"""Чтение DOCX: проверка архива на zip-бомбу, затем обход XML в порядке документа.

Таблицы разбираются напрямую по XML (w:tr / w:tc), а не через row.cells python-docx:
у него квадратичная сложность и зависимость от w:gridSpan, которую можно раздуть."""
import io
import zipfile

from docx import Document
from docx.opc.constants import RELATIONSHIP_TYPE as RT
from docx.text.paragraph import Paragraph

from app.config import Settings
from app.errors import DocumentReadError, ProcessingError, TextTooLargeError, UnsafeDocumentError
from app.extraction.file_types import DocumentFormat, zip_entry_count
from app.extraction.normalize import normalize_text
from app.extraction.text import ExtractedText

W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
CELL_SEPARATOR = " | "
# коэффициент сжатия проверяем только у крупных частей: маленькие XML сжимаются сильно и это нормально
RATIO_CHECK_MIN_BYTES = 1024 * 1024


class _RowBudget:
    """Общий лимит строк по всем таблицам документа."""

    def __init__(self, limit: int):
        self.left = limit

    def take(self) -> None:
        self.left -= 1
        if self.left < 0:
            raise UnsafeDocumentError("В документе слишком большие таблицы")


def read_docx(data: bytes, settings: Settings) -> ExtractedText:
    _check_archive(data, settings)
    try:
        blocks = _document_blocks(data, _RowBudget(settings.docx_max_table_rows))
    except ProcessingError:
        raise
    except Exception as exc:  # любые сбои python-docx/lxml на недоверенном вводе
        raise DocumentReadError("Не удалось прочитать DOCX: файл повреждён") from exc

    text = normalize_text("\n".join(block for block in blocks if block))
    if len(text) > settings.max_text_chars:
        raise TextTooLargeError("Документ содержит слишком много текста")
    return ExtractedText(format=DocumentFormat.DOCX, pages_text=(text,), pages=None, pages_without_text=0)


def _check_archive(data: bytes, settings: Settings) -> None:
    entries = zip_entry_count(data)
    if entries is None:
        raise DocumentReadError("Не удалось прочитать DOCX: повреждён архив")
    if entries > settings.docx_max_entries:
        raise UnsafeDocumentError("Архив DOCX содержит слишком много файлов")
    try:
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            infos = archive.infolist()
    except Exception as exc:
        raise DocumentReadError("Не удалось прочитать DOCX: повреждён архив") from exc

    unpacked = 0
    for info in infos:
        unpacked += info.file_size
        if unpacked > settings.docx_max_unpacked_bytes:
            raise UnsafeDocumentError("DOCX слишком большой после распаковки")
        if info.file_size > RATIO_CHECK_MIN_BYTES and (
            info.compress_size == 0 or info.file_size / info.compress_size > settings.docx_max_compression_ratio
        ):
            raise UnsafeDocumentError("DOCX похож на zip-бомбу")


def _document_blocks(data: bytes, budget: _RowBudget) -> list[str]:
    document = Document(io.BytesIO(data))
    part = document.part
    headers = [rel.target_part.element for rel in part.rels.values() if rel.reltype == RT.HEADER]
    footers = [rel.target_part.element for rel in part.rels.values() if rel.reltype == RT.FOOTER]

    blocks: list[str] = []
    for container in (*headers, document.element.body, *footers):
        blocks.extend(_container_lines(container, budget))
    return blocks


def _container_lines(container, budget: _RowBudget) -> list[str]:
    """Абзацы, таблицы и элементы управления содержимым (w:sdt) в порядке следования."""
    lines: list[str] = []
    for child in container.iterchildren():
        if child.tag == f"{W}p":
            lines.append(Paragraph(child, None).text)
        elif child.tag == f"{W}tbl":
            lines.extend(_table_lines(child, budget))
        elif child.tag == f"{W}sdt":
            content = child.find(f"{W}sdtContent")
            if content is not None:
                lines.extend(_container_lines(content, budget))
    return lines


def _table_lines(table, budget: _RowBudget) -> list[str]:
    lines: list[str] = []
    for row in table.iterchildren(f"{W}tr"):
        budget.take()
        # пустые ячейки сохраняем, чтобы не сдвигались столбцы; продолжения вертикального
        # объединения в XML пустые, поэтому значение не дублируется
        cells = [" ".join(line for line in _container_lines(cell, budget) if line) for cell in row.iterchildren(f"{W}tc")]
        if any(cells):
            lines.append(CELL_SEPARATOR.join(cells))
    return lines
