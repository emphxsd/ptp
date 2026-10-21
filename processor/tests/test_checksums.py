import pytest

from app.fields.checksums import is_valid_inn


@pytest.mark.parametrize("inn", ["7707083893", "500100732259", "7736050003"])
def test_valid_inn(inn):
    assert is_valid_inn(inn) is True


@pytest.mark.parametrize(
    "inn",
    [
        "7707083894",  # неверная контрольная цифра (10 знаков)
        "500100732258",  # неверная контрольная цифра (12 знаков)
        "770708389",  # 9 цифр
        "77070838930",  # 11 цифр
        "77070838ab",  # не цифры
        "",
    ],
)
def test_invalid_inn(inn):
    assert is_valid_inn(inn) is False
