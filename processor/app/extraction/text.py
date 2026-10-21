"""Извлечение текста: выбор читателя по формату и общий результат."""
from dataclasses import dataclass

from app.config import Settings
from app.extraction.file_types import DocumentFormat

PAGE_SEPARATOR = "\n\n"


@dataclass(frozen=True)
class ExtractedText:
    format: DocumentFormat
    # текст по страницам (для PDF); у страниц-сканов — пустая строка. DOCX — один блок.
    pages_text: tuple[str, ...]
    pages: int | None
    pages_without_text: int

    @property
    def text(self) -> str:
        return PAGE_SEPARATOR.join(page for page in self.pages_text if page)

    @property
    def has_text_layer(self) -> bool:
        return any(self.pages_text)


def extract_text(data: bytes, fmt: DocumentFormat, settings: Settings) -> ExtractedText:
    # импорт внутри функции: читатели тянут тяжёлые библиотеки, а модуль используется и в схемах
    if fmt is DocumentFormat.PDF:
        from app.extraction.pdf_reader import read_pdf

        return read_pdf(data, settings)
    if fmt is DocumentFormat.DOCX:
        from app.extraction.docx_reader import read_docx

        return read_docx(data, settings)
    # изображения: текста нет до подключения OCR
    return ExtractedText(format=fmt, pages_text=("",), pages=1, pages_without_text=1)
