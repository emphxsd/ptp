"""Приведение найденных значений к единому виду. None — значение некорректно (кандидат отбрасывается)."""
import re
from datetime import date
from decimal import Decimal, InvalidOperation

MIN_YEAR, MAX_YEAR = 1900, 2100
TWO_DIGIT_YEAR_BASE = 2000

MONTHS = {
    name: number
    for number, names in enumerate(
        (
            ("января", "январь"),
            ("февраля", "февраль"),
            ("марта", "март"),
            ("апреля", "апрель"),
            ("мая", "май"),
            ("июня", "июнь"),
            ("июля", "июль"),
            ("августа", "август"),
            ("сентября", "сентябрь"),
            ("октября", "октябрь"),
            ("ноября", "ноябрь"),
            ("декабря", "декабрь"),
        ),
        start=1,
    )
    for name in names
}

_NUMERIC_DATE = re.compile(r"^(\d{1,2})[./-](\d{1,2})[./-](\d{4}|\d{2})$")
_ISO_DATE = re.compile(r"^(\d{4})-(\d{2})-(\d{2})$")
_TEXT_DATE = re.compile(r"^(\d{1,2})\s+([а-яё]+)\s+(\d{4})(?:\s*(?:г\.?|года))?$")
_AMOUNT = re.compile(r"^\d+(?:\.\d{1,2})?$")
_DASH_KOPECKS = re.compile(r"^(\d+)-(\d{2})$")
# фамилия: «Иванов», «ИВАНОВ», двойная через дефис; используется и в шаблонах поиска (patterns.py)
SURNAME = r"(?:[А-ЯЁ][а-яё]+|[А-ЯЁ]{2,})(?:-(?:[А-ЯЁ][а-яё]+|[А-ЯЁ]{2,}))?"
_PERSON_SURNAME_FIRST = re.compile(rf"^({SURNAME})\s+([А-ЯЁ])\.\s*([А-ЯЁ])\.$")
_PERSON_INITIALS_FIRST = re.compile(rf"^([А-ЯЁ])\.\s*([А-ЯЁ])\.\s*({SURNAME})$")
_SPACES = re.compile(r"\s+")

# полные организационно-правовые формы → сокращения (длинные раньше: «публичное акционерное» до «акционерное»)
LEGAL_FORMS = (
    ("общество с ограниченной ответственностью", "ООО"),
    ("публичное акционерное общество", "ПАО"),
    ("непубличное акционерное общество", "НАО"),
    ("закрытое акционерное общество", "ЗАО"),
    ("открытое акционерное общество", "ОАО"),
    ("акционерное общество", "АО"),
    ("автономная некоммерческая организация", "АНО"),
    ("индивидуальный предприниматель", "ИП"),
)


def normalize_date(raw: str) -> str | None:
    text = _SPACES.sub(" ", raw.replace('"', "")).strip().lower()
    if m := _ISO_DATE.match(text):
        year, month, day = (int(g) for g in m.groups())
    elif m := _NUMERIC_DATE.match(text):
        day, month, year = int(m[1]), int(m[2]), int(m[3])
        if len(m[3]) == 2:
            year += TWO_DIGIT_YEAR_BASE
    elif m := _TEXT_DATE.match(text):
        month = MONTHS.get(m[2])
        if month is None:
            return None
        day, year = int(m[1]), int(m[3])
    else:
        return None
    if not MIN_YEAR <= year <= MAX_YEAR:
        return None
    try:
        return date(year, month, day).isoformat()
    except ValueError:
        return None


def normalize_amount(raw: str) -> str | None:
    text = raw.replace(" ", "").replace(",", ".")
    # формат «15 000-50»: рубли и копейки через дефис
    text = _DASH_KOPECKS.sub(r"\1.\2", text)
    if not _AMOUNT.match(text):
        return None
    try:
        return str(Decimal(text).quantize(Decimal("0.01")))
    except InvalidOperation:
        return None


def normalize_phone(raw: str) -> str | None:
    digits = re.sub(r"\D", "", raw)
    if len(digits) == 11 and digits[0] in "78":
        return "+7" + digits[1:]
    if len(digits) == 10:
        return "+7" + digits
    return None


def normalize_person(raw: str) -> str | None:
    text = _SPACES.sub(" ", raw).strip()
    if m := _PERSON_SURNAME_FIRST.match(text):
        return f"{_title(m[1])} {m[2]}. {m[3]}."
    if m := _PERSON_INITIALS_FIRST.match(text):
        return f"{_title(m[3])} {m[1]}. {m[2]}."
    return None


def _title(surname: str) -> str:
    """ИВАНОВ → Иванов, САЛТЫКОВ-ЩЕДРИН → Салтыков-Щедрин."""
    return "-".join(part[:1] + part[1:].lower() for part in surname.split("-"))


def normalize_organization(raw: str) -> str | None:
    text = _SPACES.sub(" ", raw).strip()
    lowered = text.lower()
    for full, short in LEGAL_FORMS:
        if lowered.startswith(full):
            text = short + text[len(full):]
            break
    if text.startswith("ИП "):
        person = normalize_person(text[3:])
        return f"ИП {person}" if person else text
    # «ФОРМА "Название"»: разбор без регулярного выражения (линейно на любом вводе);
    # всё между первой и последней кавычкой — название, вложенные кавычки сохраняются
    if text.endswith('"'):
        first_quote = text.find('"')
        form, name = text[:first_quote].strip(), text[first_quote + 1:-1].strip()
        if form and not any(ch.isspace() for ch in form) and name:
            return f'{form} "{name}"'
    return text or None


def normalize_text_value(raw: str) -> str | None:
    text = _SPACES.sub(" ", raw).strip()
    return text or None
