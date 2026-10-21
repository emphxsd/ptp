"""Конвейер обработки документа. Сейчас: проверка файла и извлечение текста.
Следующие шаги (правила, NER, LLM, валидация) встраиваются в find_fields."""
import time

from app.config import Settings
from app.errors import EmptyDocumentError, FileTooLargeError, NoTextLayerError
from app.extraction.file_types import DocumentFormat, detect_format
from app.extraction.text import ExtractedText, extract_text
from app.schemas import DocumentInfo, ExtractionResult, FieldResult, Template, TemplateField


def process_document(data: bytes, template: Template, settings: Settings, include_text: bool = False) -> ExtractionResult:
    started = time.perf_counter()
    if len(data) > settings.max_file_bytes:
        raise FileTooLargeError(f"Файл больше {settings.max_file_mb:g} МБ")

    fmt = detect_format(data)
    extracted = extract_text(data, fmt, settings)
    _ensure_has_text(extracted)

    fields = find_fields(extracted, template)
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


def find_fields(extracted: ExtractedText, template: Template) -> tuple[FieldResult, ...]:
    """Пока поиск не реализован: все поля не найдены, обязательные требуют проверки."""
    return tuple(_not_found(field) for field in template.fields)


def _not_found(field: TemplateField) -> FieldResult:
    return FieldResult(name=field.name, value=None, source=None, confidence=0.0, needs_review=field.is_required)
