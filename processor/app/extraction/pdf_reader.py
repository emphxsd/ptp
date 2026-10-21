"""Чтение PDF через PyMuPDF с лимитами на страницы и объём текста."""
import threading

import pymupdf

from app.config import Settings
from app.errors import (
    DocumentReadError,
    PasswordProtectedError,
    ProcessingError,
    TextTooLargeError,
    TooManyPagesError,
)
from app.extraction.file_types import DocumentFormat
from app.extraction.normalize import normalize_text
from app.extraction.text import ExtractedText

# PyMuPDF не поддерживает параллельную работу из нескольких потоков — сериализуем доступ.
# Изоляция в отдельных процессах с таймаутом будет добавлена при контейнеризации.
PYMUPDF_LOCK = threading.Lock()

_REPLACEMENT_CHAR = "�"
_PRIVATE_USE = range(0xE000, 0xF900)


def read_pdf(data: bytes, settings: Settings) -> ExtractedText:
    with PYMUPDF_LOCK:
        try:
            raw_pages = _read_pages(data, settings)
        except ProcessingError:
            raise
        except Exception as exc:  # любые сбои парсера на недоверенном вводе
            raise DocumentReadError("Не удалось прочитать PDF: файл повреждён") from exc

    pages_text = tuple(page if _meaningful_chars(page) >= settings.min_chars_per_page else "" for page in raw_pages)
    return ExtractedText(
        format=DocumentFormat.PDF,
        pages_text=pages_text,
        pages=len(raw_pages),
        pages_without_text=sum(1 for page in pages_text if not page),
    )


def _read_pages(data: bytes, settings: Settings) -> list[str]:
    with pymupdf.open(stream=data, filetype="pdf") as doc:
        if doc.needs_pass:
            raise PasswordProtectedError("PDF защищён паролем")
        if doc.page_count > settings.max_pages:
            raise TooManyPagesError(f"В документе больше {settings.max_pages} страниц")
        pages: list[str] = []
        total_chars = 0
        for page in doc:
            text = normalize_text(page.get_text("text"))
            total_chars += len(text)
            if total_chars > settings.max_text_chars:
                raise TextTooLargeError("Документ содержит слишком много текста")
            pages.append(text)
        return pages


def _meaningful_chars(text: str) -> int:
    """Непробельные символы без «мусора» битых шрифтов (U+FFFD, Private Use Area)."""
    return sum(1 for ch in text if not ch.isspace() and ch != _REPLACEMENT_CHAR and ord(ch) not in _PRIVATE_USE)
