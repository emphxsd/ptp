"""Интеграционные тесты HTTP API: формат ответа, коды ошибок, обработка файлов."""
from tests.conftest import (
    INVOICE_TEXT_NORMALIZED,
    make_client,
    make_docx,
    make_pdf,
    post_extract,
)


def test_health(client):
    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {
        "success": True,
        "data": {"status": "ok", "ner": "ready", "llm": "not_configured", "ocr": "disabled"},
        "error": None,
    }


def test_extract_pdf_returns_document_info_and_fields(client, template_json):
    pdf = make_pdf([INVOICE_TEXT_NORMALIZED])

    response = post_extract(client, pdf, template_json)
    body = response.json()

    assert response.status_code == 200
    assert body["success"] is True and body["error"] is None
    data = body["data"]
    assert data["document"] == {
        "format": "pdf",
        "pages": 1,
        "pages_without_text": 0,
        "has_text_layer": True,
        "chars": len(INVOICE_TEXT_NORMALIZED),
        "ocr_pages": 0,
        "ocr_confidence": None,
    }
    assert [f["name"] for f in data["fields"]] == ["inn", "comment"]
    assert data["llm_used"] is False
    assert data["processing_ms"] >= 0
    assert data["text"] is None  # текст по умолчанию не возвращается


def test_extract_response_is_not_cached(client, invoice_pdf, template_json):
    response = post_extract(client, invoice_pdf, template_json)

    assert response.headers["cache-control"] == "no-store"


def test_fields_are_found_by_rules(client, invoice_pdf, template_json):
    data = post_extract(client, invoice_pdf, template_json, include_text="true").json()["data"]

    inn, comment = data["fields"]
    assert inn["value"] == "7707083893"
    assert inn["source"] == "rule"
    assert inn["confidence"] == 0.95
    assert inn["needs_review"] is False
    assert inn["alternatives"] == []
    # позиции указывают на фрагмент в возвращённом тексте — по ним интерфейс подсвечивает значение
    assert data["text"][inn["start"]:inn["end"]] == inn["raw"] == "7707083893"
    # произвольный текст без якорей правила не угадывают
    assert comment == {
        "name": "comment",
        "value": None,
        "source": None,
        "raw": None,
        "start": None,
        "end": None,
        "confidence": 0.0,
        "needs_review": False,
        "alternatives": [],
        "issues": [],
    }


def test_full_invoice_template(client, invoice_pdf):
    template = (
        '{"name": "Счёт", "fields": ['
        '{"name": "number", "label": "Номер", "data_type": "number", "keywords": ["Счёт на оплату"]},'
        '{"name": "date", "label": "Дата", "data_type": "date", "keywords": ["Счёт на оплату"]},'
        '{"name": "supplier", "label": "Поставщик", "data_type": "organization", "keywords": ["Поставщик"]},'
        '{"name": "total", "label": "Сумма", "data_type": "amount", "keywords": ["Итого"]}'
        "]}"
    )

    fields = {f["name"]: f["value"] for f in post_extract(client, invoice_pdf, template).json()["data"]["fields"]}

    assert fields == {"number": "15", "date": "2026-03-12", "supplier": 'ООО "Ромашка"', "total": "15000.00"}


def test_extract_can_return_text(client, invoice_docx, template_json):
    response = post_extract(client, invoice_docx, template_json, filename="doc.docx", include_text="true")

    assert response.status_code == 200
    assert "Договор поставки" in response.json()["data"]["text"]


def test_file_extension_is_ignored(client, template_json):
    docx_named_as_pdf = make_docx(["Акт выполненных работ"])

    response = post_extract(client, docx_named_as_pdf, template_json, filename="act.pdf")

    assert response.status_code == 200
    assert response.json()["data"]["document"]["format"] == "docx"


def test_scan_without_text_layer_is_reported(client, scanned_pdf, template_json):
    response = post_extract(client, scanned_pdf, template_json)

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "NO_TEXT_LAYER"
    assert response.json()["success"] is False


def test_unsupported_format_returns_415(client, template_json):
    response = post_extract(client, b"MZ\x90\x00 not a document", template_json, filename="virus.pdf")

    assert response.status_code == 415
    assert response.json()["error"]["code"] == "UNSUPPORTED_FORMAT"


def test_empty_file_is_rejected(client, template_json):
    response = post_extract(client, b"", template_json)

    assert response.status_code == 400
    assert response.json()["error"]["code"] == "EMPTY_FILE"


def test_file_of_exactly_max_size_is_accepted(invoice_pdf, template_json):
    with make_client(max_file_bytes=len(invoice_pdf)) as client:
        response = post_extract(client, invoice_pdf, template_json)

    assert response.status_code == 200


def test_file_one_byte_over_limit_returns_413(invoice_pdf, template_json):
    with make_client(max_file_bytes=len(invoice_pdf) - 1) as client:
        response = post_extract(client, invoice_pdf, template_json)

    assert response.status_code == 413
    assert response.json()["error"]["code"] == "FILE_TOO_LARGE"


def test_invalid_template_json_returns_422(client, invoice_pdf):
    response = post_extract(client, invoice_pdf, "{not json")

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_TEMPLATE"


def test_template_with_duplicate_field_names_is_rejected(client, invoice_pdf):
    template = '{"fields": [{"name": "inn", "label": "ИНН"}, {"name": "inn", "label": "ИНН 2"}]}'

    response = post_extract(client, invoice_pdf, template)

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_TEMPLATE"


def test_template_with_invalid_regex_is_rejected(client, invoice_pdf):
    template = '{"fields": [{"name": "inn", "label": "ИНН", "regex_pattern": "([0-9"}]}'

    response = post_extract(client, invoice_pdf, template)

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_TEMPLATE"


def test_template_with_valid_regex_is_accepted(client, invoice_pdf):
    template = '{"fields": [{"name": "inn", "label": "ИНН", "regex_pattern": "\\\\d{10}"}]}'

    response = post_extract(client, invoice_pdf, template)

    assert response.status_code == 200


def test_missing_file_returns_validation_error_in_envelope(client, template_json):
    response = client.post("/extract", data={"template": template_json})

    assert response.status_code == 422
    assert response.json()["success"] is False
    assert response.json()["error"]["code"] == "VALIDATION_ERROR"
