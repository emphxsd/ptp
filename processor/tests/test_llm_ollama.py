"""Клиент локальной LLM (Ollama) на подставном HTTP-транспорте: запрос, разбор ответа, ошибки, адрес только локальный."""
import json

import httpx
import pytest

from app.llm.client import ChatMessage
from app.llm.errors import LlmBadResponseError, LlmBusyError, LlmRequestRejectedError, LlmUnavailableError
from app.llm.ollama import OllamaClient
from app.llm.urls import is_loopback_url, require_local_or_https

URL = "http://127.0.0.1:11434"
MESSAGES = (ChatMessage("system", "инструкция"), ChatMessage("user", "документ"))


class FakeOllama:
    """Имитация POST /api/chat: отдаёт заготовленные ответы и запоминает запросы."""

    def __init__(self, *responses):
        self.responses = list(responses or [chat_ok('{"inn": null}')])
        self.requests: list[httpx.Request] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        assert str(request.url) == f"{URL}/api/chat"
        self.requests.append(request)
        response = self.responses.pop(0)
        if isinstance(response, Exception):
            raise response
        return response


def chat_ok(content: str) -> httpx.Response:
    return httpx.Response(
        200,
        json={
            "model": "qwen2.5:7b",
            "message": {"role": "assistant", "content": content},
            "done": True,
            "done_reason": "stop",
            "prompt_eval_count": 120,
            "eval_count": 15,
        },
    )


def make_client(fake: FakeOllama, **overrides) -> OllamaClient:
    options = {"model": "qwen2.5:7b", "timeout": 5.0, "num_ctx": 8192, "keep_alive": "30m", "queue_wait": 0.1}
    return OllamaClient(URL, transport=httpx.MockTransport(fake), **(options | overrides))


def test_chat_request_asks_for_deterministic_json_with_large_context():
    fake = FakeOllama()

    make_client(fake).complete(MESSAGES, max_tokens=300)

    body = json.loads(fake.requests[0].content)
    assert body["model"] == "qwen2.5:7b"
    assert body["messages"] == [{"role": "system", "content": "инструкция"}, {"role": "user", "content": "документ"}]
    assert body["stream"] is False
    assert body["format"] == "json"
    # окно по умолчанию у Ollama маленькое: длинный документ обрезался бы молча
    assert body["options"] == {"temperature": 0, "num_predict": 300, "num_ctx": 8192}
    assert body["keep_alive"] == "30m"


def test_reply_content_and_token_counts_are_returned():
    reply = make_client(FakeOllama(chat_ok('{"inn": {"value": "7701234567"}}'))).complete(MESSAGES, max_tokens=100)

    assert reply.content == '{"inn": {"value": "7701234567"}}'
    assert (reply.prompt_tokens, reply.completion_tokens) == (120, 15)


@pytest.mark.parametrize(
    ("response", "error"),
    [
        (httpx.Response(404, json={"error": "model 'qwen2.5:7b' not found"}), LlmRequestRejectedError),
        (httpx.Response(400, json={"error": "bad request"}), LlmRequestRejectedError),
        (httpx.Response(500, json={"error": "out of memory"}), LlmUnavailableError),
        (httpx.Response(503), LlmUnavailableError),
        (httpx.Response(429), LlmBusyError),
        (httpx.ConnectError("connection refused"), LlmUnavailableError),
        (httpx.ReadTimeout("timed out"), LlmUnavailableError),
        (httpx.Response(200, content=b"not json"), LlmBadResponseError),
        (httpx.Response(200, json={"message": {"content": 42}}), LlmBadResponseError),
        (httpx.Response(200, json=["list"]), LlmBadResponseError),
    ],
    ids=["model-not-pulled", "bad-request", "server-error", "unavailable", "busy", "not-running", "timeout",
         "not-json", "content-not-string", "not-object"],
)
def test_errors_map_to_llm_codes(response, error):
    with pytest.raises(error):
        make_client(FakeOllama(response)).complete(MESSAGES, max_tokens=100)


def test_error_text_does_not_leak_document():
    fake = FakeOllama(httpx.ConnectError("документ: Иванов Иван, паспорт 4500 123456"))

    with pytest.raises(LlmUnavailableError) as raised:
        make_client(fake).complete(MESSAGES, max_tokens=100)

    assert "Иванов" not in str(raised.value)


def test_oversized_reply_is_rejected():
    huge = httpx.Response(200, content=b"x" * (1024 * 1024 + 1))

    with pytest.raises(LlmBadResponseError):
        make_client(FakeOllama(huge)).complete(MESSAGES, max_tokens=100)


def test_busy_when_generation_slot_is_taken():
    client = make_client(FakeOllama(), max_concurrent=1, queue_wait=0.05)
    client._slots.acquire()  # другой документ уже генерирует ответ

    with pytest.raises(LlmBusyError):
        client.complete(MESSAGES, max_tokens=100)


def test_preload_loads_model_with_same_context_as_real_requests():
    fake = FakeOllama(httpx.Response(200, json={"model": "qwen2.5:7b", "done": True, "done_reason": "load"}))

    assert make_client(fake).preload() is True

    body = json.loads(fake.requests[0].content)
    # другой num_ctx заставил бы Ollama перезагрузить модель на первом документе — прогрев был бы напрасным
    assert body == {
        "model": "qwen2.5:7b",
        "messages": [],
        "stream": False,
        "options": {"num_ctx": 8192},
        "keep_alive": "30m",
    }


def test_preload_failure_is_logged_not_raised(caplog):
    with caplog.at_level("WARNING", logger="processor"):
        assert make_client(FakeOllama(httpx.ConnectError("refused"))).preload() is False
        assert make_client(FakeOllama(httpx.Response(404, json={"error": "not found"}))).preload() is False

    messages = [r.getMessage() for r in caplog.records]
    assert any("LLM_UNAVAILABLE" in m for m in messages)
    assert any("LLM_REQUEST_REJECTED" in m for m in messages)


@pytest.mark.parametrize(
    "url",
    ["http://127.0.0.1:11434", "http://127.0.0.1:11434/", "http://[::1]:11434", "https://llm.example.org"],
)
def test_local_or_https_urls_accepted(url):
    assert require_local_or_https(url) == url.rstrip("/")


@pytest.mark.parametrize(
    "url",
    [
        "http://192.168.1.10:11434",  # документ ушёл бы по сети без шифрования
        "http://llm.example.org",
        "http://localhost:11434",  # имя зависит от файла hosts и резолвера — только IP-адрес
        "http://localhost.evil.test:11434",
        "https://user:pass@llm.example.org",
        "ftp://127.0.0.1",
        "http://",
        "http://127.0.0.1:abc",
    ],
    ids=["lan-http", "remote-http", "localhost-name", "localhost-prefix", "userinfo", "scheme", "no-host", "bad-port"],
)
def test_remote_plain_http_and_credentials_rejected(url):
    with pytest.raises(ValueError):
        require_local_or_https(url)


@pytest.mark.parametrize(
    ("url", "local"),
    [("http://127.0.0.1:11434", True), ("http://[::1]:11434", True), ("https://llm.example.org", False)],
)
def test_loopback_detection(url, local):
    assert is_loopback_url(url) is local


def test_docker_service_name_allowed_only_when_listed():
    # в Docker Compose Ollama — соседний контейнер на том же компьютере: имя сервиса задаётся явно
    with pytest.raises(ValueError):
        require_local_or_https("http://ollama:11434")

    assert require_local_or_https("http://ollama:11434", local_hosts=("ollama",)) == "http://ollama:11434"
    assert is_loopback_url("http://ollama:11434", local_hosts=("ollama",)) is True
    assert is_loopback_url("http://ollama.evil.test:11434", local_hosts=("ollama",)) is False
    with pytest.raises(ValueError):
        require_local_or_https("http://192.168.1.10:11434", local_hosts=("ollama",))
