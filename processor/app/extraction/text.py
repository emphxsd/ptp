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
    # страницы, текст которых получен распознаванием (OCR), и средняя уверенность Tesseract (0–100)
    ocr_page_indexes: tuple[int, ...] = ()
    ocr_confidence: float | None = None

    @property
    def text(self) -> str:
        return PAGE_SEPARATOR.join(page for page in self.pages_text if page)

    @property
    def has_text(self) -> bool:
        return any(self.pages_text)

    @property
    def has_text_layer(self) -> bool:
        """Есть ли текст в самом файле (без учёта распознанных страниц)."""
        ocr = set(self.ocr_page_indexes)
        return any(page for index, page in enumerate(self.pages_text) if index not in ocr)

    @property
    def ocr_pages(self) -> int:
        return len(self.ocr_page_indexes)


def extract_text(data: bytes, fmt: DocumentFormat, settings: Settings) -> ExtractedText:
    # импорт внутри функции: читатели тянут тяжёлые библиотеки, а модуль используется и в схемах
    if fmt is DocumentFormat.PDF:
        from app.extraction.pdf_reader import read_pdf

        return read_pdf(data, settings)
    if fmt is DocumentFormat.DOCX:
        from app.extraction.docx_reader import read_docx

        return read_docx(data, settings)
    # изображения: текст появляется только после OCR (ocr_stage.apply_ocr)
    return ExtractedText(format=fmt, pages_text=("",), pages=1, pages_without_text=1)
