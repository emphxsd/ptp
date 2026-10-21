"""Защиты этапа правил по итогам аудита безопасности: сложность RE2, квадратичные нормализаторы,
размер ответа, логирование без ПДн, режим production."""
import logging
import time

import pytest
from fastapi.testclient import TestClient
from pydantic import ValidationError

from app.config import Settings
from app.fields.normalizers import normalize_organization
from app.fields.rules import MAX_RAW_CHARS, USER_REGEX_SCAN_CHARS, find_by_rules
from app.fields.user_regex import UserRegexError, compile_user_regex
from app.main import create_app
from app.schemas import Template, TemplateField
from tests.conftest import post_extract


def field(data_type="string", keywords=(), **extra) -> TemplateField:
    return TemplateField(name="f", label="f", data_type=data_type, keywords=keywords, **extra)


# ---------------------------------------------------------------- сложность RE2
@pytest.mark.parametrize("heavy", [r"(\pL?){300}x", r"(?s).{0,1000}x", r"(\pL?){250}(\pN?){250}x"])
def test_regex_with_huge_program_is_rejected(heavy):
    with pytest.raises(UserRegexError):
        compile_user_regex(heavy)


def test_byte_matching_escape_is_rejected():
    with pytest.raises(UserRegexError):
        compile_user_regex(r"\C{3}")


def test_regular_patterns_are_still_accepted():
    for pattern in (r"Р/с\s*(\d{20})", r"[А-Яа-яЁё]+ [А-ЯЁ]\.", r"(\d{2}\.\d{2}\.\d{4})", r"[A-Z]{2}-\d{1,6}"):
        compile_user_regex(pattern)


def test_too_many_regex_fields_in_template_are_rejected():
    fields = [{"name": f"f{i}", "label": "x", "regex_pattern": rf"x{i}y"} for i in range(21)]

    with pytest.raises(ValidationError):
        Template.model_validate({"fields": fields})


# ---------------------------------------------------------------- линейность и размер значений
@pytest.mark.parametrize("hostile", ['a"' * 50_000 + "b", '"' * 100_000 + " x"], ids=["alternating", "quotes"])
def test_organization_normalizer_is_linear(hostile):
    started = time.perf_counter()
    normalize_organization(hostile)
    assert time.perf_counter() - started < 0.5


def test_value_longer_than_limit_is_not_returned():
    text = "слово " * 2_000
    match = find_by_rules(field(regex_pattern=r"(?s).+"), text)

    assert match is None or len(match.raw) <= MAX_RAW_CHARS


def test_user_regex_global_scan_is_bounded():
    text = "x" * (USER_REGEX_SCAN_CHARS + 1000) + " Р/с 40702810400000012345"

    assert find_by_rules(field(regex_pattern=r"Р/с\s*(\d{20})"), text) is None


def test_user_regex_in_many_keyword_windows_is_fast():
    text = ("ИНН 7707083893 " + "ж" * 100_000 + "\n") * 20  # ~2 млн символов кириллицы
    started = time.perf_counter()

    find_by_rules(field(keywords=["ИНН"], regex_pattern=r"(\d{10})"), text)

    assert time.perf_counter() - started < 1.0


# ---------------------------------------------------------------- логирование и режим production
def test_unexpected_error_text_does_not_reach_logs(monkeypatch, caplog, invoice_pdf, template_json):
    def boom(*_args, **_kwargs):
        raise RuntimeError("ИНН 7707083893 Иванов Иван")

    monkeypatch.setattr("app.api.routes.process_document", boom)
    caplog.set_level(logging.DEBUG)
    with TestClient(create_app(Settings())) as client:
        response = post_extract(client, invoice_pdf, template_json)

    assert response.status_code == 500
    assert response.json()["error"]["code"] == "INTERNAL_ERROR"
    assert "RuntimeError" in caplog.text
    assert "7707083893" not in caplog.text
    assert "Иванов" not in caplog.text


def test_production_requires_token():
    with pytest.raises(ValidationError):
        Settings(environment="production")


def test_production_disables_docs():
    with TestClient(create_app(Settings(environment="production", api_token="x" * 32))) as client:
        assert client.get("/docs").status_code == 404
        assert client.get("/openapi.json").status_code == 404


def test_environment_is_read_from_env(monkeypatch):
    monkeypatch.setenv("PROCESSOR_ENV", "production")
    monkeypatch.setenv("PROCESSOR_API_TOKEN", "x" * 32)

    assert Settings.from_env().environment == "production"
