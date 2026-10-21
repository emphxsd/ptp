"""POST /templates/validate: проверка шаблона из конструктора веб-приложения той же схемой, что при обработке.
Только сервис может проверить регулярное выражение через RE2 (включая предел сложности программы)."""
import json

from tests.conftest import make_client

TOKEN = "test-secret-token"


def _check(client, template, **kwargs):
    raw = template if isinstance(template, str) else json.dumps(template, ensure_ascii=False)
    return client.post("/templates/validate", data={"template": raw}, **kwargs)


def _field(**overrides):
    return {"name": "total", "label": "Сумма", "data_type": "amount", **overrides}


def test_valid_template_is_accepted(client):
    response = _check(client, {"name": "Счёт", "fields": [_field(regex_pattern=r"(\d+)")]})

    assert response.status_code == 200
    assert response.headers["cache-control"] == "no-store"
    assert response.json() == {"success": True, "data": {"valid": True, "issues": []}, "error": None}


def test_too_complex_regex_is_reported_with_field_location(client):
    template = {"name": "Счёт", "fields": [_field(), _field(name="who", data_type="string", regex_pattern=r"\pL+")]}

    data = _check(client, template).json()["data"]

    assert data["valid"] is False
    assert [issue["field"] for issue in data["issues"]] == ["fields.1.regex_pattern"]
    assert "слишком сложное" in data["issues"][0]["message"]
    assert not data["issues"][0]["message"].startswith("Value error")


def test_single_invalid_field_reports_only_its_own_issue(client):
    # без фильтра Pydantic добавляет эхо-ошибку «fields: Tuple should have at least 1 item»
    data = _check(client, {"fields": [_field(regex_pattern=r"\pL+")]}).json()["data"]

    assert [issue["field"] for issue in data["issues"]] == ["fields.0.regex_pattern"]


def test_validation_pattern_issue_points_to_nested_field(client):
    template = {"fields": [_field(validation={"pattern": r"(?=\d)\d"})]}

    data = _check(client, template).json()["data"]

    assert data["valid"] is False
    assert data["issues"][0]["field"] == "fields.0.validation.pattern"


def test_template_level_issue_has_template_location(client):
    data = _check(client, {"fields": [_field(), _field()]}).json()["data"]

    assert data["valid"] is False
    assert data["issues"][0]["field"] == "fields"
    assert "повторяются" in data["issues"][0]["message"]


def test_malformed_json_is_rejected(client):
    response = _check(client, "{не json")

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_TEMPLATE"


def test_oversized_template_is_rejected():
    with make_client(max_template_chars=100) as client:
        response = _check(client, {"fields": [_field(llm_hint="x" * 200)]})

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "INVALID_TEMPLATE"


def test_validation_requires_token_when_configured():
    with make_client(api_token=TOKEN) as client:
        template = {"fields": [_field()]}

        assert _check(client, template).status_code == 401
        accepted = _check(client, template, headers={"Authorization": f"Bearer {TOKEN}"})
        assert accepted.status_code == 200
