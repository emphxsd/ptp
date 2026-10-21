import io
import zipfile

import pytest

from app.errors import EmptyFileError, UnsupportedFormatError
from app.extraction.file_types import DocumentFormat, detect_format
from tests.conftest import add_docx_entries, make_docx, make_pdf, make_png, make_zip_without_docx


def test_detects_pdf_by_signature():
    assert detect_format(make_pdf(["текст"])) is DocumentFormat.PDF


def test_detects_pdf_with_short_garbage_before_header():
    assert detect_format(b"\r\n" + make_pdf(["текст"])) is DocumentFormat.PDF


def test_detects_docx_by_zip_content():
    assert detect_format(make_docx(["текст"])) is DocumentFormat.DOCX


def test_detects_png():
    assert detect_format(make_png()) is DocumentFormat.PNG


def test_detects_jpeg():
    assert detect_format(b"\xff\xd8\xff\xe0" + b"\x00" * 20) is DocumentFormat.JPG


def test_png_containing_pdf_marker_is_still_png():
    polyglot = make_png() + b"%PDF-1.7 hidden"
    assert detect_format(polyglot) is DocumentFormat.PNG


def test_docx_containing_pdf_marker_is_still_docx():
    polyglot = add_docx_entries(make_docx(["текст"]), {"%PDF-1.7.txt": b"x"})
    assert detect_format(polyglot) is DocumentFormat.DOCX


def test_rejects_zip_that_is_not_docx():
    with pytest.raises(UnsupportedFormatError):
        detect_format(make_zip_without_docx())


def test_rejects_zip_with_document_xml_but_without_word_content_type():
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        zf.writestr("word/document.xml", "<x/>")
        zf.writestr("[Content_Types].xml", "<Types/>")
    with pytest.raises(UnsupportedFormatError):
        detect_format(buf.getvalue())


def test_rejects_unknown_bytes_even_with_misleading_content():
    with pytest.raises(UnsupportedFormatError):
        detect_format(b"MZ\x90\x00 executable pretending to be a document")


def test_rejects_legacy_doc_format():
    ole_header = b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" + b"\x00" * 50
    with pytest.raises(UnsupportedFormatError):
        detect_format(ole_header)


def test_rejects_empty_file():
    with pytest.raises(EmptyFileError):
        detect_format(b"")
