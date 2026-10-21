"""Логика NER-этапа на подставном распознавателе: какие сущности берутся для поля и с какой уверенностью."""
from dataclasses import dataclass, field as dc_field

from app.config import Settings
from app.extraction.file_types import DocumentFormat
from app.extraction.text import ExtractedText
from app.fields.ner import Entity
from app.fields.ner_stage import NerStage
from app.fields.rules import DocumentIndex
from app.pipeline import find_fields
from app.schemas import Template, TemplateField

THRESHOLD = Settings().review_threshold


@dataclass
class FakeRecognizer:
    """Находит заданные фрагменты (label, surface, normal) в переданном тексте."""

    known: list[tuple[str, str, str]] = dc_field(default_factory=list)
    known_names: list[tuple[str, str]] = dc_field(default_factory=list)
    calls: int = 0

    def entities(self, text: str) -> list[Entity]:
        self.calls += 1
        return self._find(text, self.known)

    def names(self, text: str) -> list[Entity]:
        return self._find(text, [("PER", s, n) for s, n in self.known_names])

    @staticmethod
    def _find(text, known):
        found = []
        for label, surface, normal in known:
            start = text.find(surface)
            while start >= 0:
                found.append(Entity(label=label, text=surface, start=start, end=start + len(surface), normal=normal))
                start = text.find(surface, start + 1)
        return sorted(found, key=lambda e: e.start)


def field(data_type="person", keywords=(), **extra) -> TemplateField:
    return TemplateField(name="f", label="f", data_type=data_type, keywords=keywords, **extra)


def stage(text, recognizer) -> NerStage:
    return NerStage(recognizer, DocumentIndex(text))


CONTRACT = (
    "Договор поставки № 7\n"
    "Покупатель: Ивановой Марией Сергеевной, действующей на основании доверенности\n"
    "Поставщик: ПАО Сбербанк в лице директора Петрова Петра Петровича\n"
    "Адрес поставки: г. Москва"
)
RECOGNIZER = [
    ("PER", "Ивановой Марией Сергеевной", "Иванова Мария Сергеевна"),
    ("ORG", "ПАО Сбербанк", "ПАО Сбербанк"),
    ("PER", "Петрова Петра Петровича", "Петров Петр Петрович"),
    ("LOC", "Москва", "Москва"),
]


def test_person_near_keyword_is_found_in_nominative():
    match = stage(CONTRACT, FakeRecognizer(RECOGNIZER)).find(field("person", ["Покупатель"]))

    assert match.value == "Иванова Мария Сергеевна"
    assert match.raw == "Ивановой Марией Сергеевной"
    assert CONTRACT[match.start:match.end] == match.raw
    assert match.confidence >= 0.8


def test_person_after_multiword_keyword():
    match = stage(CONTRACT, FakeRecognizer(RECOGNIZER)).find(field("person", ["в лице директора"]))

    assert match.value == "Петров Петр Петрович"


def test_organization_near_keyword():
    match = stage(CONTRACT, FakeRecognizer(RECOGNIZER)).find(field("organization", ["Поставщик"]))

    assert match.value == "ПАО Сбербанк"
    assert match.confidence >= 0.8


def test_location_by_explicit_ner_label():
    match = stage(CONTRACT, FakeRecognizer(RECOGNIZER)).find(field("string", ["Адрес поставки"], ner_label="LOC"))

    assert match.value == "Москва"


def test_two_people_near_keyword_are_ambiguous():
    text = "Подписи: Иванова Мария Сергеевна, Петров Петр Петрович"
    recognizer = FakeRecognizer(
        [("PER", "Иванова Мария Сергеевна", "Иванова Мария Сергеевна"), ("PER", "Петров Петр Петрович", "Петров Петр Петрович")]
    )

    match = stage(text, recognizer).find(field("person", ["Подписи"]))

    assert match.confidence < THRESHOLD
    assert set(match.alternatives) == {"Иванова Мария Сергеевна", "Петров Петр Петрович"}


def test_single_person_in_document_without_keywords():
    text = "Резюме\nОпыт работы 5 лет\nКонтакт: Смирнов Алексей Викторович"
    recognizer = FakeRecognizer([("PER", "Смирнов Алексей Викторович", "Смирнов Алексей Викторович")])

    match = stage(text, recognizer).find(field("person"))

    assert match.value == "Смирнов Алексей Викторович"
    assert match.confidence >= THRESHOLD


def test_keyword_given_but_absent_caps_confidence():
    match = stage(CONTRACT, FakeRecognizer(RECOGNIZER)).find(field("organization", ["Грузополучатель"]))

    assert match.value == "ПАО Сбербанк"
    assert match.confidence < THRESHOLD


def test_single_word_person_is_not_confident():
    recognizer = FakeRecognizer([("PER", "Иванов", "Иванов")])

    match = stage("Ответственный: Иванов", recognizer).find(field("person", ["Ответственный"]))

    assert match.confidence < THRESHOLD


def test_names_fallback_when_ner_finds_nothing():
    text = "Резюме\nСмирнов Алексей Викторович\nТелефон: +7 912 345-67-89"
    recognizer = FakeRecognizer(known_names=[("Смирнов Алексей Викторович", "Смирнов Алексей Викторович")])

    match = stage(text, recognizer).find(field("person"))

    assert match.value == "Смирнов Алексей Викторович"
    # словарный поиск без якоря — самый слабый источник: значение отдаётся, но на проверку
    assert match.confidence < THRESHOLD


def test_nothing_found_returns_none():
    assert stage("Только цифры 12345", FakeRecognizer()).find(field("person")) is None


def test_not_applicable_field_returns_none():
    assert stage(CONTRACT, FakeRecognizer(RECOGNIZER)).find(field("inn", ["ИНН"])) is None


# ---------------------------------------------------------------- встраивание в конвейер
def run_pipeline(text, template_fields, recognizer, **settings):
    extracted = ExtractedText(format=DocumentFormat.DOCX, pages_text=(text,), pages=None, pages_without_text=0)
    template = Template.model_validate({"fields": template_fields})
    return {f.name: f for f in find_fields(extracted, template, Settings(**settings), recognizer)}


def test_pipeline_uses_ner_when_rules_find_nothing():
    fields = run_pipeline(
        CONTRACT,
        [{"name": "buyer", "label": "Покупатель", "data_type": "person", "keywords": ["Покупатель"]}],
        FakeRecognizer(RECOGNIZER),
    )

    assert fields["buyer"].value == "Иванова Мария Сергеевна"
    assert fields["buyer"].source == "ner"
    assert fields["buyer"].needs_review is False


def test_pipeline_keeps_confident_rule_result_and_skips_ner():
    recognizer = FakeRecognizer(RECOGNIZER)
    fields = run_pipeline(
        "Директор Петров П. П.",
        [{"name": "boss", "label": "Директор", "data_type": "person", "keywords": ["Директор"]}],
        recognizer,
    )

    assert fields["boss"].source == "rule"
    assert fields["boss"].value == "Петров П. П."
    assert recognizer.calls == 0


def test_pipeline_does_not_call_ner_for_other_types():
    recognizer = FakeRecognizer(RECOGNIZER)
    run_pipeline("ИНН 7707083893", [{"name": "inn", "label": "ИНН", "data_type": "inn"}], recognizer)

    assert recognizer.calls == 0


def test_pipeline_without_recognizer_uses_rules_only():
    fields = run_pipeline(
        CONTRACT,
        [{"name": "buyer", "label": "Покупатель", "data_type": "person", "keywords": ["Покупатель"]}],
        None,
    )

    assert fields["buyer"].value is None
