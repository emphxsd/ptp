"""Определение формата по содержимому файла (сигнатуре), а не по расширению имени.
Сначала проверяются точные префиксы (PNG, JPEG, ZIP), PDF — последним: так файл-полиглот
с маркером %PDF- внутри не будет принят за PDF."""
import io
import zipfile
from enum import Enum

from app.errors import EmptyFileError, UnsupportedFormatError

PDF_SIGNATURE = b"%PDF-"
ZIP_SIGNATURE = b"PK\x03\x04"
PNG_SIGNATURE = b"\x89PNG\r\n\x1a\n"
JPEG_SIGNATURE = b"\xff\xd8\xff"
# стандарт PDF допускает «мусор» перед заголовком в пределах первых 1024 байт
PDF_HEADER_SEARCH_LIMIT = 1024

DOCX_MAIN_PART = "word/document.xml"
DOCX_CONTENT_TYPES = "[Content_Types].xml"
DOCX_MAIN_CONTENT_TYPE = b"wordprocessingml.document.main+xml"
MAX_CONTENT_TYPES_BYTES = 1024 * 1024

# запись End Of Central Directory: сигнатура, число записей по смещению 10 (2 байта)
EOCD_SIGNATURE = b"PK\x05\x06"
EOCD_SIZE = 22
EOCD_MAX_COMMENT = 65535
EOCD_ENTRIES_OFFSET = 10
# жёсткий предел числа записей ещё до разбора каталога архива
HARD_MAX_ZIP_ENTRIES = 10_000


class DocumentFormat(str, Enum):
    PDF = "pdf"
    DOCX = "docx"
    PNG = "png"
    JPG = "jpg"


def detect_format(data: bytes) -> DocumentFormat:
    if not data:
        raise EmptyFileError("Файл пустой")
    if data.startswith(PNG_SIGNATURE):
        return DocumentFormat.PNG
    if data.startswith(JPEG_SIGNATURE):
        return DocumentFormat.JPG
    if data.startswith(ZIP_SIGNATURE):
        if _is_docx(data):
            return DocumentFormat.DOCX
        raise UnsupportedFormatError("ZIP-архив не является документом DOCX")
    if PDF_SIGNATURE in data[:PDF_HEADER_SEARCH_LIMIT]:
        return DocumentFormat.PDF
    raise UnsupportedFormatError("Поддерживаются только PDF, DOCX, PNG и JPG")


def zip_entry_count(data: bytes) -> int | None:
    """Число записей из EOCD — без разбора всего каталога (дёшево и безопасно)."""
    tail = data[-(EOCD_SIZE + EOCD_MAX_COMMENT):]
    pos = tail.rfind(EOCD_SIGNATURE)
    if pos < 0 or pos + EOCD_SIZE > len(tail):
        return None
    start = pos + EOCD_ENTRIES_OFFSET
    return int.from_bytes(tail[start:start + 2], "little")


def _is_docx(data: bytes) -> bool:
    entries = zip_entry_count(data)
    if entries is None or entries > HARD_MAX_ZIP_ENTRIES:
        return False
    try:
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            names = set(archive.namelist())
            if DOCX_MAIN_PART not in names or DOCX_CONTENT_TYPES not in names:
                return False
            info = archive.getinfo(DOCX_CONTENT_TYPES)
            if info.file_size > MAX_CONTENT_TYPES_BYTES:
                return False
            return DOCX_MAIN_CONTENT_TYPE in archive.read(info)
    except (zipfile.BadZipFile, NotImplementedError, RuntimeError, ValueError, OSError, EOFError):
        return False
