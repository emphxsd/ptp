import pytest

from app.fields.normalizers import (
    normalize_amount,
    normalize_date,
    normalize_organization,
    normalize_person,
    normalize_phone,
)


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("12.03.2026", "2026-03-12"),
        ("12/03/2026", "2026-03-12"),
        ("12-03-2026", "2026-03-12"),
        ("2026-03-12", "2026-03-12"),
        ("1.3.2026", "2026-03-01"),
        ("12.03.26", "2026-03-12"),
        ('"12" марта 2026 г.', "2026-03-12"),
        ("12 марта 2026 года", "2026-03-12"),
        ("1 января 2025", "2025-01-01"),
        ("31 Декабря 2025", "2025-12-31"),
    ],
)
def test_normalize_date(raw, expected):
    assert normalize_date(raw) == expected


@pytest.mark.parametrize("raw", ["31.02.2026", "00.01.2026", "12.13.2026", "45 марта 2026", "12 мартобря 2026"])
def test_impossible_date_is_rejected(raw):
    assert normalize_date(raw) is None


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("15 000,00", "15000.00"),
        ("15000", "15000.00"),
        ("1 200.5", "1200.50"),
        ("1 234 567,89", "1234567.89"),
        ("0,99", "0.99"),
    ],
)
def test_normalize_amount(raw, expected):
    assert normalize_amount(raw) == expected


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("+7 (912) 345-67-89", "+79123456789"),
        ("8-912-345-67-89", "+79123456789"),
        ("8 (495) 1234567", "+74951234567"),
    ],
)
def test_normalize_phone(raw, expected):
    assert normalize_phone(raw) == expected


def test_phone_with_wrong_digit_count_is_rejected():
    assert normalize_phone("+7 912 345 67") is None


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("Иванов И. И.", "Иванов И. И."),
        ("Иванов И.И.", "Иванов И. И."),
        ("И.И. Иванов", "Иванов И. И."),
        ("Салтыков-Щедрин М. Е.", "Салтыков-Щедрин М. Е."),
    ],
)
def test_normalize_person(raw, expected):
    assert normalize_person(raw) == expected


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ('ООО  "Ромашка"', 'ООО "Ромашка"'),
        ('Общество с ограниченной ответственностью "Ромашка"', 'ООО "Ромашка"'),
        ('Акционерное общество "Лютик"', 'АО "Лютик"'),
        ("ИП Иванов И.И.", "ИП Иванов И. И."),
    ],
)
def test_normalize_organization(raw, expected):
    assert normalize_organization(raw) == expected
