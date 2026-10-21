"""Конвейер обработки документа: проверка файла → извлечение текста → поиск полей.
Поиск полей — каскад этапов в find_fields: правила → NER (→ LLM и валидация на следующих шагах)."""
import logging
import time
from dataclasses import replace

from app.config import Settings
from app.errors import EmptyDocumentError, FileTooLargeError, NoTextLayerError
from app.extraction.file_types import DocumentFormat, detect_format
from app.extraction.text import ExtractedText, extract_text
from app.fields.ner import EntityRecognizer, get_recognizer
from app.fields.ner_stage import NerStage
from app.fields.rules import DocumentIndex, RuleMatch, find_by_rules

logger = logging.getLogger("processor")
# согласие правил и NER на одном значении — дополнительное подтверждение
AGREEMENT_BONUS = 0.1
AGREEMENT_MAX_CONFIDENCE = 0.95
from app.schemas import DocumentInfo, ExtractionResult, FieldResult, Template, TemplateField


def process_document(
    data: bytes,
    template: Template,
    settings: Settings,
    include_text: bool = False,
    recognizer: EntityRecognizer | None = None,
) -> ExtractionResult:
    started = time.perf_counter()
    if len(data) > settings.max_file_bytes:
        raise FileTooLargeError(f"Файл больше {settings.max_file_mb:g} МБ")

    fmt = detect_format(data)
    extracted = extract_text(data, fmt, settings)
    _ensure_has_text(extracted)

    # модели NER загружаются, только если в шаблоне есть поля, которым они нужны
    if recognizer is None and settings.ner_enabled and any(NerStage.applies_to(f) for f in template.fields):
        recognizer = _load_recognizer()
    fields = find_fields(extracted, template, settings, recognizer)
    return ExtractionResult(
        document=DocumentInfo(
            format=extracted.format,
            pages=extracted.pages,
            pages_without_text=extracted.pages_without_text,
            has_text_layer=extracted.has_text_layer,
            chars=len(extracted.text),
        ),
        fields=fields,
        llm_used=False,
        processing_ms=int((time.perf_counter() - started) * 1000),
        text=extracted.text if include_text else None,
    )


def _load_recognizer() -> EntityRecognizer | None:
    """Сбой загрузки моделей не должен ронять обработку: продолжаем на одних правилах."""
    try:
        return get_recognizer()
    except Exception as exc:
        logger.warning("NER недоступен, используются только правила: %s", type(exc).__name__)
        return None


def _ensure_has_text(extracted: ExtractedText) -> None:
    if extracted.has_text_layer:
        return
    if extracted.format is DocumentFormat.DOCX:
        raise EmptyDocumentError("Документ не содержит текста")
    raise NoTextLayerError("В документе нет текстового слоя (скан или изображение); OCR будет добавлен позже")


def find_fields(
    extracted: ExtractedText,
    template: Template,
    settings: Settings,
    recognizer: EntityRecognizer | None = None,
) -> tuple[FieldResult, ...]:
    """Каскад этапов: правила → NER. Следующий этап обрабатывает только поля, которые предыдущий
    не нашёл или нашёл с уверенностью ниже review_threshold; берётся более уверенный результат."""
    text = extracted.text
    index = DocumentIndex(text)  # позиции якорей и кандидаты считаются один раз на документ
    ner = NerStage(recognizer, index) if recognizer is not None else None
    threshold = settings.review_threshold
    results = []
    for field in template.fields:
        match, source = find_by_rules(field, text, index), "rule"
        explicit_ner = field.ner_label is not None
        if ner is not None and ner.applies_to(field) and (explicit_ner or match is None or match.confidence < threshold):
            match, source = _combine(match, ner.find(field), explicit_ner, threshold)
        results.append(_to_result(field, match, source, threshold))
    return tuple(results)


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


def _to_result(field: TemplateField, match: RuleMatch | None, source: str, threshold: float) -> FieldResult:
    if match is None:
        return FieldResult(
            name=field.name, value=None, source=None, raw=None, confidence=0.0, needs_review=field.is_required
        )
    return FieldResult(
        name=field.name,
        value=match.value,
        source=source,
        raw=match.raw,
        start=match.start,
        end=match.end,
        confidence=match.confidence,
        needs_review=match.confidence < threshold,
        alternatives=match.alternatives,
    )
