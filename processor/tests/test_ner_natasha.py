"""Обёртка над Natasha на реальных моделях: склейка ФИО, кавычки, стоп-слова, запасной поиск имён."""
import pytest

from app.config import Settings
from app.fields.ner import get_recognizer
from app.pipeline import process_document
from app.schemas import Template
from tests.conftest import make_client, make_docx, post_extract


@pytest.fixture(scope="module")
def recognizer():
    return get_recognizer()


def labels(entities, label):
    return [e.normal for e in entities if e.label == label]


def test_split_person_spans_are_merged_and_normalized(recognizer):
    text = "Договор заключён между Ивановой Марией Сергеевной, действующей на основании доверенности"

    assert labels(recognizer.entities(text), "PER") == ["Иванова Мария Сергеевна"]


def test_person_in_genitive_is_normalized(recognizer):
    text = "ПАО Сбербанк в лице директора Петрова Петра Петровича, действующего на основании устава"

    assert "Петров Пётр Петрович" in labels(recognizer.entities(text), "PER")


def test_truncated_quote_in_organization_is_fixed(recognizer):
    text = 'Опыт работы: ООО "Яндекс", АО "Тинькофф Банк", 2020-2024'
    orgs = labels(recognizer.entities(text), "ORG")

    assert 'АО "Тинькофф Банк"' in orgs
    for org in orgs:
        assert org.count('"') % 2 == 0


def test_requisite_abbreviations_are_not_organizations(recognizer):
    text = "ИНН 7707083893, КПП 773601001, ОГРН 1027700132195, БИК 044525225, НДС 20%"

    assert labels(recognizer.entities(text), "ORG") == []


def test_entity_positions_point_to_text(recognizer):
    text = "Покупатель: Иванова Мария Сергеевна, г. Москва"
    for entity in recognizer.entities(text):
        assert text[entity.start:entity.end] == entity.text


def test_names_fallback_finds_standalone_full_name(recognizer):
    names = recognizer.names("Резюме\nСмирнов Алексей Викторович\nТелефон: +7 912 345-67-89")

    assert [n.normal for n in names] == ["Смирнов Алексей Викторович"]


def test_names_fallback_normalizes_case(recognizer):
    names = recognizer.names("между Ивановой Марией Сергеевной и")

    assert [n.normal for n in names] == ["Иванова Мария Сергеевна"]


def test_names_fallback_ignores_single_words(recognizer):
    assert recognizer.names("Итого к оплате: пятнадцать тысяч рублей, Москва Сити") == []


# ---------------------------------------------------------------- сквозной сценарий
def test_resume_candidate_name_end_to_end():
    docx = make_docx(["Резюме", "Смирнов Алексей Викторович", "Телефон: +7 912 345-67-89", "Опыт работы: 5 лет"])
    template = Template.model_validate({"fields": [{"name": "candidate", "label": "ФИО", "data_type": "person"}]})

    (candidate,) = process_document(docx, template, Settings()).fields

    assert candidate.value == "Смирнов Алексей Викторович"
    assert candidate.source == "ner"


def test_contract_parties_through_api():
    docx = make_docx(
        [
            "Договор поставки № 7",
            "ПАО Сбербанк в лице директора Петрова Петра Петровича, действующего на основании устава, "
            "и Иванова Мария Сергеевна заключили настоящий договор.",
        ]
    )
    template = (
        '{"fields": ['
        '{"name": "signer", "label": "Подписант", "data_type": "person", "keywords": ["в лице директора"]},'
        '{"name": "bank", "label": "Банк", "data_type": "organization", "keywords": ["Договор поставки"]}'
        "]}"
    )
    with make_client() as client:
        fields = {f["name"]: f for f in post_extract(client, docx, template, filename="d.docx").json()["data"]["fields"]}

    assert fields["signer"]["value"] == "Петров Пётр Петрович"
    assert fields["signer"]["source"] == "ner"


def test_ner_can_be_disabled():
    docx = make_docx(["Резюме", "Смирнов Алексей Викторович"])
    template = Template.model_validate({"fields": [{"name": "candidate", "label": "ФИО", "data_type": "person"}]})

    (candidate,) = process_document(docx, template, Settings(ner_enabled=False)).fields

    assert candidate.value is None


# ---------------------------------------------------------------- найдено оценкой качества (шаг 7)
@pytest.mark.parametrize(
    ("genitive", "nominative"),
    [
        ("Соколовой Елены Михайловны", "Соколова Елена Михайловна"),
        ("Кузнецовой Татьяны Алексеевны", "Кузнецова Татьяна Алексеевна"),
        ("Захаровой Натальи Николаевны", "Захарова Наталья Николаевна"),
    ],
)
def test_feminine_genitive_is_not_taken_for_plural_nominative(recognizer, genitive, nominative):
    # «Елены», «Михайловны» — и родительный падеж ед. числа, и именительный мн. числа
    assert recognizer.normalize_person(genitive) == nominative


def test_person_named_after_is_not_a_person(recognizer):
    text = "Образование: МГУ им. М. В. Ломоносова, Уральский федеральный университет имени Б. Н. Ельцина"

    assert labels(recognizer.entities(text), "PER") == []
