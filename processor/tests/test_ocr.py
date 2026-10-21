"""Шаг 6: OCR сканов. Tesseract вызывается как внешняя программа; в тестах — подставной запуск,
реальный Tesseract — только в интеграционном тесте (пропускается, если программа не установлена)."""
import struct
import subprocess
import zlib
from dataclasses import dataclass, field as dc_field

import pymupdf
import pytest

from app.config import Settings
from app.errors import DocumentReadError, NoTextLayerError, OcrFailedError, UnsafeDocumentError
from app.extraction.file_types import DocumentFormat
from app.extraction.images import image_size
from app.extraction.ocr import (
    OcrFailedRunError,
    OcrResult,
    OcrTimeoutError,
    OcrUnavailableError,
    TesseractEngine,
    check_tesseract,
    parse_tsv,
)
from app.extraction.ocr_stage import apply_ocr
from app.extraction.text import extract_text
from app.pipeline import process_document
from app.schemas import Template
from tests.conftest import make_mixed_pdf, make_png, make_scanned_pdf

TSV = (
    "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n"
    "1\t1\t0\t0\t0\t0\t0\t0\t1000\t1000\t-1\t\n"
    "5\t1\t1\t1\t1\t1\t10\t10\t50\t20\t96\tСчёт\n"
    "5\t1\t1\t1\t1\t2\t70\t10\t30\t20\t90\t№\n"
    "5\t1\t1\t1\t1\t3\t110\t10\t20\t20\t94\t15\n"
    "4\t1\t1\t1\t2\t0\t10\t40\t200\t20\t-1\t\n"
    "5\t1\t1\t1\t2\t1\t10\t40\t40\t20\t88\tИНН\n"
    "5\t1\t1\t1\t2\t2\t60\t40\t100\t20\t92\t7707083893\n"
    "5\t1\t2\t1\t1\t1\t10\t80\t40\t20\t80\tИтого:\n"
    "5\t1\t2\t1\t1\t2\t60\t80\t40\t20\t-1\t \n"
)


# ---------------------------------------------------------------- разбор вывода Tesseract
def test_tsv_is_assembled_into_lines_with_mean_confidence():
    result = parse_tsv(TSV)

    assert result.text == "Счёт № 15\nИНН 7707083893\nИтого:"
    assert result.confidence == pytest.approx((96 + 90 + 94 + 88 + 92 + 80) / 6)


def test_empty_tsv_gives_empty_text():
    result = parse_tsv("level\tpage_num\n")

    assert result.text == ""
    assert result.confidence is None


# ---------------------------------------------------------------- запуск Tesseract
@dataclass
class FakeRunner:
    stdout: bytes = TSV.encode()
    returncode: int = 0
    error: Exception | None = None
    calls: list = dc_field(default_factory=list)

    def __call__(self, args, **kwargs):
        self.calls.append((args, kwargs))
        if self.error is not None:
            raise self.error
        return subprocess.CompletedProcess(args, self.returncode, stdout=self.stdout, stderr=b"")


def test_tesseract_command_is_safe_and_bounded():
    runner = FakeRunner()
    engine = TesseractEngine("tesseract", "rus+eng", runner=runner)

    result = engine.recognize(b"png-bytes", timeout=7.5)

    (args, kwargs), = runner.calls
    assert args == ["tesseract", "stdin", "stdout", "-l", "rus+eng", "--psm", "3", "tsv"]
    assert kwargs["input"] == b"png-bytes"
    assert kwargs["timeout"] == 7.5
    assert kwargs.get("shell", False) is False
    assert kwargs["env"]["OMP_THREAD_LIMIT"] == "1"  # Tesseract не захватывает все ядра
    assert result.text.startswith("Счёт № 15")


@pytest.mark.parametrize(
    ("runner", "error"),
    [
        (FakeRunner(error=FileNotFoundError()), OcrUnavailableError),
        (FakeRunner(error=subprocess.TimeoutExpired("tesseract", 1)), OcrTimeoutError),
        (FakeRunner(returncode=1), OcrFailedRunError),
    ],
    ids=["not-installed", "timeout", "failed"],
)
def test_tesseract_errors_are_mapped(runner, error):
    with pytest.raises(error):
        TesseractEngine("tesseract", "rus", runner=runner).recognize(b"x", timeout=1)


def test_invalid_utf8_output_does_not_crash():
    runner = FakeRunner(stdout=TSV.encode()[:-3] + b"\xff\xfe\n")

    assert "Счёт" in TesseractEngine("tesseract", "rus", runner=runner).recognize(b"x", timeout=1).text


def test_check_tesseract_requires_all_languages():
    runner = FakeRunner(stdout=b'List of available languages in "C:/tessdata/" (3):\neng\nosd\nrus\n')

    assert check_tesseract("tesseract", "rus+eng", runner=runner) is True
    assert check_tesseract("tesseract", "rus+deu", runner=runner) is False


def test_check_tesseract_when_not_installed():
    assert check_tesseract("tesseract", "rus", runner=FakeRunner(error=FileNotFoundError())) is False


@pytest.mark.parametrize("languages", ["rus;calc", "../rus", "", "rus+"], ids=["semicolon", "path", "empty", "trailing"])
def test_unsafe_languages_setting_rejected(languages):
    with pytest.raises(ValueError):
        Settings(ocr_languages=languages)


# ---------------------------------------------------------------- размеры изображения из заголовка
def _png_header(width: int, height: int) -> bytes:
    ihdr = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
    chunk = struct.pack(">I", len(ihdr)) + b"IHDR" + ihdr + struct.pack(">I", zlib.crc32(b"IHDR" + ihdr))
    return b"\x89PNG\r\n\x1a\n" + chunk


def _jpeg(width: int, height: int) -> bytes:
    pix = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, width, height), False)
    pix.clear_with(255)
    return pix.tobytes("jpeg")


def test_image_size_png_and_jpeg():
    assert image_size(make_png(), DocumentFormat.PNG) == (10, 10)
    assert image_size(_jpeg(40, 30), DocumentFormat.JPG) == (40, 30)


@pytest.mark.parametrize(
    ("data", "fmt"),
    [(b"\x89PNG\r\n\x1a\n" + b"\x00" * 5, DocumentFormat.PNG), (b"\xff\xd8\xff\xe0\x00\x10JFIF", DocumentFormat.JPG)],
    ids=["png-truncated", "jpeg-no-sof"],
)
def test_broken_image_header_is_read_error(data, fmt):
    with pytest.raises(DocumentReadError):
        image_size(data, fmt)


# ---------------------------------------------------------------- OCR в составе извлечения текста
@dataclass
class FakeOcr:
    """Возвращает тексты по очереди и запоминает размер переданных изображений."""

    texts: list = dc_field(default_factory=lambda: ["ИНН 7707083893"])
    confidence: float = 91.0
    images: list = dc_field(default_factory=list)

    def recognize(self, image: bytes, timeout: float) -> OcrResult:
        self.images.append(image)
        item = self.texts[min(len(self.images), len(self.texts)) - 1]
        if isinstance(item, Exception):
            raise item
        return OcrResult(text=item, confidence=self.confidence)


def ocr(data, fmt, engine, **settings):
    s = Settings(**settings)
    return apply_ocr(data, extract_text(data, fmt, s), s, engine)


def test_scanned_pdf_pages_are_recognized():
    engine = FakeOcr(texts=["Страница один", "Страница два"])

    extracted, warnings = ocr(make_scanned_pdf(pages=2), DocumentFormat.PDF, engine)

    assert extracted.pages_text == ("Страница один", "Страница два")
    assert extracted.ocr_pages == 2
    assert extracted.ocr_confidence == 91.0
    assert warnings == ()
    assert all(image.startswith(b"\x89PNG") for image in engine.images)


def test_only_pages_without_text_layer_are_recognized():
    engine = FakeOcr(texts=["скан"])

    extracted, _ = ocr(make_mixed_pdf("Текстовая страница с достаточным количеством букв", 1), DocumentFormat.PDF, engine)

    assert len(engine.images) == 1
    assert extracted.pages_text[0].startswith("Текстовая страница")
    assert extracted.pages_text[1] == "скан"
    assert extracted.ocr_pages == 1


def test_image_is_sent_to_ocr_as_is():
    png = make_png()
    engine = FakeOcr()

    extracted, _ = ocr(png, DocumentFormat.PNG, engine)

    assert engine.images == [png]
    assert extracted.pages_text == ("ИНН 7707083893",)


def test_ocr_text_is_normalized():
    extracted, _ = ocr(make_png(), DocumentFormat.PNG, FakeOcr(texts=["ООО «Ромашка» — поставщик"]))

    assert extracted.pages_text == ('ООО "Ромашка" - поставщик',)


def test_page_limit_for_ocr():
    engine = FakeOcr(texts=["стр"])

    extracted, warnings = ocr(make_scanned_pdf(pages=3), DocumentFormat.PDF, engine, ocr_max_pages=2)

    assert len(engine.images) == 2
    assert extracted.pages_text == ("стр", "стр", "")
    assert warnings == ("OCR_PAGES_TRUNCATED",)


def test_failed_page_is_skipped_with_warning():
    engine = FakeOcr(texts=["первая", OcrTimeoutError()])

    extracted, warnings = ocr(make_scanned_pdf(pages=2), DocumentFormat.PDF, engine)

    assert extracted.pages_text == ("первая", "")
    assert warnings == ("OCR_PAGE_FAILED",)


def test_all_pages_failed_raises():
    with pytest.raises(OcrFailedError):
        ocr(make_scanned_pdf(pages=1), DocumentFormat.PDF, FakeOcr(texts=[OcrFailedRunError()]))


def test_low_ocr_confidence_is_reported():
    _, warnings = ocr(make_png(), DocumentFormat.PNG, FakeOcr(confidence=40.0))

    assert warnings == ("LOW_OCR_QUALITY",)


def test_huge_image_is_rejected_before_ocr():
    engine = FakeOcr()

    with pytest.raises(UnsafeDocumentError):
        ocr(_png_header(20_000, 20_000), DocumentFormat.PNG, engine)
    assert engine.images == []


def test_render_resolution_is_capped_by_pixel_limit():
    engine = FakeOcr()

    ocr(make_scanned_pdf(pages=1), DocumentFormat.PDF, engine, ocr_dpi=600, ocr_max_pixels=1_000_000)

    pix = pymupdf.Pixmap(engine.images[0])
    assert pix.width * pix.height <= 1_000_000


# ---------------------------------------------------------------- в составе конвейера
TEMPLATE = Template.model_validate(
    {"fields": [{"name": "inn", "label": "ИНН", "data_type": "inn", "keywords": ["ИНН"]}]}
)


def test_pipeline_extracts_fields_from_scan():
    result = process_document(make_scanned_pdf(), TEMPLATE, Settings(ner_enabled=False), ocr=FakeOcr())

    assert result.fields[0].value == "7707083893"
    assert result.document.ocr_pages == 1
    assert result.document.has_text_layer is False


def test_low_ocr_quality_marks_fields_for_review():
    result = process_document(make_png(), TEMPLATE, Settings(ner_enabled=False), ocr=FakeOcr(confidence=40.0))

    assert result.fields[0].value == "7707083893"
    assert "LOW_OCR_QUALITY" in result.fields[0].issues
    assert result.fields[0].needs_review is True
    assert "LOW_OCR_QUALITY" in result.warnings


def test_scan_without_ocr_engine_still_reports_no_text_layer():
    with pytest.raises(NoTextLayerError):
        process_document(make_scanned_pdf(), TEMPLATE, Settings(ner_enabled=False))


def test_ocr_disabled_in_settings():
    with pytest.raises(NoTextLayerError):
        process_document(make_png(), TEMPLATE, Settings(ner_enabled=False, ocr_enabled=False), ocr=FakeOcr())


def test_empty_ocr_result_reports_no_text():
    with pytest.raises(NoTextLayerError):
        process_document(make_png(), TEMPLATE, Settings(ner_enabled=False), ocr=FakeOcr(texts=["  "]))


# ---------------------------------------------------------------- запуск сервиса и API
def test_health_reports_ocr_status(monkeypatch):
    from fastapi.testclient import TestClient

    from app.main import create_app

    monkeypatch.setattr("app.main.check_tesseract", lambda cmd, languages: False)
    with TestClient(create_app(Settings(ner_enabled=False, ocr_enabled=True))) as client:
        assert client.get("/health").json()["data"]["ocr"] == "unavailable"
        assert client.get("/health").json()["data"]["status"] == "degraded"

    monkeypatch.setattr("app.main.check_tesseract", lambda cmd, languages: True)
    app = create_app(Settings(ner_enabled=False, ocr_enabled=True))
    with TestClient(app) as client:
        assert client.get("/health").json()["data"]["ocr"] == "ready"
        assert isinstance(app.state.ocr_engine, TesseractEngine)


def test_extract_endpoint_uses_ocr_engine_from_app_state():
    from fastapi.testclient import TestClient

    from app.main import create_app
    from tests.conftest import post_extract

    template = '{"fields": [{"name": "inn", "label": "ИНН", "data_type": "inn", "keywords": ["ИНН"]}]}'
    app = create_app(Settings(ner_enabled=False, ocr_enabled=True))
    with TestClient(app) as client:
        app.state.ocr_engine = FakeOcr()
        body = post_extract(client, make_scanned_pdf(), template).json()["data"]

    assert body["fields"][0]["value"] == "7707083893"
    assert body["document"]["ocr_pages"] == 1


def test_ocr_settings_from_env():
    settings = Settings.from_env(
        {"PROCESSOR_TESSERACT_CMD": r"C:\Program Files\Tesseract-OCR\tesseract.exe", "PROCESSOR_OCR_DPI": "200",
         "PROCESSOR_OCR_ENABLED": "false"}
    )

    assert settings.tesseract_cmd.endswith("tesseract.exe")
    assert settings.ocr_dpi == 200
    assert settings.ocr_enabled is False


# ---------------------------------------------------------------- исправления по итогам ревью
def test_page_that_fails_to_render_is_skipped(monkeypatch):
    import app.extraction.ocr_stage as stage

    original = stage._render_page

    def flaky(data, index, settings):
        if index == 1:
            raise DocumentReadError("битая страница")
        return original(data, index, settings)

    monkeypatch.setattr(stage, "_render_page", flaky)
    engine = FakeOcr(texts=["одна", "три"])

    extracted, warnings = ocr(make_scanned_pdf(pages=3), DocumentFormat.PDF, engine)

    assert extracted.pages_text == ("одна", "", "три")
    assert warnings == ("OCR_PAGE_FAILED",)


def test_deadline_is_checked_before_rendering_next_page(monkeypatch):
    import app.extraction.ocr_stage as stage

    clock = {"now": 0.0}
    monkeypatch.setattr(stage.time, "monotonic", lambda: clock["now"])
    rendered = []
    monkeypatch.setattr(stage, "_render_page", lambda data, index, settings: rendered.append(index) or b"png")

    class SlowOcr:
        def recognize(self, image, timeout):
            clock["now"] += 30
            return OcrResult("текст", 90.0)

    _, warnings = ocr(make_scanned_pdf(pages=3), DocumentFormat.PDF, SlowOcr(), ocr_timeout_seconds=20)

    assert rendered == [0]
    assert warnings == ("OCR_PAGE_FAILED",)


def test_os_error_starting_tesseract_is_unavailable():
    runner = FakeRunner(error=PermissionError("нет доступа"))

    with pytest.raises(OcrUnavailableError):
        TesseractEngine("tesseract", "rus", runner=runner).recognize(b"x", timeout=1)


def test_languages_with_newline_rejected_by_engine():
    with pytest.raises(ValueError):
        TesseractEngine("tesseract", "rus\n")


def test_incomplete_ocr_marks_found_and_missing_fields_for_review():
    template = Template.model_validate(
        {
            "fields": [
                {"name": "inn", "label": "ИНН", "data_type": "inn", "keywords": ["ИНН"]},
                {"name": "kpp", "label": "КПП", "data_type": "kpp", "keywords": ["КПП"]},
            ]
        }
    )

    result = process_document(
        make_scanned_pdf(pages=3), template, Settings(ner_enabled=False, ocr_max_pages=1), ocr=FakeOcr()
    )
    fields = {f.name: f for f in result.fields}

    assert "OCR_PAGES_TRUNCATED" in result.warnings
    assert fields["inn"].issues == ("INCOMPLETE_OCR",)
    assert fields["kpp"].issues == ("INCOMPLETE_OCR",)
    assert result.needs_review is True


def test_mixed_pdf_without_ocr_warns_about_skipped_pages():
    template = Template.model_validate({"fields": [{"name": "x", "label": "x"}]})

    result = process_document(make_mixed_pdf("Текстовая страница с достаточным количеством букв", 1), template, Settings(ner_enabled=False))

    assert "OCR_UNAVAILABLE" in result.warnings


def test_jpeg_with_fake_unprefixed_sof_is_rejected():
    # поддельный SOF без 0xFF перед маркером, а настоящий FF C0 объявляет 65534×65534: libjpeg пропустит мусор
    # до настоящего маркера, поэтому заголовок без 0xFF перед маркером считается повреждённым
    fake = bytes.fromhex("ffd8" "c0001108001000100301220002110103110 1".replace(" ", ""))
    real = bytes.fromhex("ffc0001108fffefffe0301220002110103110 1".replace(" ", ""))

    with pytest.raises(DocumentReadError):
        image_size(fake + real + bytes.fromhex("ffd9"), DocumentFormat.JPG)


def test_jpeg_size_is_the_largest_declared_frame():
    small = _jpeg(40, 30)
    big_sof = b"\xff\xc0\x00\x11\x08\x10\x00\x20\x00\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01"
    data = small[:-2] + big_sof + b"\xff\xd9"

    assert image_size(data, DocumentFormat.JPG) == (0x2000, 0x1000)


def test_tesseract_gets_minimal_environment(monkeypatch):
    monkeypatch.setenv("PROCESSOR_GIGACHAT_AUTH_KEY", "secret-key")
    runner = FakeRunner()

    TesseractEngine("tesseract", "rus", runner=runner).recognize(b"x", timeout=1)

    env = runner.calls[0][1]["env"]
    assert "PROCESSOR_GIGACHAT_AUTH_KEY" not in env
    assert env["OMP_THREAD_LIMIT"] == "1"


def test_unavailable_tesseract_stops_after_first_page():
    engine = FakeOcr(texts=[OcrUnavailableError()])

    with pytest.raises(OcrFailedError):
        ocr(make_scanned_pdf(pages=3), DocumentFormat.PDF, engine)
    assert len(engine.images) == 1


def test_ocr_respects_remaining_document_budget():
    seen = []

    class RecordingOcr:
        def recognize(self, image, timeout):
            seen.append(timeout)
            return OcrResult("ИНН 7707083893", 90.0)

    process_document(
        make_scanned_pdf(), TEMPLATE, Settings(ner_enabled=False, ocr_timeout_seconds=20, processing_budget_seconds=5),
        ocr=RecordingOcr(),
    )

    assert seen and seen[0] <= 5
