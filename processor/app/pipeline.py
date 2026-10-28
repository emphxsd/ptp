"""Конвейер обработки документа: проверка файла → извлечение текста → поиск полей.
Поиск полей — каскад этапов: правила → NER → LLM, затем проверка значений (validation.py)."""
import logging
import time
from dataclasses import dataclass, replace

from app.config import Settings
from app.errors import EmptyDocumentError, FileTooLargeError, NoTextLayerError
from app.extraction.ocr import OcrEngine
from app.extraction.ocr_stage import INCOMPLETE_WARNINGS, apply_ocr
from app.extraction.file_types import DocumentFormat, detect_format
from app.extraction.text import ExtractedText, extract_text
from app.fields.ner import EntityRecognizer, get_recognizer
from app.fields.ner_stage import NerStage
from app.fields.rules import DocumentIndex, RuleMatch, find_by_rules
from app.fields.validation import review_issues
from app.llm.client import LlmClient
from app.llm.errors import LlmError, LlmUnavailableError
from app.llm.stage import LlmRequest, LlmStage
from app.schemas import DocumentInfo, ExtractionResult, FieldResult, Template, TemplateField

logger = logging.getLogger("processor")
# согласие двух этапов на одном значении — дополнительное подтверждение
AGREEMENT_BONUS = 0.1
AGREEMENT_MAX_CONFIDENCE = 0.95
# полей в одном запросе к LLM: ответ должен уложиться в llm_max_output_tokens
MAX_LLM_FIELDS = 40
MAX_LLM_CANDIDATES = 3


@dataclass(frozen=True)
class _Found:
    field: TemplateField
    match: RuleMatch | None
    source: str | None


def process_document(
    data: bytes,
    template: Template,
    settings: Settings,
    include_text: bool = False,
    recognizer: EntityRecognizer | None = None,
    llm: LlmClient | None = None,
    ocr: OcrEngine | None = None,
    llm_blocked: bool = False,
) -> ExtractionResult:
    """llm_blocked — запрос требовал локальную LLM, а настроена облачная: этап пропущен, код — в warnings."""
    started = time.perf_counter()
    if len(data) > settings.max_file_bytes:
        raise FileTooLargeError(f"Файл больше {settings.max_file_mb:g} МБ")

    fmt = detect_format(data)
    extracted = extract_text(data, fmt, settings)

    def remaining() -> float:  # общий срок документа делят OCR и LLM
        return settings.processing_budget_seconds - (time.perf_counter() - started)

    ocr_warnings: tuple[str, ...] = ()
    ocr_available = ocr is not None and settings.ocr_enabled
    if ocr_available and extracted.pages_without_text:
        extracted, ocr_warnings = apply_ocr(data, extracted, settings, ocr, timeout=max(remaining(), 0.0))
    elif extracted.pages_without_text and extracted.has_text:
        ocr_warnings = ("OCR_UNAVAILABLE",)  # страницы-сканы смешанного PDF пропущены
    _ensure_has_text(extracted, ocr_available)

    # модели NER загружаются, только если в шаблоне есть поля, которым они нужны
    if recognizer is None and settings.ner_enabled and any(NerStage.applies_to(f) for f in template.fields):
        recognizer = _load_recognizer()
    index = DocumentIndex(extracted.text)
    found = _cascade(index, template, settings, recognizer)
    llm_used, llm_warnings = False, ("LLM_SKIPPED_NOT_LOCAL",) if llm_blocked else ()
    if llm is not None and settings.llm_enabled:
        found, llm_used, llm_warnings = _refine_with_llm(found, index, settings, llm, remaining())
    threshold = settings.review_threshold
    document_issues = _document_issues(ocr_warnings)
    fields = tuple(_to_result(f.field, f.match, f.source, threshold, document_issues) for f in found)
    warnings = (*ocr_warnings, *llm_warnings)
    return ExtractionResult(
        document=DocumentInfo(
            format=extracted.format,
            pages=extracted.pages,
            pages_without_text=extracted.pages_without_text,
            has_text_layer=extracted.has_text_layer,
            chars=len(extracted.text),
            ocr_pages=extracted.ocr_pages,
            ocr_confidence=extracted.ocr_confidence,
        ),
        fields=fields,
        llm_used=llm_used,
        processing_ms=int((time.perf_counter() - started) * 1000),
        text=extracted.text if include_text else None,
        warnings=warnings,
        needs_review=any(f.needs_review for f in fields),
        review_fields=tuple(f.name for f in fields if f.needs_review),
    )


def _document_issues(ocr_warnings: tuple[str, ...]) -> tuple[str, ...]:
    """При плохом или неполном распознавании любое значение может быть ошибочным или пропущенным."""
    issues = []
    if "LOW_OCR_QUALITY" in ocr_warnings:
        issues.append("LOW_OCR_QUALITY")
    if INCOMPLETE_WARNINGS.intersection(ocr_warnings):
        issues.append("INCOMPLETE_OCR")
    return tuple(issues)


def _load_recognizer() -> EntityRecognizer | None:
    """Сбой загрузки моделей не должен ронять обработку: продолжаем на одних правилах."""
    try:
        return get_recognizer()
    except Exception as exc:
        logger.warning("NER недоступен, используются только правила: %s", type(exc).__name__)
        return None


def _ensure_has_text(extracted: ExtractedText, ocr_available: bool) -> None:
    if extracted.has_text:
        return
    if extracted.format is DocumentFormat.DOCX:
        raise EmptyDocumentError("Документ не содержит текста")
    if ocr_available:
        raise NoTextLayerError("Текст на скане или изображении не распознан")
    raise NoTextLayerError("В документе нет текстового слоя (скан или изображение), а OCR отключён или не установлен")


def find_fields(
    extracted: ExtractedText,
    template: Template,
    settings: Settings,
    recognizer: EntityRecognizer | None = None,
) -> tuple[FieldResult, ...]:
    """Правила и NER без LLM (используется в тестах и экспериментах)."""
    found = _cascade(DocumentIndex(extracted.text), template, settings, recognizer)
    return tuple(_to_result(f.field, f.match, f.source, settings.review_threshold) for f in found)


def _cascade(
    index: DocumentIndex, template: Template, settings: Settings, recognizer: EntityRecognizer | None
) -> list[_Found]:
    """Правила → NER. Следующий этап обрабатывает только поля, которые предыдущий не нашёл
    или нашёл с уверенностью ниже review_threshold; берётся более уверенный результат."""
    ner = NerStage(recognizer, index) if recognizer is not None else None
    threshold = settings.review_threshold
    found = []
    for field in template.fields:
        match, source = find_by_rules(field, index.text, index), "rule"
        explicit_ner = field.ner_label is not None
        if ner is not None and ner.applies_to(field) and (explicit_ner or match is None or match.confidence < threshold):
            match, source = _combine(match, ner.find(field), explicit_ner, threshold)
        found.append(_Found(field, match, source if match is not None else None))
    return found


def _refine_with_llm(
    found: list[_Found], index: DocumentIndex, settings: Settings, llm: LlmClient, time_left: float
) -> tuple[list[_Found], bool, tuple[str, ...]]:
    """Одним запросом к LLM уточняются поля без значения или с уверенностью ниже порога.
    Сбой LLM не прерывает обработку: остаются результаты правил и NER, код ошибки — в warnings."""
    threshold = settings.review_threshold
    pending = [f for f in found if f.match is None or f.match.confidence < threshold]
    if not pending:
        return found, False, ()
    if time_left < settings.llm_min_seconds:  # общий срок документа почти исчерпан (например, долгий OCR)
        return found, False, ("LLM_SKIPPED_TIME_BUDGET",)
    warnings: list[str] = []
    if len(pending) > MAX_LLM_FIELDS:
        pending, warnings = pending[:MAX_LLM_FIELDS], ["LLM_FIELDS_TRUNCATED"]
    stage = LlmStage(llm, index, settings.llm_max_input_chars, settings.llm_max_output_tokens, time_left)
    requests = [LlmRequest(f.field, _candidates(f.match)) for f in pending]
    try:
        outcome = stage.find(requests)
    except LlmError as exc:
        logger.warning("LLM не использована: %s", exc.code)
        return found, False, (*warnings, exc.code)
    except Exception as exc:  # непредвиденный сбой LLM-этапа не должен ронять обработку документа
        logger.warning("LLM не использована: %s", type(exc).__name__)
        return found, False, (*warnings, LlmUnavailableError.code)
    refined = []
    for item in found:
        llm_match = outcome.matches.get(item.field.name)
        if llm_match is None:
            refined.append(item)
            continue
        match, source = _merge_llm(item, llm_match)
        refined.append(_Found(item.field, match, source))
    return refined, True, (*warnings, *outcome.warnings)


def _candidates(match: RuleMatch | None) -> tuple[str, ...]:
    if match is None:
        return ()
    return tuple(dict.fromkeys((match.value, *match.alternatives)))[:MAX_LLM_CANDIDATES]


def _merge_llm(item: _Found, llm_match: RuleMatch) -> tuple[RuleMatch, str]:
    """LLM заменяет значение предыдущих этапов, только если её значение подтверждено текстом документа
    и увереннее. Согласие засчитывается только для подтверждённого значения: модель видела кандидатов
    и может просто повторить один из них."""
    current = item.match
    if current is None:
        return llm_match, "llm"
    source = item.source or "rule"
    verified = llm_match.raw is not None
    if current.value == llm_match.value:
        if not verified:
            return current, source
        confidence = min(AGREEMENT_MAX_CONFIDENCE, max(current.confidence, llm_match.confidence) + AGREEMENT_BONUS)
        return replace(current, confidence=confidence), source
    if verified and llm_match.confidence > current.confidence:
        return replace(llm_match, alternatives=_alternatives(llm_match.value, current.value, *current.alternatives)), "llm"
    return replace(current, alternatives=_alternatives(current.value, *current.alternatives, llm_match.value)), source


def _alternatives(winner: str, *values: str) -> tuple[str, ...]:
    return tuple(v for v in dict.fromkeys(values) if v != winner)


def _combine(
    rule: RuleMatch | None, ner_match: RuleMatch | None, explicit_ner: bool, threshold: float
) -> tuple[RuleMatch | None, str]:
    """Выбор между правилами и NER. Согласие этапов повышает уверенность; значение
    проигравшего этапа сохраняется в alternatives — пригодится для проверки и этапа LLM."""
    if ner_match is None:
        return rule, "rule"
    if rule is None:
        return ner_match, "ner"
    if ner_match.value == rule.value:
        confidence = min(AGREEMENT_MAX_CONFIDENCE, max(rule.confidence, ner_match.confidence) + AGREEMENT_BONUS)
        return replace(rule, confidence=confidence), "rule"
    ner_wins = (explicit_ner and ner_match.confidence >= threshold) or ner_match.confidence > rule.confidence
    winner, loser = (ner_match, rule) if ner_wins else (rule, ner_match)
    alternatives = tuple(dict.fromkeys((*winner.alternatives, loser.value)))
    return replace(winner, alternatives=alternatives), "ner" if ner_wins else "rule"


def _to_result(
    field: TemplateField,
    match: RuleMatch | None,
    source: str | None,
    threshold: float,
    document_issues: tuple[str, ...] = (),
) -> FieldResult:
    issues = review_issues(field, match, source, threshold, document_issues)
    if match is None:
        return FieldResult(
            name=field.name, value=None, source=None, raw=None, confidence=0.0, needs_review=bool(issues), issues=issues
        )
    return FieldResult(
        name=field.name,
        value=match.value,
        source=source,
        raw=match.raw,
        start=match.start,
        end=match.end,
        confidence=match.confidence,
        needs_review=bool(issues),
        alternatives=match.alternatives,
        issues=issues,
    )
