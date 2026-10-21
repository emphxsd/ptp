"""Клиент GigaChat на подставном HTTP-транспорте: токен, повтор при 401, коды ошибок, отсутствие утечек ключа."""
import json
import ssl
import uuid

import httpx
import pytest

from app.llm.client import ChatMessage
from app.llm.errors import (
    LlmAuthError,
    LlmBadResponseError,
    LlmBusyError,
    LlmQuotaError,
    LlmRefusedError,
    LlmRequestRejectedError,
    LlmUnavailableError,
)
from app.llm.gigachat import GigaChatClient, make_ssl_context

AUTH_KEY = "c2VjcmV0LWNsaWVudDpzZWNyZXQ="  # синтетический ключ, не настоящий
OAUTH_URL = "https://oauth.test/api/v2/oauth"
API_URL = "https://api.test/v1"
NOW = 1_800_000_000.0
MESSAGES = (ChatMessage("system", "инструкция"), ChatMessage("user", "документ"))


class FakeGigaChat:
    """Имитация двух эндпоинтов GigaChat: выдача токена и chat/completions."""

    def __init__(self, chat_responses=None, token_expires_at=None):
        self.chat_responses = list(chat_responses or [chat_ok('{"inn": null}')])
        self.token_expires_at = token_expires_at if token_expires_at is not None else int((NOW + 1800) * 1000)
        self.token_requests: list[httpx.Request] = []
        self.chat_requests: list[httpx.Request] = []
        self.tokens_issued = 0

    def __call__(self, request: httpx.Request) -> httpx.Response:
        if str(request.url) == OAUTH_URL:
            self.token_requests.append(request)
            self.tokens_issued += 1
            return httpx.Response(
                200, json={"access_token": f"token-{self.tokens_issued}", "expires_at": self.token_expires_at}
            )
        assert str(request.url) == f"{API_URL}/chat/completions"
        self.chat_requests.append(request)
        response = self.chat_responses.pop(0)
        if isinstance(response, Exception):
            raise response
        return response


def chat_ok(content: str, finish_reason: str = "stop") -> httpx.Response:
    return httpx.Response(
        200,
        json={
            "choices": [{"message": {"role": "assistant", "content": content}, "index": 0, "finish_reason": finish_reason}],
            "usage": {"prompt_tokens": 120, "completion_tokens": 15, "total_tokens": 135},
            "model": "GigaChat-2-Pro",
            "object": "chat.completion",
        },
    )


def make_client(fake: FakeGigaChat, clock=lambda: NOW, **overrides) -> GigaChatClient:
    options = {
        "scope": "GIGACHAT_API_PERS",
        "model": "GigaChat-2-Pro",
        "oauth_url": OAUTH_URL,
        "api_url": API_URL,
        "timeout": 5.0,
        "max_concurrent": 1,
        "queue_wait": 0.1,
        "transport": httpx.MockTransport(fake),
        "clock": clock,
    } | overrides
    return GigaChatClient(AUTH_KEY, **options)


# ---------------------------------------------------------------- токен доступа
def test_token_request_uses_basic_key_scope_and_uuid_rquid():
    fake = FakeGigaChat()

    make_client(fake).complete(MESSAGES, max_tokens=100)

    (request,) = fake.token_requests
    assert request.method == "POST"
    assert request.headers["authorization"] == f"Basic {AUTH_KEY}"
    assert uuid.UUID(request.headers["rquid"]).version == 4
    assert request.headers["content-type"] == "application/x-www-form-urlencoded"
    assert request.content == b"scope=GIGACHAT_API_PERS"


def test_token_is_cached_between_calls():
    fake = FakeGigaChat(chat_responses=[chat_ok("{}"), chat_ok("{}")])
    client = make_client(fake)

    client.complete(MESSAGES, max_tokens=100)
    client.complete(MESSAGES, max_tokens=100)

    assert len(fake.token_requests) == 1
    assert [r.headers["authorization"] for r in fake.chat_requests] == ["Bearer token-1", "Bearer token-1"]


@pytest.mark.parametrize("expires_at", [int((NOW + 30) * 1000), int(NOW + 30)], ids=["milliseconds", "seconds"])
def test_token_refreshed_shortly_before_expiry(expires_at):
    # в документации expires_at встречается и в миллисекундах, и в секундах — понимаем оба
    fake = FakeGigaChat(chat_responses=[chat_ok("{}"), chat_ok("{}")], token_expires_at=expires_at)
    client = make_client(fake)

    client.complete(MESSAGES, max_tokens=100)
    client.complete(MESSAGES, max_tokens=100)

    assert len(fake.token_requests) == 2


def test_expired_token_on_chat_is_refreshed_once():
    fake = FakeGigaChat(chat_responses=[httpx.Response(401, json={"status": 401, "message": "Unauthorized"}), chat_ok("{}")])

    reply = make_client(fake).complete(MESSAGES, max_tokens=100)

    assert reply.content == "{}"
    assert len(fake.token_requests) == 2
    assert fake.chat_requests[-1].headers["authorization"] == "Bearer token-2"


def test_repeated_401_raises_auth_error():
    fake = FakeGigaChat(chat_responses=[httpx.Response(401), httpx.Response(401)])

    with pytest.raises(LlmAuthError):
        make_client(fake).complete(MESSAGES, max_tokens=100)


def test_rejected_key_raises_auth_error():
    def handler(request):
        return httpx.Response(401, json={"code": 6, "message": "credentials doesn't match db data"})

    client = GigaChatClient(AUTH_KEY, oauth_url=OAUTH_URL, api_url=API_URL, transport=httpx.MockTransport(handler))

    with pytest.raises(LlmAuthError):
        client.complete(MESSAGES, max_tokens=100)


def test_token_response_without_access_token_is_bad_response():
    client = GigaChatClient(
        AUTH_KEY, oauth_url=OAUTH_URL, api_url=API_URL, transport=httpx.MockTransport(lambda r: httpx.Response(200, json={}))
    )

    with pytest.raises(LlmBadResponseError):
        client.complete(MESSAGES, max_tokens=100)


# ---------------------------------------------------------------- запрос к модели
def test_chat_request_body_and_reply():
    fake = FakeGigaChat(chat_responses=[chat_ok('{"inn": {"value": "7707083893"}}')])

    reply = make_client(fake).complete(MESSAGES, max_tokens=256)

    body = json.loads(fake.chat_requests[0].content)
    assert body["model"] == "GigaChat-2-Pro"
    assert body["messages"] == [{"role": "system", "content": "инструкция"}, {"role": "user", "content": "документ"}]
    assert body["max_tokens"] == 256
    assert body["stream"] is False
    assert 0 < body["temperature"] <= 0.3
    # без User-Agent api.giga.chat отвечает 403
    assert fake.chat_requests[0].headers["user-agent"].startswith("doc-processor/")
    assert reply.content == '{"inn": {"value": "7707083893"}}'
    assert (reply.prompt_tokens, reply.completion_tokens) == (120, 15)


@pytest.mark.parametrize(
    ("response", "error"),
    [
        (httpx.Response(402, json={"status": 402, "message": "Payment Required"}), LlmQuotaError),
        (httpx.Response(429, json={"status": 429, "message": "Too Many Requests"}), LlmBusyError),
        (httpx.Response(500), LlmUnavailableError),
        (httpx.Response(503), LlmUnavailableError),
        (httpx.Response(404, json={"message": "No such model"}), LlmRequestRejectedError),
        (httpx.Response(422, json={"message": "Invalid params"}), LlmRequestRejectedError),
        (httpx.Response(200, text="<html>не JSON</html>"), LlmBadResponseError),
        (httpx.Response(200, json={"choices": []}), LlmBadResponseError),
        (httpx.Response(200, json={"choices": [{"message": {"content": 42}}]}), LlmBadResponseError),
        (chat_ok("", finish_reason="blacklist"), LlmRefusedError),
        (httpx.ConnectError("нет сети"), LlmUnavailableError),
        (httpx.ReadTimeout("долго"), LlmUnavailableError),
    ],
    ids=["402", "429", "500", "503", "404", "422", "html", "no-choices", "bad-content", "blacklist", "connect", "timeout"],
)
def test_chat_errors_are_mapped(response, error):
    fake = FakeGigaChat(chat_responses=[response])

    with pytest.raises(error):
        make_client(fake).complete(MESSAGES, max_tokens=100)


def test_too_long_reply_is_bad_response():
    fake = FakeGigaChat(chat_responses=[chat_ok("x" * 200_001)])

    with pytest.raises(LlmBadResponseError):
        make_client(fake).complete(MESSAGES, max_tokens=100)


def test_errors_and_repr_do_not_leak_secrets():
    fake = FakeGigaChat(chat_responses=[httpx.Response(500, text=f"echo {AUTH_KEY} документ")])
    client = make_client(fake)

    with pytest.raises(LlmUnavailableError) as caught:
        client.complete(MESSAGES, max_tokens=100)

    assert AUTH_KEY not in str(caught.value)
    assert "документ" not in str(caught.value)
    assert AUTH_KEY not in repr(client)
    assert "token-1" not in repr(client)


# ---------------------------------------------------------------- ограничение параллельности
def test_busy_when_no_free_stream():
    # у физлиц GigaChat разрешает один поток генерации — второй запрос ждёт и получает отказ
    fake = FakeGigaChat()
    client = make_client(fake, max_concurrent=1, queue_wait=0.01)
    client._streams.acquire()
    try:
        with pytest.raises(LlmBusyError):
            client.complete(MESSAGES, max_tokens=100)
    finally:
        client._streams.release()
    assert fake.chat_requests == []


# ---------------------------------------------------------------- настройки клиента
def test_only_https_urls_are_accepted():
    with pytest.raises(ValueError):
        GigaChatClient(AUTH_KEY, oauth_url="http://oauth.test/api/v2/oauth", api_url=API_URL)
    with pytest.raises(ValueError):
        GigaChatClient(AUTH_KEY, oauth_url=OAUTH_URL, api_url="http://api.test/v1")


def test_empty_auth_key_is_rejected():
    with pytest.raises(ValueError):
        GigaChatClient("  ", oauth_url=OAUTH_URL, api_url=API_URL)


def test_ssl_context_verifies_certificates_by_default():
    context = make_ssl_context(None)

    assert context.verify_mode is ssl.CERT_REQUIRED
    assert context.check_hostname is True


def test_ssl_context_with_missing_ca_bundle_fails_fast(tmp_path):
    with pytest.raises(FileNotFoundError):
        make_ssl_context(str(tmp_path / "нет-такого.pem"))


# ---------------------------------------------------------------- общий бюджет времени
class FakeMonotonic:
    def __init__(self):
        self.now = 0.0

    def __call__(self):
        return self.now


def test_total_deadline_covers_token_and_chat():
    # таймаут — на весь вызов (очередь, токен, повтор, чтение ответа), а не на каждую фазу
    monotonic = FakeMonotonic()
    fake = FakeGigaChat()

    def slow_handler(request):
        monotonic.now += 15
        return fake(request)

    client = make_client(fake, timeout=20.0, transport=httpx.MockTransport(slow_handler), monotonic=monotonic)

    with pytest.raises(LlmUnavailableError):
        client.complete(MESSAGES, max_tokens=100)


def test_oversized_response_body_is_rejected():
    fake = FakeGigaChat(chat_responses=[httpx.Response(200, content=b"{" + b" " * 3_000_000 + b"}")])

    with pytest.raises(LlmBadResponseError):
        make_client(fake).complete(MESSAGES, max_tokens=100)


def test_closed_client_raises_llm_error():
    client = make_client(FakeGigaChat())
    client.close()

    with pytest.raises(LlmUnavailableError):
        client.complete(MESSAGES, max_tokens=100)


def test_auth_failure_pauses_further_token_requests():
    requests = []

    def handler(request):
        requests.append(request)
        return httpx.Response(401, json={"code": 6, "message": "credentials doesn't match db data"})

    client = GigaChatClient(AUTH_KEY, oauth_url=OAUTH_URL, api_url=API_URL, transport=httpx.MockTransport(handler))
    for _ in range(3):
        with pytest.raises(LlmAuthError):
            client.complete(MESSAGES, max_tokens=100)

    assert len(requests) == 1


def test_url_with_credentials_is_rejected():
    with pytest.raises(ValueError):
        GigaChatClient(AUTH_KEY, oauth_url="https://user:pass@oauth.test/api/v2/oauth", api_url=API_URL)
