"""Поиск значений правилами: шаблон по типу поля + слова-якоря."""
import pytest

from app.fields.rules import find_by_rules
from app.schemas import TemplateField

# текст в нормализованном виде (так его отдаёт этап извлечения текста)
INVOICE = """Счёт на оплату № 15-А от 12.03.2026
Поставщик: ООО "Ромашка"
ИНН/КПП 7707083893/773601001
БИК 044525225
Р/с 40702810400000012345
Покупатель: ИП Иванов И. И., ИНН 500100732259
Телефон: +7 (912) 345-67-89, e-mail: info@romashka.ru
Срок оплаты: "20" марта 2026 г.
Итого: 15 000,00 руб.
В том числе НДС 20%: 2 500,00 руб.
Директор Петров П. П."""


def field(name="f", data_type="string", keywords=(), **extra) -> TemplateField:
    return TemplateField(name=name, label=name, data_type=data_type, keywords=keywords, **extra)


@pytest.mark.parametrize(
    ("data_type", "keywords", "expected"),
    [
        ("inn", ["ИНН/КПП"], "7707083893"),
        ("inn", ["Покупатель"], "500100732259"),
        ("kpp", ["КПП"], "773601001"),
        ("bik", ["БИК"], "044525225"),
        ("date", ["Счёт на оплату"], "2026-03-12"),
        ("date", ["Срок оплаты"], "2026-03-20"),
        ("amount", ["Итого"], "15000.00"),
        ("amount", ["НДС"], "2500.00"),
        ("number", ["Счёт на оплату"], "15-А"),
        ("phone", ["Телефон"], "+79123456789"),
        ("email", ["e-mail"], "info@romashka.ru"),
        ("organization", ["Поставщик"], 'ООО "Ромашка"'),
        ("organization", ["Покупатель"], "ИП Иванов И. И."),
        ("person", ["Директор"], "Петров П. П."),
        ("string", ["Поставщик"], 'ООО "Ромашка"'),
    ],
)
def test_value_is_found_near_keyword(data_type, keywords, expected):
    match = find_by_rules(field(data_type=data_type, keywords=keywords), INVOICE)

    assert match is not None
    assert match.value == expected
    assert match.confidence >= 0.9 or (data_type == "string" and match.confidence >= 0.6)
    assert match.raw in INVOICE


def test_keyword_search_is_case_insensitive():
    assert find_by_rules(field(data_type="inn", keywords=["инн"]), "ИНН 7707083893").value == "7707083893"


def test_first_matching_keyword_wins():
    match = find_by_rules(field(data_type="inn", keywords=["Нет такого", "Покупатель"]), INVOICE)

    assert match.value == "500100732259"


@pytest.mark.parametrize(
    ("data_type", "expected"),
    [("bik", "044525225"), ("phone", "+79123456789"), ("email", "info@romashka.ru")],
)
def test_unique_value_is_found_without_keyword(data_type, expected):
    match = find_by_rules(field(data_type=data_type), INVOICE)

    assert match.value == expected
    assert 0.6 <= match.confidence < 0.9


def test_ambiguous_value_without_keyword_has_low_confidence():
    match = find_by_rules(field(data_type="amount"), INVOICE)

    assert match is not None
    assert match.confidence < 0.6


def test_two_different_inns_without_keyword_are_ambiguous():
    match = find_by_rules(field(data_type="inn"), INVOICE)

    assert match.confidence < 0.6


def test_inn_with_wrong_checksum_is_ignored():
    assert find_by_rules(field(data_type="inn", keywords=["ИНН"]), "ИНН 7707083894") is None


def test_inn_is_not_taken_from_inside_a_longer_number():
    assert find_by_rules(field(data_type="inn"), "Р/с 40702810400000012345") is None


def test_amount_does_not_match_part_of_a_date():
    assert find_by_rules(field(data_type="amount", keywords=["Дата"]), "Дата 12.03.2026") is None


def test_keyword_must_start_a_word():
    # «ИНН» не должно находиться внутри «ИННОВАЦИИ» — иначе берётся чужое значение
    assert find_by_rules(field(data_type="inn", keywords=["ИНН"]), "ИННОВАЦИИ 7707083893") .confidence < 0.9


def test_value_on_next_line_after_keyword_is_found():
    assert find_by_rules(field(data_type="inn", keywords=["ИНН"]), "ИНН:\n7707083893").value == "7707083893"


def test_value_far_from_keyword_is_not_attributed_to_it():
    text = "ИНН\nстрока 2\nстрока 3\n7707083893"
    match = find_by_rules(field(data_type="inn", keywords=["ИНН"]), text)

    # найдено, но уже как единственное значение в документе, а не рядом с якорем
    assert match.value == "7707083893"
    assert match.confidence < 0.9


def test_string_field_without_keyword_is_not_guessed():
    assert find_by_rules(field(data_type="string"), INVOICE) is None


def test_string_field_takes_next_line_when_keyword_line_is_empty():
    match = find_by_rules(field(data_type="string", keywords=["Назначение платежа"]), "Назначение платежа:\nОплата по счёту 15")

    assert match.value == "Оплата по счёту 15"


def test_user_regex_with_group_is_used():
    match = find_by_rules(field(regex_pattern=r"Р/с\s*(\d{20})"), INVOICE)

    assert match.value == "40702810400000012345"
    assert match.raw == "40702810400000012345"


def test_user_regex_without_group_returns_whole_match():
    match = find_by_rules(field(regex_pattern=r"\d{20}"), INVOICE)

    assert match.value == "40702810400000012345"


def test_user_regex_result_is_normalized_by_type():
    match = find_by_rules(field(data_type="date", regex_pattern=r"оплаты: (.+ г\.)"), INVOICE)

    assert match.value == "2026-03-20"


def test_nothing_found_returns_none():
    assert find_by_rules(field(data_type="email"), "без почты") is None


# ---------------------------------------------------------------- найдено оценкой качества (шаг 7)
@pytest.mark.parametrize(
    "text",
    [
        "Дата рождения: 12 марта 1970 года",
        'Договор от "05" июня 2025 г.',
        "Телефон: +7 994 721 12 34",
        "Тел. 8 (912) 345-67-89",
        "E-mail: novikov644@gmail.com",
        "ИНН 7707083893",
    ],
    ids=["text-date-year", "quoted-date-year", "phone-spaces", "phone-brackets", "email", "inn"],
)
def test_amount_is_not_taken_from_dates_or_phones(text):
    assert find_by_rules(field("total", "amount"), text) is None


def test_person_after_named_after_is_skipped():
    text = "Иванов И. И.\nОбразование: МГУ им. М. В. Ломоносова"

    match = find_by_rules(field("who", "person"), text)

    assert match is not None and match.value == "Иванов И. И."
    assert match.alternatives == ()


def test_string_tail_mixed_with_date_goes_to_review():
    # «г. Казань «07» января 2025 г.»: остаток строки после якоря содержит ещё и дату — значение сомнительно
    match = find_by_rules(field("city", "string", ("г.",)), 'г. Казань "07" января 2025 г.')

    assert match is not None
    assert match.confidence < 0.6


def test_plain_string_tail_keeps_confidence():
    match = find_by_rules(field("city", "string", ("Город",)), "Город: Казань")

    assert match.value == "Казань"
    assert match.confidence >= 0.6


def test_person_acting_on_behalf_is_kept():
    match = find_by_rules(field("who", "person", ("Подписант",)), "Подписант: действует от имени Иванов И. И.")

    assert match is not None and match.value == "Иванов И. И."


def test_ten_digit_amount_without_inn_label_is_amount():
    match = find_by_rules(field("total", "amount", ("Итого",)), "Итого: 1234567890")

    assert match is not None and match.value == "1234567890.00"
