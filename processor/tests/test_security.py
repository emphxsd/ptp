"""Защиты HTTP-слоя: лимит тела запроса, аутентификация, единый конверт ошибок, разбор шаблона."""
import pytest
from fastapi.testclient import TestClient

from app.config import Settings
from app.main import create_app
from tests.conftest import make_client, post_extract

TOKEN = "test-secret-token"


# ---------------------------------------------------------------- лимит тела запроса
def test_body_with_too_large_content_length_is_rejected_before_parsing(template_json):
    with make_client(max_file_bytes=1024) as client:
        response = client.post(
            "/extract",
            content=b"x" * 400_000,
            headers={"content-type": "multipart/form-data; boundary=abc"},
        )

    assert response.status_code == 413
    assert response.json()["error"]["code"] == "FILE_TOO_LARGE"


def test_chunked_body_over_limit_is_rejected(template_json):
    def chunks():
        for _ in range(100):
            yield b"x" * 10_000

    with make_client(max_file_bytes=1024) as client:
        response = client.post(
            "/extract",
            content=chunks(),
            headers={"content-type": "multipart/form-data; boundary=abc"},
        )

    assert response.status_code == 413
    assert response.json()["error"]["code"] == "FILE_TOO_LARGE"


# ---------------------------------------------------------------- аутентификация
@pytest.fixture
def secured_client():
    with make_client(api_token=TOKEN) as client:
        yield client


def test_extract_requires_token_when_configured(secured_client, invoice_pdf, template_json):
    response = post_extract(secured_client, invoice_pdf, template_json)

    assert response.status_code == 401
    assert response.json()["error"]["code"] == "UNAUTHORIZED"


def test_extract_rejects_wrong_token(secured_client, invoice_pdf, template_json):
    response = post_extract(secured_client, invoice_pdf, template_json, headers={"Authorization": "Bearer wrong"})

    assert response.status_code == 401


def test_extract_accepts_correct_token(secured_client, invoice_pdf, template_json):
    response = post_extract(secured_client, invoice_pdf, template_json, headers={"Authorization": f"Bearer {TOKEN}"})

    assert response.status_code == 200


def test_health_does_not_require_token(secured_client):
    assert secured_client.get("/health").status_code == 200


def test_docs_can_be_disabled():
    with make_client(docs_enabled=False) as client:
        assert client.get("/docs").status_code == 404
        assert client.get("/openapi.json").status_code == 404


# ---------------------------------------------------------------- единый конверт ошибок
def test_unknown_route_returns_envelope(client):
    response = client.get("/no-such-route")

    assert response.status_code == 404
    assert response.json() == {"success": False, "data": None, "error": {"code": "NOT_FOUND", "message": "Not Found"}}


def test_wrong_method_returns_envelope(client):
    response = client.get("/extract")

    assert response.status_code == 405
    assert response.json()["error"]["code"] == "METHOD_NOT_ALLOWED"


def test_unexpected_error_returns_500_envelope_without_details(monkeypatch, invoice_pdf, template_json):
    def boom(*_args, **_kwargs):
        raise RuntimeError("ИНН 7701234567 утёк в сообщение об ошибке")

    monkeypatch.setattr("app.api.routes.process_document", boom)
    with TestClient(create_app(Settings()), raise_server_exceptions=False) as client:
        response = post_extract(client, invoice_pdf, template_json)

    assert response.status_code == 500
    assert response.json()["error"] == {"code": "INTERNAL_ERROR", "message": "Внутренняя ошибка сервиса"}
    assert "7701234567" not in response.text


# ---------------------------------------------------------------- разбор шаблона
@pytest.mark.parametrize(
    "template",
    [
        "[" * 100_000,  # глубокая вложенность JSON
        '{"fields": []}',
        "null",
        '{"fields": [{"name": "inn", "label": "ИНН", "unknown": 1}]}',
        '{"fields": [{"name": "Bad Name", "label": "ИНН"}]}',
        '{"fields": [{"name": "inn", "label": "ИНН", "keywords": ["' + "к" * 101 + '"]}]}',
        '{"fields": [{"name": "inn", "label": "ИНН", "keywords": [""]}]}',
        '{"fields": [{"name": "inn", "label": "ИНН", "ner_label": "WEAPON"}]}',
        '{"fields": [{"name": "inn", "label": "ИНН\\u0000"}]}',
        '{"fields": [' + ",".join(f'{{"name": "f{i}", "label": "x"}}' for i in range(101)) + "]}",
    ],
    ids=[
        "deep-json",
        "no-fields",
        "null",
        "extra-key",
        "bad-name",
        "long-keyword",
        "empty-keyword",
        "bad-ner-label",
        "control-char",
        "too-many-fields",
    ],
)
def test_invalid_templates_are_rejected(client, invoice_pdf, template):
    response = post_extract(client, invoice_pdf, template)

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_TEMPLATE"


def test_template_over_size_limit_is_rejected(client, invoice_pdf):
    huge = '{"name": "' + "x" * 70_000 + '", "fields": [{"name": "a", "label": "b"}]}'

    response = post_extract(client, invoice_pdf, huge)

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_TEMPLATE"


def test_known_ner_label_is_accepted(client, invoice_pdf):
    template = '{"fields": [{"name": "boss", "label": "Директор", "data_type": "person", "ner_label": "PER"}]}'

    assert post_extract(client, invoice_pdf, template).status_code == 200
