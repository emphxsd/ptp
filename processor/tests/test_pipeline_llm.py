"""LLM в каскаде: вызывается одним запросом только для ненайденных и сомнительных полей; сбой LLM не ломает обработку."""
import json
from dataclasses import dataclass, field as dc_field

import pytest

from app.config import Settings
from app.llm.client import LlmReply
from app.llm.errors import LlmBusyError, LlmQuotaError, LlmUnavailableError
from app.pipeline import process_document
from app.schemas import Template
from tests.conftest import make_docx

DOCX = make_docx(
    [
        "Счёт на оплату № 15 от 12.03.2026",
        "Поставщик: ООО «Ромашка»",
        "ИНН 7707083893",
        "Оплатить до конца месяца безналичным переводом",
        "Итого: 15 000,00 руб.",
    ]
)


@dataclass
class FakeLlm:
    content: str = "{}"
    error: Exception | None = None
    calls: list = dc_field(default_factory=list)

    def complete(self, messages, max_tokens):
        self.calls.append(messages)
        if self.error is not None:
            raise self.error
        return LlmReply(content=self.content)


def template(*fields) -> Template:
    return Template.model_validate({"name": "Счёт", "fields": list(fields)})


INN = {"name": "inn", "label": "ИНН", "data_type": "inn", "keywords": ["ИНН"]}
TERMS = {"name": "terms", "label": "Условия оплаты", "data_type": "string"}
TOTAL = {"name": "total", "label": "Сумма", "data_type": "amount", "keywords": ["Итого"]}


def run(tpl, llm, **settings):
    return process_document(DOCX, tpl, Settings(ner_enabled=False, **settings), llm=llm)


def test_llm_not_called_when_rules_found_everything():
    llm = FakeLlm()

    result = run(template(INN, TOTAL), llm)

    assert llm.calls == []
    assert result.llm_used is False
    assert {f.source for f in result.fields} == {"rule"}


def test_llm_fills_field_rules_could_not_find():
    llm = FakeLlm(json.dumps({"terms": {"value": "до конца месяца", "quote": "Оплатить до конца месяца"}}, ensure_ascii=False))

    result = run(template(INN, TERMS), llm)
    fields = {f.name: f for f in result.fields}

    assert len(llm.calls) == 1
    user_prompt = llm.calls[0][1].content
    assert '"terms"' in user_prompt and '"inn"' not in user_prompt  # ИНН найден правилами — в LLM не отправляется
    assert result.llm_used is True
    assert fields["terms"].source == "llm"
    assert fields["terms"].value == "до конца месяца"
    assert fields["terms"].raw == "Оплатить до конца месяца"
    # свободный текст без якоря от LLM — подтверждён цитатой, но проверяется человеком
    assert fields["terms"].needs_review is True
    assert fields["inn"].source == "rule"


def test_all_pending_fields_go_in_one_request():
    llm = FakeLlm("{}")
    extra = {"name": "bank", "label": "Банк", "data_type": "string"}

    run(template(INN, TERMS, extra), llm)

    assert len(llm.calls) == 1
    user_prompt = llm.calls[0][1].content
    assert '"terms"' in user_prompt and '"bank"' in user_prompt


def test_low_confidence_rule_value_is_sent_with_candidates_and_kept_on_disagreement():
    # без якоря несколько сумм в документе → правило не уверено; LLM без подтверждения в тексте не перебивает его
    tpl = template({"name": "num", "label": "Номер счёта", "data_type": "number"}, {**TOTAL, "keywords": []})
    llm = FakeLlm(json.dumps({"total": {"value": "999", "quote": "нет такого"}}))

    result = run(tpl, llm, review_threshold=0.95)
    total = {f.name: f for f in result.fields}["total"]

    assert '"total"' in llm.calls[0][1].content
    assert total.source == "rule"
    assert "999.00" in total.alternatives


def test_agreement_with_llm_raises_confidence():
    tpl = template({**TOTAL, "keywords": []})
    llm = FakeLlm(json.dumps({"total": {"value": "15000.00", "quote": "15 000,00 руб."}}, ensure_ascii=False))

    without_llm = run(tpl, None, review_threshold=0.95)
    with_llm = run(tpl, llm, review_threshold=0.95)

    assert with_llm.fields[0].value == without_llm.fields[0].value == "15000.00"
    assert with_llm.fields[0].confidence > without_llm.fields[0].confidence
    assert with_llm.fields[0].source == "rule"


@pytest.mark.parametrize(
    ("error", "code"),
    [(LlmUnavailableError(), "LLM_UNAVAILABLE"), (LlmBusyError(), "LLM_BUSY"), (LlmQuotaError(), "LLM_QUOTA_EXCEEDED")],
    ids=["unavailable", "busy", "quota"],
)
def test_llm_failure_keeps_rule_results_and_reports_warning(error, code):
    result = run(template(INN, TERMS), FakeLlm(error=error))
    fields = {f.name: f for f in result.fields}

    assert result.llm_used is False
    assert code in result.warnings
    assert fields["inn"].value == "7707083893"
    assert fields["terms"].value is None


def test_llm_disabled_in_settings_is_not_called():
    llm = FakeLlm()

    result = run(template(TERMS), llm, llm_enabled=False)

    assert llm.calls == []
    assert result.llm_used is False


def test_too_many_pending_fields_are_capped():
    fields = [{"name": f"f{i}", "label": f"Поле {i}", "data_type": "string"} for i in range(60)]
    llm = FakeLlm("{}")

    result = run(template(*fields), llm)

    prompt = llm.calls[0][1].content
    assert '"f0"' in prompt and '"f59"' not in prompt
    assert "LLM_FIELDS_TRUNCATED" in result.warnings


def test_unverified_llm_echo_of_candidate_gets_no_agreement_bonus():
    tpl = template({**TOTAL, "keywords": []})
    llm = FakeLlm(json.dumps({"total": {"value": "15000.00", "quote": "выдумано"}}, ensure_ascii=False))

    without_llm = run(tpl, None, review_threshold=0.95)
    with_llm = run(tpl, llm, review_threshold=0.95)

    assert with_llm.fields[0].confidence == without_llm.fields[0].confidence


def test_unverified_llm_value_does_not_replace_grounded_value():
    tpl = template({**TOTAL, "keywords": []})
    llm = FakeLlm(json.dumps({"total": {"value": "777.00", "quote": "нет в документе"}}))

    total = run(tpl, llm, review_threshold=0.95).fields[0]

    assert total.value == "15000.00"
    assert total.source == "rule"
    assert "777.00" in total.alternatives
    assert total.value not in total.alternatives


def test_unexpected_llm_exception_degrades_to_warning():
    result = run(template(INN, TERMS), FakeLlm(error=UnicodeEncodeError("ascii", "ключ", 0, 1, "bad")))

    assert result.llm_used is False
    assert "LLM_UNAVAILABLE" in result.warnings
    assert {f.name: f for f in result.fields}["inn"].value == "7707083893"
