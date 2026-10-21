"""Проверка найденных значений и причины пометки «требует проверки» (needs_review).
Значение не удаляется: пользователь видит его вместе с причиной и исправляет в интерфейсе."""
from datetime import date
from decimal import Decimal, InvalidOperation

from app.fields.rules import RuleMatch
from app.fields.user_regex import compile_user_regex
from app.schemas import DataType, FieldValidation, TemplateField

REQUIRED_MISSING = "REQUIRED_MISSING"
LOW_CONFIDENCE = "LOW_CONFIDENCE"
NOT_CONFIRMED = "NOT_CONFIRMED"  # значение от LLM, которое не нашлось в тексте документа
OUT_OF_RANGE = "OUT_OF_RANGE"
TOO_LONG = "TOO_LONG"
NOT_ALLOWED = "NOT_ALLOWED"
INVALID_FORMAT = "INVALID_FORMAT"


def review_issues(
    field: TemplateField,
    match: RuleMatch | None,
    source: str | None,
    threshold: float,
    document_issues: tuple[str, ...] = (),
) -> tuple[str, ...]:
    """document_issues — причины на уровне документа (например, плохое качество OCR) для каждого найденного поля."""
    if match is None:  # поле могло не найтись из-за ошибок или пропусков распознавания
        return ((REQUIRED_MISSING,) if field.is_required else ()) + document_issues
    issues = list(document_issues)
    if match.confidence < threshold:
        issues.append(LOW_CONFIDENCE)
    if source == "llm" and match.raw is None:
        issues.append(NOT_CONFIRMED)
    if field.validation is not None:
        issues += _rule_issues(field.data_type, field.validation, match.value)
    return tuple(issues)


def _rule_issues(data_type: DataType, rules: FieldValidation, value: str) -> list[str]:
    issues = []
    if (range_issue := _range_issue(data_type, rules, value)) is not None:
        issues.append(range_issue)
    if rules.max_length is not None and len(value) > rules.max_length:
        issues.append(TOO_LONG)
    if rules.allowed_values is not None and _fold(value) not in {_fold(v) for v in rules.allowed_values}:
        issues.append(NOT_ALLOWED)
    if rules.pattern is not None and compile_user_regex(rules.pattern).fullmatch(value) is None:
        issues.append(INVALID_FORMAT)
    return issues


def _range_issue(data_type: DataType, rules: FieldValidation, value: str) -> str | None:
    """Значения уже нормализованы: сумма — «15000.00», дата — ГГГГ-ММ-ДД; иное — неверный формат."""
    if data_type is DataType.AMOUNT and (rules.min_value is not None or rules.max_value is not None):
        try:
            amount = Decimal(value)
        except InvalidOperation:
            return INVALID_FORMAT
        inside = (rules.min_value is None or amount >= rules.min_value) and (
            rules.max_value is None or amount <= rules.max_value
        )
        return None if inside else OUT_OF_RANGE
    if data_type is DataType.DATE and (rules.min_date is not None or rules.max_date is not None):
        try:
            day = date.fromisoformat(value)
        except ValueError:
            return INVALID_FORMAT
        inside = (rules.min_date is None or day >= rules.min_date) and (rules.max_date is None or day <= rules.max_date)
        return None if inside else OUT_OF_RANGE
    return None


def _fold(value: str) -> str:
    return " ".join(value.split()).casefold().replace("ё", "е")
