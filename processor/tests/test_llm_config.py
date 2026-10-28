"""Настройки LLM, создание клиента при старте и статус в /health; ключ не должен утекать."""
import pytest
from fastapi.testclient import TestClient
from pydantic import ValidationError

from app.config import Settings
from app.llm.factory import build_llm_client
from app.llm.gigachat import GigaChatClient
from app.main import create_app

AUTH_KEY = "c2VjcmV0LWNsaWVudDpzZWNyZXQ="  # синтетический ключ


def test_llm_settings_read_from_env():
    settings = Settings.from_env(
        {
            "PROCESSOR_GIGACHAT_AUTH_KEY": f"  {AUTH_KEY}  ",
            "PROCESSOR_GIGACHAT_MODEL": "GigaChat-2-Max",
            "PROCESSOR_GIGACHAT_CA_BUNDLE": "certs/russian_trusted_root_ca.pem",
            "PROCESSOR_LLM_TIMEOUT_SECONDS": "15",
            "PROCESSOR_LLM_ENABLED": "true",
        }
    )

    assert settings.gigachat_auth_key.get_secret_value() == AUTH_KEY
    assert settings.gigachat_model == "GigaChat-2-Max"
    assert settings.gigachat_ca_bundle == "certs/russian_trusted_root_ca.pem"
    assert settings.llm_timeout_seconds == 15
    assert settings.llm_configured is True


def test_auth_key_hidden_in_repr_and_dump():
    settings = Settings(gigachat_auth_key=AUTH_KEY)

    assert AUTH_KEY not in repr(settings)
    assert AUTH_KEY not in str(settings.model_dump())


@pytest.mark.parametrize(
    ("overrides", "configured"),
    [({}, False), ({"gigachat_auth_key": ""}, False), ({"gigachat_auth_key": AUTH_KEY, "llm_enabled": False}, False)],
    ids=["no-key", "empty-key", "disabled"],
)
def test_llm_not_configured(overrides, configured):
    settings = Settings(**overrides)

    assert settings.llm_configured is configured
    assert build_llm_client(settings) is None


@pytest.mark.parametrize(
    "overrides",
    [{"gigachat_api_url": "http://api.giga.chat/v1"}, {"gigachat_model": "../evil"}, {"gigachat_scope": "ANY"}],
    ids=["http", "model", "scope"],
)
def test_invalid_llm_settings_rejected(overrides):
    with pytest.raises(ValidationError):
        Settings(**overrides)


def test_factory_builds_gigachat_client():
    client = build_llm_client(Settings(gigachat_auth_key=AUTH_KEY))

    assert isinstance(client, GigaChatClient)
    client.close()


def health(settings: Settings) -> dict:
    with TestClient(create_app(settings.model_copy(update={"ocr_enabled": False}))) as client:
        data = client.get("/health").json()["data"]
    assert data.pop("ocr") == "disabled"
    return data


def test_health_reports_llm_ready_without_calling_api():
    assert health(Settings(ner_enabled=False, gigachat_auth_key=AUTH_KEY)) == {
        "status": "ok",
        "ner": "disabled",
        "llm": "ready",
    }


def test_health_reports_llm_disabled():
    assert health(Settings(ner_enabled=False, llm_enabled=False))["llm"] == "disabled"


def test_missing_ca_bundle_degrades_service_instead_of_crashing(tmp_path):
    settings = Settings(ner_enabled=False, gigachat_auth_key=AUTH_KEY, gigachat_ca_bundle=str(tmp_path / "нет.pem"))

    assert health(settings) == {"status": "degraded", "ner": "disabled", "llm": "unavailable"}


def test_extract_endpoint_uses_llm_client_from_app_state():
    from app.llm.client import LlmReply
    from tests.conftest import make_docx, post_extract

    class FakeLlm:
        def complete(self, messages, max_tokens, timeout=None):
            return LlmReply('{"terms": {"value": "до конца месяца", "quote": "до конца месяца"}}')

        def close(self):
            pass

    docx = make_docx(["Оплатить до конца месяца"])
    template = '{"fields": [{"name": "terms", "label": "Условия оплаты"}]}'
    app = create_app(Settings(ner_enabled=False))
    with TestClient(app) as client:
        app.state.llm_client = FakeLlm()
        body = post_extract(client, docx, template, filename="doc.docx").json()["data"]

    assert body["llm_used"] is True
    assert body["warnings"] == []
    assert body["fields"][0]["source"] == "llm"
    assert body["fields"][0]["value"] == "до конца месяца"


@pytest.mark.parametrize(
    "overrides",
    [
        {"gigachat_auth_key": "ключ с кириллицей"},
        {"gigachat_oauth_url": "https://user@attacker.test/api/v2/oauth"},
        {"gigachat_api_url": "https:///v1"},
    ],
    ids=["non-base64-key", "userinfo", "no-host"],
)
def test_more_invalid_llm_settings_rejected(overrides):
    with pytest.raises(ValidationError):
        Settings(**overrides)


def test_ollama_settings_read_from_env():
    settings = Settings.from_env(
        {
            "PROCESSOR_LLM_PROVIDER": "ollama",
            "PROCESSOR_OLLAMA_URL": "http://127.0.0.1:11500/",
            "PROCESSOR_OLLAMA_MODEL": "qwen2.5:3b",
            "PROCESSOR_OLLAMA_NUM_CTX": "16384",
            "PROCESSOR_OLLAMA_KEEP_ALIVE": "1h",
        }
    )

    assert settings.llm_provider == "ollama"
    assert settings.ollama_url == "http://127.0.0.1:11500"  # без слэша — как его использует клиент
    assert settings.ollama_model == "qwen2.5:3b"
    assert settings.ollama_num_ctx == 16384
    assert settings.ollama_keep_alive == "1h"


def test_ollama_in_docker_compose_is_local():
    settings = Settings.from_env(
        {
            "PROCESSOR_LLM_PROVIDER": "ollama",
            "PROCESSOR_OLLAMA_URL": "http://ollama:11434",
            "PROCESSOR_OLLAMA_LOCAL_HOSTS": " ollama , ",
        }
    )

    assert settings.ollama_local_hosts == ("ollama",)
    assert settings.llm_is_local is True
    with pytest.raises(ValidationError):
        Settings(llm_provider="ollama", ollama_url="http://ollama:11434")


def test_ollama_needs_no_key():
    settings = Settings(llm_provider="ollama")

    assert settings.llm_configured is True
    assert Settings(llm_provider="ollama", llm_enabled=False).llm_configured is False


def test_factory_builds_ollama_client():
    from app.llm.ollama import OllamaClient

    client = build_llm_client(Settings(llm_provider="ollama"))

    assert isinstance(client, OllamaClient)
    client.close()


@pytest.mark.parametrize(
    "overrides",
    [
        {"ollama_url": "http://192.168.1.10:11434"},
        {"ollama_url": "https://user:pass@llm.example.org"},
        {"ollama_model": " qwen"},
        {"ollama_num_ctx": 512},
        {"ollama_keep_alive": "навсегда"},
        {"llm_provider": "openai"},
    ],
    ids=["remote-http", "userinfo", "model", "num-ctx", "keep-alive", "provider"],
)
def test_invalid_ollama_settings_rejected(overrides):
    with pytest.raises(ValidationError):
        Settings(**overrides)


def test_health_reports_ollama_ready_and_preloads_model(monkeypatch):
    import threading

    from app.llm.ollama import OllamaClient

    preloaded = threading.Event()  # прогрев идёт в фоновом потоке
    monkeypatch.setattr(OllamaClient, "preload", lambda self: preloaded.set() or True)

    assert health(Settings(ner_enabled=False, llm_provider="ollama"))["llm"] == "ready"
    assert preloaded.wait(timeout=5)


@pytest.mark.parametrize(
    ("provider", "local_only", "llm_used", "warning"),
    [
        ("gigachat", "true", False, "LLM_SKIPPED_NOT_LOCAL"),  # веб обещал «никуда не передаётся» — облако нельзя
        ("ollama", "true", True, None),
        ("gigachat", "false", True, None),
    ],
    ids=["cloud-blocked", "local-allowed", "cloud-allowed-without-flag"],
)
def test_local_only_request_never_reaches_cloud_llm(monkeypatch, provider, local_only, llm_used, warning):
    from app.llm.client import LlmReply
    from app.llm.ollama import OllamaClient
    from tests.conftest import make_docx, post_extract

    monkeypatch.setattr(OllamaClient, "preload", lambda self: True)
    calls = []

    class FakeLlm:
        def complete(self, messages, max_tokens, timeout=None):
            calls.append(messages)
            return LlmReply('{"terms": {"value": "до конца месяца", "quote": "до конца месяца"}}')

        def close(self):
            pass

    template = '{"fields": [{"name": "terms", "label": "Условия оплаты"}]}'
    app = create_app(Settings(ner_enabled=False, llm_provider=provider, gigachat_auth_key=AUTH_KEY))
    with TestClient(app) as client:
        app.state.llm_client = FakeLlm()
        body = post_extract(
            client, make_docx(["Оплатить до конца месяца"]), template, filename="d.docx", llm_local_only=local_only
        ).json()["data"]

    assert (len(calls) == 1) is llm_used
    assert body["llm_used"] is llm_used
    assert (warning in body["warnings"]) if warning else body["warnings"] == []


def test_request_can_opt_out_of_llm():
    from app.llm.client import LlmReply
    from tests.conftest import make_docx, post_extract

    calls = []

    class FakeLlm:
        def complete(self, messages, max_tokens, timeout=None):
            calls.append(messages)
            return LlmReply("{}")

        def close(self):
            pass

    template = '{"fields": [{"name": "terms", "label": "Условия оплаты"}]}'
    app = create_app(Settings(ner_enabled=False))
    with TestClient(app) as client:
        app.state.llm_client = FakeLlm()
        body = post_extract(client, make_docx(["Текст"]), template, filename="d.docx", use_llm="false").json()["data"]

    assert calls == []
    assert body["llm_used"] is False
