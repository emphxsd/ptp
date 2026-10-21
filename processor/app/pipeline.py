"""Конвейер обработки документа: проверка файла → извлечение текста → поиск полей.
Поиск полей сейчас — этап правил; NER, LLM и валидация встраиваются в find_fields."""
import time

from app.config import Settings
from app.errors import EmptyDocumentError, FileTooLargeError, NoTextLayerError
from app.extraction.file_types import DocumentFormat, detect_format
from app.extraction.text import ExtractedText, extract_text
from app.fields.rules import DocumentIndex, RuleMatch, find_by_rules
from app.schemas import DocumentInfo, ExtractionResult, FieldResult, Template, TemplateField


def process_document(data: bytes, template: Template, settings: Settings, include_text: bool = False) -> ExtractionResult:
    started = time.perf_counter()
    if len(data) > settings.max_file_bytes:
        raise FileTooLargeError(f"Файл больше {settings.max_file_mb:g} МБ")

    fmt = detect_format(data)
    extracted = extract_text(data, fmt, settings)
    _ensure_has_text(extracted)

    fields = find_fields(extracted, template, settings)
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


def _ensure_has_text(extracted: ExtractedText) -> None:
    if extracted.has_text_layer:
        return
    if extracted.format is DocumentFormat.DOCX:
        raise EmptyDocumentError("Документ не содержит текста")
    raise NoTextLayerError("В документе нет текстового слоя (скан или изображение); OCR будет добавлен позже")


def find_fields(extracted: ExtractedText, template: Template, settings: Settings) -> tuple[FieldResult, ...]:
    """Этап правил. Следующие этапы (NER, LLM) будут обрабатывать только поля,
    которые не найдены или найдены с уверенностью ниже review_threshold."""
    text = extracted.text
    index = DocumentIndex(text)  # позиции якорей и кандидаты считаются один раз на документ
    return tuple(
        _to_result(field, find_by_rules(field, text, index), settings.review_threshold) for field in template.fields
    )


def _to_result(field: TemplateField, match: RuleMatch | None, threshold: float) -> FieldResult:
    if match is None:
        return FieldResult(
            name=field.name, value=None, source=None, raw=None, confidence=0.0, needs_review=field.is_required
        )
    return FieldResult(
        name=field.name,
        value=match.value,
        source="rule",
        raw=match.raw,
        start=match.start,
        end=match.end,
        confidence=match.confidence,
        needs_review=match.confidence < threshold,
        alternatives=match.alternatives,
    )
