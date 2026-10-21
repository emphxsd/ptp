"""OCR страниц без текстового слоя: сканы в PDF и изображения PNG/JPG.
Распознаются только пустые страницы; срок и лимит страниц — из настроек, страница со сбоем пропускается."""
import math
import time
from dataclasses import dataclass, field, replace

import pymupdf

from app.config import Settings
from app.errors import DocumentReadError, OcrFailedError, TextTooLargeError, UnsafeDocumentError
from app.extraction.file_types import DocumentFormat
from app.extraction.images import image_size
from app.extraction.normalize import normalize_text
from app.extraction.ocr import OcrEngine, OcrError, OcrUnavailableError
from app.extraction.pdf_reader import PYMUPDF_LOCK
from app.extraction.text import ExtractedText

POINTS_PER_INCH = 72
# запас, чтобы округление размеров при рендеринге не превысило лимит пикселей
PIXEL_LIMIT_MARGIN = 0.99
# предупреждения, после которых часть документа могла остаться нераспознанной
INCOMPLETE_WARNINGS = frozenset({"OCR_PAGES_TRUNCATED", "OCR_PAGE_FAILED"})


@dataclass
class _Progress:
    pages: list[str]
    recognized: list[int] = field(default_factory=list)
    confidences: list[float] = field(default_factory=list)
    failed: bool = False


def apply_ocr(
    data: bytes, extracted: ExtractedText, settings: Settings, engine: OcrEngine, timeout: float | None = None
) -> tuple[ExtractedText, tuple[str, ...]]:
    """timeout — сколько осталось от общего срока документа (по умолчанию ocr_timeout_seconds)."""
    missing = [index for index, text in enumerate(extracted.pages_text) if not text]
    if not missing:
        return extracted, ()
    warnings: list[str] = []
    if len(missing) > settings.ocr_max_pages:
        missing, warnings = missing[: settings.ocr_max_pages], ["OCR_PAGES_TRUNCATED"]
    if extracted.format in (DocumentFormat.PNG, DocumentFormat.JPG):
        _check_image(data, extracted.format, settings)
    budget = min(settings.ocr_timeout_seconds, timeout) if timeout is not None else settings.ocr_timeout_seconds
    progress = _recognize(data, extracted.format, missing, settings, engine, time.monotonic() + budget, list(extracted.pages_text))
    if progress.failed:
        if not any(progress.pages):
            raise OcrFailedError("Не удалось распознать текст документа (сбой или таймаут OCR)")
        warnings.append("OCR_PAGE_FAILED")
    if sum(len(page) for page in progress.pages) > settings.max_text_chars:
        raise TextTooLargeError("Документ содержит слишком много текста")
    confidence = sum(progress.confidences) / len(progress.confidences) if progress.confidences else None
    if confidence is not None and confidence < settings.ocr_min_confidence:
        warnings.append("LOW_OCR_QUALITY")
    updated = replace(
        extracted, pages_text=tuple(progress.pages), ocr_page_indexes=tuple(progress.recognized), ocr_confidence=confidence
    )
    return updated, tuple(warnings)


def _recognize(
    data: bytes,
    fmt: DocumentFormat,
    indexes: list[int],
    settings: Settings,
    engine: OcrEngine,
    deadline: float,
    pages: list[str],
) -> _Progress:
    progress = _Progress(pages)
    for index in indexes:
        remaining = deadline - time.monotonic()
        if remaining <= 0:  # проверяется до рендеринга следующей страницы
            progress.failed = True
            break
        try:
            image = data if fmt in (DocumentFormat.PNG, DocumentFormat.JPG) else _render_page(data, index, settings)
            result = engine.recognize(image, remaining)
        except OcrUnavailableError:  # Tesseract не запускается — остальные страницы не пробуем
            progress.failed = True
            break
        except (OcrError, DocumentReadError):  # таймаут, сбой Tesseract или страница, которую не удалось отрендерить
            progress.failed = True
            continue
        text = normalize_text(result.text).strip()
        if text:
            progress.pages[index] = text
            progress.recognized.append(index)
            if result.confidence is not None:
                progress.confidences.append(result.confidence)
    return progress


def _check_image(data: bytes, fmt: DocumentFormat, settings: Settings) -> None:
    width, height = image_size(data, fmt)
    if width * height > settings.ocr_max_pixels:
        raise UnsafeDocumentError("Изображение слишком большое для распознавания")


def _render_page(data: bytes, index: int, settings: Settings) -> bytes:
    """Документ открывается заново для каждой страницы: блокировка PyMuPDF не держится во время OCR."""
    with PYMUPDF_LOCK:
        try:
            with pymupdf.open(stream=data, filetype="pdf") as doc:
                page = doc[index]
                area = page.rect.width * page.rect.height
                zoom = settings.ocr_dpi / POINTS_PER_INCH
                if area > 0:
                    zoom = min(zoom, math.sqrt(settings.ocr_max_pixels * PIXEL_LIMIT_MARGIN / area))
                pixmap = page.get_pixmap(matrix=pymupdf.Matrix(zoom, zoom), colorspace=pymupdf.csGRAY, alpha=False)
                return pixmap.tobytes("png")
        except Exception as exc:  # сбои рендеринга недоверенного PDF
            raise DocumentReadError("Не удалось подготовить страницу к распознаванию") from exc
