"""Контрольные суммы реквизитов. ИНН: алгоритм ФНС (веса, остаток от деления на 11, затем на 10)."""

_INN10_WEIGHTS = (2, 4, 10, 3, 5, 9, 4, 6, 8)
_INN12_WEIGHTS_11 = (7, 2, 4, 10, 3, 5, 9, 4, 6, 8)
_INN12_WEIGHTS_12 = (3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8)


def _control_digit(digits: list[int], weights: tuple[int, ...]) -> int:
    return sum(w * d for w, d in zip(weights, digits)) % 11 % 10


def is_valid_inn(value: str) -> bool:
    if not value.isdigit() or not value.isascii():
        return False
    # код региона «00» не существует; строка из нулей формально проходит контрольную сумму
    if value.startswith("00"):
        return False
    digits = [int(c) for c in value]
    if len(digits) == 10:
        return _control_digit(digits, _INN10_WEIGHTS) == digits[9]
    if len(digits) == 12:
        return (
            _control_digit(digits, _INN12_WEIGHTS_11) == digits[10]
            and _control_digit(digits, _INN12_WEIGHTS_12) == digits[11]
        )
    return False
