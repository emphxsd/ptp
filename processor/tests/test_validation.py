"""Шаг 5: проверка значений по правилам шаблона и причины пометки «требует проверки»."""
import json

import pytest
from pydantic import ValidationError

from app.config import Settings
from app.fields.rules import RuleMatch
from app.fields.validation import review_issues
from app.pipeline import process_document
from app.schemas import Template, TemplateField
from tests.conftest import make_docx

THRESHOLD = 0.6


def field(data_type="string", required=False, **validation) -> TemplateField:
    return TemplateField(
        name="x", label="Поле", data_type=data_type, is_required=required, validation=validation or None
    )


def match(value="значение", confidence=0.9, raw="значение") -> RuleMatch:
    return RuleMatch(value=value, raw=raw, start=0 if raw else None, end=len(raw) if raw else None, confidence=confidence)


def issues(f, m, source="rule"):
    return review_issues(f, m, source, THRESHOLD)


# ---------------------------------------------------------------- общие причины
def test_confident_value_has_no_issues():
    assert issues(field(), match()) == ()


def test_missing_required_field():
    assert issues(field(required=True), None) == ("REQUIRED_MISSING",)


def test_missing_optional_field_is_not_an_issue():
    assert issues(field(), None) == ()


def test_low_confidence():
    assert issues(field(), match(confidence=0.5)) == ("LOW_CONFIDENCE",)


def test_llm_value_not_confirmed_by_text():
    assert issues(field(), match(confidence=0.4, raw=None), source="llm") == ("LOW_CONFIDENCE", "NOT_CONFIRMED")


# ---------------------------------------------------------------- правила шаблона
@pytest.mark.parametrize(
    ("value", "expected"),
    [("15000.00", ()), ("100.00", ("OUT_OF_RANGE",)), ("2000000.00", ("OUT_OF_RANGE",)), ("1000.00", ())],
    ids=["inside", "below", "above", "boundary"],
)
def test_amount_range(value, expected):
    f = field("amount", min_value="1000", max_value="1000000")

    assert issues(f, match(value)) == expected


@pytest.mark.parametrize(
    ("value", "expected"),
    [("2026-03-12", ()), ("2019-12-31", ("OUT_OF_RANGE",)), ("2031-01-01", ("OUT_OF_RANGE",))],
    ids=["inside", "before", "after"],
)
def test_date_range(value, expected):
    f = field("date", min_date="2020-01-01", max_date="2030-12-31")

    assert issues(f, match(value)) == expected


def test_max_length():
    assert issues(field(max_length=5), match("123456")) == ("TOO_LONG",)
    assert issues(field(max_length=5), match("12345")) == ()


def test_allowed_values_ignore_case_and_spaces():
    f = field(allowed_values=["Москва", "Санкт-Петербург"])

    assert issues(f, match("  москва ")) == ()
    assert issues(f, match("Казань")) == ("NOT_ALLOWED",)


def test_pattern_must_match_whole_value():
    f = field("number", pattern=r"\d{2}-[А-ЯЁ]")

    assert issues(f, match("42-П")) == ()
    assert issues(f, match("42-П-1")) == ("INVALID_FORMAT",)


def test_several_issues_are_reported_together():
    f = field("amount", required=True, max_value="10")

    assert issues(f, match("15000.00", confidence=0.4)) == ("LOW_CONFIDENCE", "OUT_OF_RANGE")


# ---------------------------------------------------------------- проверка самого шаблона
@pytest.mark.parametrize(
    ("data_type", "validation"),
    [
        ("string", {"min_value": "1"}),
        ("amount", {"min_date": "2020-01-01"}),
        ("amount", {"min_value": "10", "max_value": "1"}),
        ("date", {"min_date": "2030-01-01", "max_date": "2020-01-01"}),
        ("string", {"max_length": 0}),
        ("string", {"allowed_values": []}),
        ("string", {"pattern": "(a+"}),
        ("string", {"pattern": r"\d*"}),
        ("string", {"unknown": 1}),
    ],
    ids=["value-for-string", "date-for-amount", "min-gt-max", "dates-reversed", "zero-length", "empty-list",
         "bad-regex", "empty-match-regex", "unknown-key"],
)
def test_invalid_validation_rules_are_rejected(data_type, validation):
    with pytest.raises(ValidationError):
        TemplateField(name="x", label="Поле", data_type=data_type, validation=validation)


# ---------------------------------------------------------------- в составе конвейера
DOCX = make_docx(["Счёт № 15 от 12.03.2026", "ИНН 7707083893", "Итого: 15 000,00 руб."])


def run(fields):
    template = Template.model_validate({"fields": fields})
    return process_document(DOCX, template, Settings(ner_enabled=False, llm_enabled=False))


def test_pipeline_reports_issues_and_document_status():
    result = run(
        [
            {"name": "inn", "label": "ИНН", "data_type": "inn", "keywords": ["ИНН"]},
            {"name": "total", "label": "Сумма", "data_type": "amount", "keywords": ["Итого"], "validation": {"max_value": "10000"}},
            {"name": "kpp", "label": "КПП", "data_type": "kpp", "is_required": True, "keywords": ["КПП"]},
        ]
    )
    fields = {f.name: f for f in result.fields}

    assert fields["inn"].issues == () and fields["inn"].needs_review is False
    assert fields["total"].value == "15000.00"
    assert fields["total"].issues == ("OUT_OF_RANGE",) and fields["total"].needs_review is True
    assert fields["kpp"].issues == ("REQUIRED_MISSING",)
    assert result.needs_review is True
    assert result.review_fields == ("total", "kpp")


def test_document_without_issues_is_ready():
    result = run([{"name": "inn", "label": "ИНН", "data_type": "inn", "keywords": ["ИНН"]}])

    assert result.needs_review is False
    assert result.review_fields == ()


def test_validation_rules_parsed_from_template_json():
    template = Template.model_validate_json(
        json.dumps(
            {"fields": [{"name": "d", "label": "Дата", "data_type": "date", "validation": {"min_date": "2020-01-01"}}]}
        )
    )

    assert str(template.fields[0].validation.min_date) == "2020-01-01"
