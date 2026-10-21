"""Граничные случаи NER по итогам ревью: переводы строк, женские фамилии, кавычки,
двойные фамилии, шумовые сущности, бюджет и загрузка моделей."""
import threading
import time

import pytest

from app.config import Settings
from app.extraction.file_types import DocumentFormat
from app.extraction.text import ExtractedText
from app.fields import ner as ner_module
from app.fields.ner import get_recognizer
from app.fields.ner_stage import NerStage
from app.fields.rules import DocumentIndex
from app.pipeline import find_fields
from app.schemas import Template, TemplateField

THRESHOLD = Settings().review_threshold


@pytest.fixture(scope="module")
def recognizer():
    return get_recognizer()


def people(recognizer, text):
    return [e.normal for e in recognizer.entities(text) if e.label == "PER"]


def orgs(recognizer, text):
    return [e.normal for e in recognizer.entities(text) if e.label == "ORG"]


# ---------------------------------------------------------------- переводы строк
@pytest.mark.parametrize(
    ("text", "expected"),
    [
        ("Иванова Мария Сергеевна\nМосква\nПрограммист", ["Иванова Мария Сергеевна"]),
        ("Иванов Иван Иванович\nПетров Пётр Петрович", ["Иванов Иван Иванович", "Петров Пётр Петрович"]),
    ],
)
def test_entities_never_cross_line_breaks(recognizer, text, expected):
    found = people(recognizer, text)

    assert found == expected
    assert all("\n" not in e.text for e in recognizer.entities(text))


def test_resume_header_name_is_not_glued_to_next_lines():
    text = "Иванова Мария Сергеевна\nМосква\nПрограммист\nОпыт работы 5 лет"
    match = NerStage(get_recognizer(), DocumentIndex(text)).find(field("person"))

    assert match.value == "Иванова Мария Сергеевна"


# ---------------------------------------------------------------- нормализация ФИО
@pytest.mark.parametrize(
    ("text", "expected"),
    [
        ("Кандидат: Смирнова Анна Викторовна, опыт 5 лет", "Смирнова Анна Викторовна"),
        ("Исполнитель Козлова Ольга Игоревна подписала акт", "Козлова Ольга Игоревна"),
        ("договор с Ивановой Марией Сергеевной заключён", "Иванова Мария Сергеевна"),
        ("в лице директора Петрова Петра Петровича, действующего", "Петров Пётр Петрович"),
        ("выдана Гусевой Елене Ивановне для получения", "Гусева Елена Ивановна"),
    ],
)
def test_person_is_normalized_with_correct_gender(recognizer, text, expected):
    assert expected in people(recognizer, text)


def test_names_fallback_keeps_feminine_surname(recognizer):
    assert [n.normal for n in recognizer.names("Резюме\nИванова Мария Сергеевна\n")] == ["Иванова Мария Сергеевна"]


def test_names_fallback_keeps_word_order(recognizer):
    assert [n.normal for n in recognizer.names("Резюме\nМария Ивановна Петрова\n")] == ["Мария Ивановна Петрова"]


def test_initials_keep_trailing_dot(recognizer):
    for entity in recognizer.entities("Директор Иванов И.И. Главный бухгалтер не назначен"):
        if entity.label == "PER":
            assert entity.text.endswith(".")


def test_double_surname_is_kept(recognizer):
    assert "Салтыков-Щедрин Михаил Евграфович" in people(recognizer, "Покупатель: Салтыков-Щедрин Михаил Евграфович")


# ---------------------------------------------------------------- организации
def test_organization_name_is_not_lemmatized(recognizer):
    text = 'Банк: ПАО "Сбербанк России", БИК 044525225'

    assert 'ПАО "Сбербанк России"' in orgs(recognizer, text)


def test_quote_extension_does_not_overlap_next_organization(recognizer):
    text = 'Стороны: ООО "Альфа" и ООО "Бета" заключили договор'
    entities = [e for e in recognizer.entities(text) if e.label == "ORG"]

    for left, right in zip(entities, entities[1:]):
        assert left.end <= right.start


@pytest.mark.parametrize(
    "text",
    ["ИНН 7707083893 КПП 773601001", "ДОГОВОР ПОСТАВКИ № 7", "СЧЁТ-ФАКТУРА № 15 от 12.03.2026", "Продавец: ИП"],
)
def test_noise_is_not_an_organization(recognizer, text):
    for org in orgs(recognizer, text):
        assert not org.isupper() or '"' in org, org
        assert org not in {"ИП", "ООО", "АО"}
        assert not any(ch.isdigit() for ch in org[:8]), org


# ---------------------------------------------------------------- уверенность
def field(data_type="person", keywords=(), **extra) -> TemplateField:
    return TemplateField(name="f", label="f", data_type=data_type, keywords=keywords, **extra)


def test_first_name_and_patronymic_without_surname_is_not_confident():
    text = "Покупатель: Михаил Евграфович"
    match = NerStage(get_recognizer(), DocumentIndex(text)).find(field("person", ["Покупатель"]))

    assert match is None or match.confidence < THRESHOLD


def test_names_fallback_alone_is_not_confident():
    text = "Резюме\nСмирнов Алексей Викторович\n"
    stage = NerStage(_NoNer(get_recognizer()), DocumentIndex(text))

    match = stage.find(field("person"))

    assert match.value == "Смирнов Алексей Викторович"
    assert match.confidence < THRESHOLD


class _NoNer:
    """Нейросеть «ничего не нашла» — проверяем словарный запасной вариант отдельно."""

    def __init__(self, real):
        self._real = real

    def entities(self, text):
        return []

    def names(self, text):
        return self._real.names(text)


# ---------------------------------------------------------------- каскад
def run_pipeline(text, template_fields):
    extracted = ExtractedText(format=DocumentFormat.DOCX, pages_text=(text,), pages=None, pages_without_text=0)
    template = Template.model_validate({"fields": template_fields})
    return {f.name: f for f in find_fields(extracted, template, Settings(), get_recognizer())}


def test_explicit_ner_label_on_string_field_uses_ner():
    fields = run_pipeline(
        "Адрес поставки: г. Москва, ул. Тверская, д. 1",
        [{"name": "city", "label": "Город", "data_type": "string", "keywords": ["Адрес поставки"], "ner_label": "LOC"}],
    )

    assert fields["city"].value == "Москва"
    assert fields["city"].source == "ner"


def test_rule_value_is_kept_as_alternative_when_ner_wins():
    text = "Покупатель: ИП Козлов К. К. и Иванова Мария Сергеевна\nДиректор: Иванова Мария Сергеевна"
    fields = run_pipeline(text, [{"name": "boss", "label": "Директор", "data_type": "person", "keywords": ["Директор"]}])

    assert fields["boss"].value == "Иванова Мария Сергеевна"


# ---------------------------------------------------------------- бюджет и загрузка
def test_ner_budget_limits_time_on_many_anchors():
    line = "Подписант: Иванова Мария Сергеевна, действующая на основании доверенности\n"
    text = line * 400
    template_fields = [
        {"name": f"p{i}", "label": "x", "data_type": "person", "keywords": [f"Подписант{i}", "Подписант"]}
        for i in range(30)
    ]
    started = time.perf_counter()

    run_pipeline(text, template_fields)

    assert time.perf_counter() - started < 10.0


def test_recognizer_is_built_once_under_concurrency(monkeypatch):
    built = []

    class Dummy:
        def __init__(self):
            built.append(1)
            time.sleep(0.05)

    monkeypatch.setattr(ner_module, "NatashaRecognizer", Dummy)
    monkeypatch.setattr(ner_module, "_recognizer", None)
    threads = [threading.Thread(target=ner_module.get_recognizer) for _ in range(8)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    assert len(built) == 1


# ---------------------------------------------------------------- аудит безопасности
def test_word_start_is_bounded_on_huge_words():
    from app.fields.ner_stage import _word_start

    text = "а" * 2_000_000
    started = time.perf_counter()
    _word_start(text, 1_000_000)
    assert time.perf_counter() - started < 0.01


def test_names_input_is_clamped(recognizer):
    started = time.perf_counter()
    recognizer.names("а,а," * 10_000)
    assert time.perf_counter() - started < 3.0


def test_ner_line_budget_is_enforced():
    lines_seen = []

    class Counting:
        def entities(self, text):
            lines_seen.append(text.count("\n") + 1)
            return []

        def names(self, text):
            return []

    text = "\n".join(f"Подписант:\nx{i}" for i in range(3000))
    stage = NerStage(Counting(), DocumentIndex(text))
    stage.find(field("person", ["Подписант"]))

    assert sum(lines_seen) <= 500


def test_health_reports_ner_status():
    from tests.conftest import make_client

    with make_client(ner_enabled=False) as client:
        assert client.get("/health").json()["data"] == {"status": "ok", "ner": "disabled"}


def test_extract_returns_503_when_overloaded(invoice_pdf, template_json):
    import asyncio

    from tests.conftest import make_client, post_extract

    with make_client(queue_wait_seconds=0.1) as client:
        client.app.state.extract_slots = asyncio.Semaphore(0)
        response = post_extract(client, invoice_pdf, template_json)

    assert response.status_code == 503
    assert response.json()["error"]["code"] == "SERVICE_BUSY"
    assert response.headers["retry-after"] == "5"
