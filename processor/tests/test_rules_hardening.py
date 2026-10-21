"""Граничные случаи этапа правил (по итогам ревью): ложные срабатывания, неоднозначность,
производительность. Главный принцип: неверное значение не должно получать уверенность выше порога."""
import time

import pytest
from pydantic import ValidationError

from app.config import Settings
from app.extraction.file_types import DocumentFormat
from app.extraction.text import ExtractedText
from app.fields.checksums import is_valid_inn
from app.fields.rules import find_by_rules
from app.fields.user_regex import UserRegexError, compile_user_regex
from app.pipeline import find_fields
from app.schemas import Template, TemplateField

THRESHOLD = Settings().review_threshold


def field(data_type="string", keywords=(), **extra) -> TemplateField:
    return TemplateField(name="f", label="f", data_type=data_type, keywords=keywords, **extra)


def value(data_type, text, keywords=(), **extra):
    match = find_by_rules(field(data_type, keywords, **extra), text)
    return None if match is None else match.value


def confident(match) -> bool:
    return match is not None and match.confidence >= THRESHOLD


# ---------------------------------------------------------------- окно после якоря
def test_value_of_next_field_is_not_attributed_to_keyword():
    match = find_by_rules(field("date", ["Дата рождения"]), "Дата рождения: не указана\nДоговор от 12.03.2026")

    assert not confident(match)


def test_next_line_is_used_only_when_keyword_line_is_empty():
    match = find_by_rules(field("amount", ["Сумма"]), "Сумма: не указана\nКод 12345")

    assert not confident(match)


def test_string_tail_skips_next_line_that_is_another_label():
    assert find_by_rules(field("string", ["Основание"]), "Основание:\nАдрес: Москва") is None


def test_string_tail_keeps_leading_dot():
    assert value("string", "Навыки: .NET, C#", ["Навыки"]) == ".NET, C#"


# ---------------------------------------------------------------- неоднозначность
def test_amount_prefers_value_with_kopecks_near_keyword():
    match = find_by_rules(field("amount", ["Итого"]), "Итого: 3 позиции на сумму 15 000,00 руб.")

    assert match.value == "15000.00"


def test_several_amounts_near_keyword_are_ambiguous():
    match = find_by_rules(field("amount", ["Итого"]), "Итого: 15 000,00 2 500,00 17 500,00")

    assert not confident(match)
    assert set(match.alternatives) == {"15000.00", "2500.00", "17500.00"}


def test_keyword_occurring_twice_with_different_values_is_ambiguous():
    text = "Продавец: ИНН 7707083893\nПокупатель: ИНН 500100732259"
    match = find_by_rules(field("inn", ["ИНН"]), text)

    assert not confident(match)
    assert set(match.alternatives) == {"7707083893", "500100732259"}


def test_same_value_repeated_near_keyword_stays_confident():
    text = "ИНН 7707083893\n...\nИНН 7707083893"

    assert find_by_rules(field("inn", ["ИНН"]), text).confidence >= 0.9


def test_value_found_only_without_keyword_is_not_confident():
    # якорь задан, но не найден: единственная сумма в документе может оказаться не той
    match = find_by_rules(field("amount", ["Сумма НДС"]), "Итого: 15 000,00 руб.")

    assert match.value == "15000.00"
    assert not confident(match)


def test_kpp_without_keyword_is_not_confident():
    # 9 цифр — это может быть и БИК, и ОКПО
    assert not confident(find_by_rules(field("kpp"), "БИК 044525225"))


# ---------------------------------------------------------------- даты
@pytest.mark.parametrize(
    "text",
    ["п. 5.2.15 договора", "код 12-11-56", "112 марта 2026", "12 марта 20265", "12 марта\n2026", "версия 1.2.2026.5"],
)
def test_not_a_date(text):
    assert value("date", text) is None


def test_date_in_quotes_with_year_suffix():
    assert value("date", 'Срок: "20" марта 2026 г.', ["Срок"]) == "2026-03-20"


# ---------------------------------------------------------------- суммы
@pytest.mark.parametrize("text", ["Дата 12/03/2026", "Дата 2026-03-12", "Счёт № 15-А", "НДС 20%", "ставка 0,1 %"])
def test_not_an_amount(text):
    assert value("amount", text, ["Дата", "Счёт", "НДС", "ставка"]) is None


def test_amount_with_dash_separated_kopecks():
    assert value("amount", "Итого: 15 000-50 руб.", ["Итого"]) == "15000.50"


# ---------------------------------------------------------------- ФИО и организации
@pytest.mark.parametrize(
    ("text", "expected"),
    [
        ("Ф.И.О. Иванов И.И.", "Иванов И. И."),
        ("Ответственный: ИВАНОВ И.И.", "Иванов И. И."),
        ("подпись И.О. Петров", "Петров И. О."),
    ],
)
def test_person_with_initials(text, expected):
    assert value("person", text) == expected


@pytest.mark.parametrize(
    ("text", "expected"),
    [
        ('Поставщик: ООО "Группа "Ромашка""', 'ООО "Группа "Ромашка""'),
        ('Поставщик: ООО "Ромашка" (далее - "Поставщик")', 'ООО "Ромашка"'),
        ("Поставщик: Индивидуальный предприниматель Иванов И.И.", "ИП Иванов И. И."),
        ("Поставщик: ИП Иванов Иван Иванович", "ИП Иванов Иван Иванович"),
        ('Поставщик: ТСЖ "Берёзка"', 'ТСЖ "Берёзка"'),
    ],
)
def test_organization_forms(text, expected):
    match = find_by_rules(field("organization", ["Поставщик"]), text)

    assert match.value == expected
    assert match.raw in text


# ---------------------------------------------------------------- якоря
def test_keyword_ending_with_punctuation_matches_without_space():
    assert find_by_rules(field("amount", ["Итого:"]), "Итого:15 000,00").confidence >= 0.9
    assert find_by_rules(field("phone", ["тел."]), "тел.8(495)1234567").confidence >= 0.9


def test_keyword_with_star_matches_word_forms():
    text = 'Договор заключён с поставщиком ООО "Ромашка"'

    assert find_by_rules(field("organization", ["поставщик*"]), text).confidence >= 0.9


@pytest.mark.parametrize("bad_keyword", ["а", "::", " ", "*"])
def test_too_short_or_meaningless_keywords_are_rejected(bad_keyword):
    with pytest.raises(ValidationError):
        field("string", [bad_keyword])


# ---------------------------------------------------------------- номер, ИНН, позиции
@pytest.mark.parametrize(
    ("text", "expected"),
    [("Счет № 2024.05", "2024.05"), ("Счет N 5", "5"), ("Счет No. 17-Б", "17-Б")],
)
def test_document_number_forms(text, expected):
    assert value("number", text, ["Счет"]) == expected


@pytest.mark.parametrize("inn", ["0000000000", "000000000000"])
def test_inn_of_zeros_is_invalid(inn):
    assert is_valid_inn(inn) is False


def test_match_positions_point_to_raw_text():
    text = "Счёт № 1 от 01.01.2026\nИтого: 15 000,00 руб.\nИтого: 15 000,00 руб."
    match = find_by_rules(field("amount", ["Итого"]), text)

    assert text[match.start:match.end] == match.raw


def test_positions_of_global_match():
    text = "текст без якорей, почта Info@Romashka.ru"
    match = find_by_rules(field("email"), text)

    assert text[match.start:match.end] == "Info@Romashka.ru"


# ---------------------------------------------------------------- RE2
def test_word_boundary_only_pattern_is_rejected():
    with pytest.raises(UserRegexError):
        compile_user_regex(r"\b")


def test_user_regex_with_many_matches_is_fast():
    text = "1" * 1_000_000
    started = time.perf_counter()

    find_by_rules(field("string", regex_pattern=r"\d"), text)

    assert time.perf_counter() - started < 2.0


# ---------------------------------------------------------------- производительность
def test_frequent_keyword_on_huge_text_is_fast():
    text = "аб " * 700_000  # ~2 млн символов, якорь встречается сотни тысяч раз
    started = time.perf_counter()

    find_by_rules(field("organization", ["аб"]), text)

    assert time.perf_counter() - started < 2.0


def test_many_fields_with_absent_keywords_on_huge_text_is_fast():
    text = "слово " * 330_000  # ~2 млн символов
    template = Template.model_validate(
        {
            "fields": [
                {"name": f"f{i}", "label": "x", "data_type": "amount", "keywords": [f"якорь{i}_{k}" for k in range(20)]}
                for i in range(100)
            ]
        }
    )
    extracted = ExtractedText(format=DocumentFormat.DOCX, pages_text=(text,), pages=None, pages_without_text=0)
    started = time.perf_counter()

    find_fields(extracted, template, Settings())

    assert time.perf_counter() - started < 5.0
