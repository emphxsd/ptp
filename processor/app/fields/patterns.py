"""Встроенные шаблоны по типам полей. Шаблоны свои (не пользовательские) и написаны без
вложенных неограниченных повторов, поэтому выполняются стандартным модулем re
(проверено на враждебных текстах по 2 млн символов). Значение — именованная группа «v»."""
import re
from collections.abc import Callable
from dataclasses import dataclass

from app.fields.checksums import is_valid_inn
from app.fields.normalizers import (
    MONTHS,
    SURNAME,
    normalize_amount,
    normalize_date,
    normalize_organization,
    normalize_person,
    normalize_phone,
    normalize_text_value,
)
from app.schemas import DataType


@dataclass(frozen=True)
class TypeRule:
    pattern: re.Pattern[str]
    normalize: Callable[[str], str | None]
    # «сильный» формат: совпадение почти наверняка верно (контрольная сумма, e-mail и т. п.)
    strong: bool = False
    # потолок уверенности при поиске без якоря: например, 9 цифр КПП легко спутать с БИК или ОКПО
    global_cap: float = 1.0


_MONTH_NAMES = "|".join(sorted(MONTHS, key=len, reverse=True))
_PERSON = (
    rf"(?<![\w.])(?:{SURNAME}\s+[А-ЯЁ]\.\s?[А-ЯЁ]\.|[А-ЯЁ]\.\s?[А-ЯЁ]\.\s?{SURNAME})(?!\w)"
)
_FULL_NAME = r"[А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+){1,2}"
_LEGAL_SHORT = (
    r"ФГБОУ|ФГАОУ|ФГУП|ФГБУ|ГБОУ|МБОУ|МАОУ|ГБУ|МБУ|МКУ|ГУП|МУП|"
    r"СПАО|ПАО|ЗАО|ОАО|НАО|САО|ООО|ОДО|АО|АНО|НКО|НПО|АКБ|КБ|ТСЖ|ТСН|СНТ|ЖСК|СПК|ПК"
)
_LEGAL_FULL = (
    r"(?i:общество с ограниченной ответственностью|публичное акционерное общество|"
    r"непубличное акционерное общество|закрытое акционерное общество|открытое акционерное общество|"
    r"акционерное общество|автономная некоммерческая организация)"
)
_ENTREPRENEUR = r"(?:(?<![А-ЯЁ])ИП|(?i:индивидуальный предприниматель))"

_DATE = re.compile(
    r"(?P<v>"
    r"(?<![\d.\-/])\d{4}-\d{2}-\d{2}(?![\d\-])"
    # только четырёхзначный год: «п. 5.2.15» и «12-11-56» — не даты
    r"|(?<![\d.\-/])\d{1,2}([./-])\d{1,2}\2\d{4}(?!\d)(?![.,\-/]\d)"
    rf'|(?<![\d"])"?\d{{1,2}}"?[ ]+(?i:{_MONTH_NAMES})[ ]+\d{{4}}(?!\d)(?:[ ]?(?:г\.|года|г\b))?'
    r")"
)
# сумма: разряды через пробел, копейки через запятую/точку или «-50»; не часть даты, номера или процента
_AMOUNT = re.compile(
    r"(?<![\d.,/+\-])(?P<v>(?:\d{1,3}(?: \d{3})+|\d{1,13})(?:[.,]\d{1,2}|-\d{2}(?!\d))?)"
    r"(?![\d%/\-]|[.,]\d|\s*%)"
)
_NUMBER = re.compile(
    r"(?:№\s*|(?<![A-Za-zА-Яа-яЁё])(?:No\.?|N)\s+)"
    r"(?P<v>[A-Za-zА-Яа-яЁё0-9][A-Za-zА-Яа-яЁё0-9/.\-]{0,29})(?![A-Za-zА-Яа-яЁё0-9])"
)
_INN = re.compile(r"(?<!\d)(?P<v>\d{12}|\d{10})(?![\d]|[.,]\d)")
_KPP = re.compile(r"(?<![\dA-Z])(?P<v>\d{4}[\dA-Z]{2}\d{3})(?![\dA-Z])")
_BIK = re.compile(r"(?<!\d)(?P<v>04\d{7})(?!\d)")
_PHONE = re.compile(
    r"(?<![\d+])(?P<v>(?:\+7|8)[ .\-]?\(?\d{3}\)?[ .\-]?\d{3}[ .\-]?\d{2}[ .\-]?\d{2})(?!\d)"
)
_EMAIL = re.compile(
    r"(?<![\w.+\-])(?P<v>[A-Za-z0-9._%+\-]{1,64}@[\w\-]{1,63}(?:\.[\w\-]{1,63}){0,8}\.[^\W\d_]{2,24})(?![\w\-])"
)
_PERSON_RE = re.compile(rf"(?P<v>{_PERSON})")
# название в кавычках: до кавычки, за которой идёт разделитель — так сохраняются вложенные кавычки
_QUOTED_NAME = r'"[^\n]{1,150}?"(?=[\s,.;:)]|$)'
_ORGANIZATION = re.compile(
    rf"(?P<v>(?:{_LEGAL_FULL}|(?<![А-ЯЁ])(?:{_LEGAL_SHORT}))\s*{_QUOTED_NAME}"
    rf"|{_ENTREPRENEUR}\s+(?:{_PERSON}|{_FULL_NAME}))"
)


def _inn(raw: str) -> str | None:
    return raw if is_valid_inn(raw) else None


def _number(raw: str) -> str | None:
    value = raw.rstrip(".-/")
    return value if any(ch.isdigit() for ch in value) else None


def _email(raw: str) -> str | None:
    return raw.lower()


TYPE_RULES: dict[DataType, TypeRule] = {
    DataType.DATE: TypeRule(_DATE, normalize_date),
    DataType.AMOUNT: TypeRule(_AMOUNT, normalize_amount),
    DataType.NUMBER: TypeRule(_NUMBER, _number),
    DataType.INN: TypeRule(_INN, _inn, strong=True),
    DataType.KPP: TypeRule(_KPP, normalize_text_value, global_cap=0.5),
    DataType.BIK: TypeRule(_BIK, normalize_text_value),
    DataType.PHONE: TypeRule(_PHONE, normalize_phone, strong=True),
    DataType.EMAIL: TypeRule(_EMAIL, _email, strong=True),
    DataType.PERSON: TypeRule(_PERSON_RE, normalize_person),
    DataType.ORGANIZATION: TypeRule(_ORGANIZATION, normalize_organization),
}

# как нормализовать значение, найденное пользовательским regex, в зависимости от типа поля
TYPE_NORMALIZERS: dict[DataType, Callable[[str], str | None]] = {
    data_type: rule.normalize for data_type, rule in TYPE_RULES.items()
} | {DataType.STRING: normalize_text_value}
