"""Юнит-тесты конвейера и настроек — без HTTP-слоя."""
import pytest
from pydantic import ValidationError

from app.config import Settings
from app.errors import EmptyDocumentError, FileTooLargeError, NoTextLayerError, PasswordProtectedError
from app.pipeline import process_document
from app.schemas import Template
from tests.conftest import make_docx, make_encrypted_pdf

TEMPLATE = Template.model_validate(
    {"fields": [{"name": "inn", "label": "ИНН", "is_required": True}, {"name": "note", "label": "Примечание"}]}
)


def test_process_document_returns_fields_in_template_order(invoice_pdf):
    result = process_document(invoice_pdf, TEMPLATE, Settings())

    assert [f.name for f in result.fields] == ["inn", "note"]
    assert result.document.format == "pdf"
    assert result.text is None


def test_process_document_can_include_text(invoice_docx):
    result = process_document(invoice_docx, TEMPLATE, Settings(), include_text=True)

    assert "Договор поставки" in result.text


def test_process_document_checks_size(invoice_pdf):
    with pytest.raises(FileTooLargeError):
        process_document(invoice_pdf, TEMPLATE, Settings(max_file_bytes=10))


def test_scan_raises_no_text_layer(scanned_pdf):
    with pytest.raises(NoTextLayerError):
        process_document(scanned_pdf, TEMPLATE, Settings())


def test_image_raises_no_text_layer(png_image):
    with pytest.raises(NoTextLayerError):
        process_document(png_image, TEMPLATE, Settings())


def test_empty_docx_is_reported_as_empty_not_as_scan():
    with pytest.raises(EmptyDocumentError):
        process_document(make_docx([]), TEMPLATE, Settings())


def test_password_protected_pdf_propagates():
    with pytest.raises(PasswordProtectedError):
        process_document(make_encrypted_pdf(), TEMPLATE, Settings())


# ---------------------------------------------------------------- настройки
@pytest.mark.parametrize(
    "overrides",
    [{"max_file_bytes": 0}, {"max_file_bytes": -5}, {"max_pages": 0}, {"min_chars_per_page": -1}, {"max_text_chars": 0}],
)
def test_invalid_settings_are_rejected(overrides):
    with pytest.raises(ValidationError):
        Settings(**overrides)


def test_settings_from_env(monkeypatch):
    monkeypatch.setenv("PROCESSOR_MAX_FILE_MB", "5")
    monkeypatch.setenv("PROCESSOR_MAX_PAGES", "50")
    monkeypatch.setenv("PROCESSOR_API_TOKEN", "abc")
    monkeypatch.setenv("PROCESSOR_DOCS_ENABLED", "false")

    settings = Settings.from_env()

    assert settings.max_file_bytes == 5 * 1024 * 1024
    assert settings.max_pages == 50
    assert settings.api_token == "abc"
    assert settings.docs_enabled is False


@pytest.mark.parametrize("value", ["abc", "nan", "inf", "-1", "0"])
def test_invalid_env_fails_fast(monkeypatch, value):
    monkeypatch.setenv("PROCESSOR_MAX_FILE_MB", value)

    with pytest.raises((ValidationError, ValueError)):
        Settings.from_env()
