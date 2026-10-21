"""Размеры изображения из заголовка файла — без декодирования (защита от «бомб» вида 20000×20000)."""
import struct

from app.errors import DocumentReadError
from app.extraction.file_types import DocumentFormat

_PNG_IHDR_END = 24
# маркеры начала кадра JPEG (SOF0–SOF15, кроме DHT, JPG и DAC)
_JPEG_SOF = frozenset(range(0xC0, 0xD0)) - {0xC4, 0xC8, 0xCC}
# маркеры без длины сегмента: RST0–RST7, SOI, TEM
_JPEG_STANDALONE = frozenset(range(0xD0, 0xD9)) | {0x01}
_JPEG_SOS, _JPEG_EOI = 0xDA, 0xD9
MAX_JPEG_SEGMENTS = 1000


def image_size(data: bytes, fmt: DocumentFormat) -> tuple[int, int]:
    """(ширина, высота) в пикселях; DocumentReadError — заголовок повреждён."""
    size = _png_size(data) if fmt is DocumentFormat.PNG else _jpeg_size(data)
    if size is None or size[0] <= 0 or size[1] <= 0:
        raise DocumentReadError("Не удалось прочитать изображение: файл повреждён")
    return size


def _png_size(data: bytes) -> tuple[int, int] | None:
    if len(data) < _PNG_IHDR_END or data[12:16] != b"IHDR":
        return None
    width, height = struct.unpack(">II", data[16:24])
    return width, height


def _jpeg_size(data: bytes) -> tuple[int, int] | None:
    """Строгий разбор сегментов (каждый маркер — после 0xFF), затем наибольший из всех объявленных кадров:
    декодер пропускает «мусор» до следующего маркера, и подложенный маленький кадр не должен скрыть большой."""
    first = _first_frame(data)
    if first is None:
        return None
    frames = [first, *_declared_frames(data)]
    return max(width for width, _ in frames), max(height for _, height in frames)


def _first_frame(data: bytes) -> tuple[int, int] | None:
    position = 2  # после SOI
    for _ in range(MAX_JPEG_SEGMENTS):
        if position >= len(data) or data[position] != 0xFF:
            return None
        while position < len(data) and data[position] == 0xFF:  # байты-заполнители перед маркером
            position += 1
        if position >= len(data):
            return None
        marker = data[position]
        position += 1
        if marker in _JPEG_STANDALONE:
            continue
        if marker in (_JPEG_SOS, _JPEG_EOI) or position + 2 > len(data):
            return None
        (length,) = struct.unpack(">H", data[position:position + 2])
        if marker in _JPEG_SOF:
            return _frame_size(data, position)
        if length < 2:
            return None
        position += length
    return None


def _declared_frames(data: bytes) -> list[tuple[int, int]]:
    """Все последовательности FF C0–FF CF с корректной длиной сегмента. В сжатых данных 0xFF
    экранируется (FF 00), поэтому ложные совпадения возможны только в таблицах — это лишь завышает размер."""
    frames = []
    for marker in _JPEG_SOF:
        needle = bytes((0xFF, marker))
        position = data.find(needle)
        while position >= 0 and len(frames) < MAX_JPEG_SEGMENTS:
            size = _frame_size(data, position + 2)
            if size is not None:
                frames.append(size)
            position = data.find(needle, position + 2)
    return frames


def _frame_size(data: bytes, position: int) -> tuple[int, int] | None:
    """position — начало длины сегмента SOF: длина(2), точность(1), высота(2), ширина(2), компоненты(1)."""
    if position + 8 > len(data):
        return None
    length, _precision, height, width, components = struct.unpack(">HBHHB", data[position:position + 8])
    if components not in (1, 3, 4) or length != 8 + 3 * components:
        return None
    return width, height
