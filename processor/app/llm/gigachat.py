"""Клиент GigaChat API (Сбер): OAuth-токен по ключу авторизации и запрос chat/completions.
Синхронный — вызывается из потока обработки документа (run_in_threadpool)."""
import json
import ssl
import threading
import time
import uuid
from collections.abc import Callable, Sequence
from dataclasses import dataclass
from urllib.parse import urlsplit

import certifi
import httpx

from app import __version__
from app.llm.client import ChatMessage, LlmReply
from app.llm.errors import (
    LlmAuthError,
    LlmBadResponseError,
    LlmBusyError,
    LlmQuotaError,
    LlmRefusedError,
    LlmRequestRejectedError,
    LlmUnavailableError,
)

DEFAULT_OAUTH_URL = "https://ngw.devices.sberbank.ru:9443/api/v2/oauth"
DEFAULT_API_URL = "https://api.giga.chat/v1"
DEFAULT_MODEL = "GigaChat-2-Pro"
SCOPES = ("GIGACHAT_API_PERS", "GIGACHAT_API_B2B", "GIGACHAT_API_CORP")
# токен живёт 30 минут; обновляем заранее, чтобы он не истёк посреди запроса
TOKEN_REFRESH_MARGIN_SECONDS = 60
# expires_at в документации встречается и в миллисекундах, и в секундах
MILLISECONDS_THRESHOLD = 10**11
CONNECT_TIMEOUT_SECONDS = 5.0
# извлечение должно быть детерминированным; GigaChat требует temperature > 0
TEMPERATURE = 0.1
MAX_REPLY_CHARS = 200_000
# ответ читается потоком и обрывается, если он больше лимита или не уложился в общий срок
MAX_RESPONSE_BYTES = 1024 * 1024
# после отказа в ключе не повторяем вход на каждом документе (риск блокировки ключа)
AUTH_FAILURE_COOLDOWN_SECONDS = 60.0
USER_AGENT = f"doc-processor/{__version__}"


def make_ssl_context(ca_bundle: str | None) -> ssl.SSLContext:
    """Проверка сертификатов всегда включена. Серверы GigaChat подписаны корневым сертификатом
    Минцифры, которого нет в certifi: его файл (PEM) добавляется к стандартным корневым."""
    context = ssl.create_default_context(cafile=certifi.where())
    if ca_bundle is not None:
        context.load_verify_locations(cafile=ca_bundle)  # FileNotFoundError, если файла нет
    return context


def require_https(url: str) -> str:
    """https, есть хост и нет user:password@ (иначе ключ может уйти на другой адрес)."""
    parts = urlsplit(url)
    if parts.scheme != "https" or not parts.hostname or parts.username is not None or parts.password is not None:
        raise ValueError("адрес GigaChat должен иметь вид https://хост/путь")
    return url.rstrip("/")


@dataclass(frozen=True)
class _HttpResult:
    status: int
    body: bytes


class GigaChatClient:
    def __init__(
        self,
        auth_key: str,
        *,
        scope: str = "GIGACHAT_API_PERS",
        model: str = DEFAULT_MODEL,
        oauth_url: str = DEFAULT_OAUTH_URL,
        api_url: str = DEFAULT_API_URL,
        ca_bundle: str | None = None,
        timeout: float = 20.0,
        max_concurrent: int = 1,
        queue_wait: float = 5.0,
        transport: httpx.BaseTransport | None = None,
        clock: Callable[[], float] = time.time,
        monotonic: Callable[[], float] = time.monotonic,
    ):
        if not auth_key.strip():
            raise ValueError("не задан ключ авторизации GigaChat")
        if scope not in SCOPES:
            raise ValueError(f"scope должен быть одним из: {', '.join(SCOPES)}")
        self._auth_key = auth_key.strip()
        self._scope = scope
        self._model = model
        self._oauth_url = require_https(oauth_url)
        self._api_url = require_https(api_url)
        self._timeout = timeout
        self._queue_wait = queue_wait
        self._clock = clock
        self._monotonic = monotonic
        self._auth_failed_until = float("-inf")
        # у физлиц GigaChat генерирует ответы в один поток: лишние запросы ждут здесь, а не получают 429
        self._streams = threading.BoundedSemaphore(max_concurrent)
        self._token_lock = threading.Lock()
        self._token: str | None = None
        self._token_expires = 0.0
        verify = True if transport is not None else make_ssl_context(ca_bundle)
        self._http = httpx.Client(
            transport=transport,
            verify=verify,
            headers={"User-Agent": USER_AGENT, "Accept": "application/json"},
            follow_redirects=False,
            trust_env=False,  # ключ не должен уйти через прокси из переменных окружения
        )

    def __repr__(self) -> str:
        return f"GigaChatClient(model={self._model!r}, api_url={self._api_url!r})"

    def close(self) -> None:
        self._http.close()

    def complete(self, messages: Sequence[ChatMessage], max_tokens: int, timeout: float | None = None) -> LlmReply:
        """Срок — на весь вызов: ожидание потока, токен, повтор и чтение ответа; не больше настроенного."""
        limit = self._timeout if timeout is None else min(self._timeout, timeout)
        deadline = self._monotonic() + limit
        if self._monotonic() < self._auth_failed_until:
            raise LlmAuthError()
        if not self._streams.acquire(timeout=max(0.0, min(self._queue_wait, limit))):
            raise LlmBusyError()
        try:
            token = self._access_token(deadline)
            response = self._post_chat(messages, max_tokens, token, deadline)
            if response.status == 401:  # токен отозван или истёк раньше срока — один повтор с новым
                response = self._post_chat(messages, max_tokens, self._access_token(deadline, stale=token), deadline)
            return _parse_chat(response)
        finally:
            self._streams.release()

    def _post_chat(self, messages: Sequence[ChatMessage], max_tokens: int, token: str, deadline: float) -> _HttpResult:
        body = {
            "model": self._model,
            "messages": [{"role": m.role, "content": m.content} for m in messages],
            "temperature": TEMPERATURE,
            "max_tokens": max_tokens,
            "stream": False,
        }
        headers = {"Authorization": f"Bearer {token}"}
        return self._send("POST", f"{self._api_url}/chat/completions", deadline, json=body, headers=headers)

    def _access_token(self, deadline: float, stale: str | None = None) -> str:
        """stale — токен, который сервер отверг: обновляем, только если другой поток ещё этого не сделал."""
        with self._token_lock:
            expired = self._clock() >= self._token_expires - TOKEN_REFRESH_MARGIN_SECONDS
            if self._token is None or expired or (stale is not None and self._token == stale):
                try:
                    self._token, self._token_expires = self._request_token(deadline)
                except LlmAuthError:
                    self._auth_failed_until = self._monotonic() + AUTH_FAILURE_COOLDOWN_SECONDS
                    raise
            return self._token

    def _request_token(self, deadline: float) -> tuple[str, float]:
        response = self._send(
            "POST",
            self._oauth_url,
            deadline,
            data={"scope": self._scope},
            headers={"Authorization": f"Basic {self._auth_key}", "RqUID": str(uuid.uuid4())},
        )
        _raise_for_status(response)
        payload = _json(response)
        token, expires_at = payload.get("access_token"), payload.get("expires_at")
        if not isinstance(token, str) or not token or not isinstance(expires_at, (int, float)):
            raise LlmBadResponseError()
        expires = expires_at / 1000 if expires_at > MILLISECONDS_THRESHOLD else float(expires_at)
        return token, expires

    def _send(self, method: str, url: str, deadline: float, **kwargs: object) -> _HttpResult:
        remaining = deadline - self._monotonic()
        if remaining <= 0:
            raise LlmUnavailableError()
        timeout = httpx.Timeout(remaining, connect=min(CONNECT_TIMEOUT_SECONDS, remaining))
        try:
            with self._http.stream(method, url, timeout=timeout, **kwargs) as response:
                body = bytearray()
                for chunk in response.iter_bytes():
                    body += chunk
                    if len(body) > MAX_RESPONSE_BYTES:
                        raise LlmBadResponseError()
                    if self._monotonic() > deadline:  # сервер отдаёт ответ по капле
                        raise LlmUnavailableError()
                return _HttpResult(response.status_code, bytes(body))
        except (httpx.HTTPError, RuntimeError, UnicodeError):
            # текст исключения может содержать адрес и заголовки — наружу только код ошибки;
            # RuntimeError — клиент закрыт при остановке сервиса
            raise LlmUnavailableError() from None


def _raise_for_status(response: _HttpResult) -> None:
    status = response.status
    if status < 400:
        return
    if status in (401, 403):
        raise LlmAuthError()
    if status == 402:
        raise LlmQuotaError()
    if status == 429:
        raise LlmBusyError()
    if status >= 500:
        raise LlmUnavailableError()
    raise LlmRequestRejectedError()


def _json(response: _HttpResult) -> dict:
    try:
        payload = json.loads(response.body)
    except (ValueError, RecursionError):
        raise LlmBadResponseError() from None
    if not isinstance(payload, dict):
        raise LlmBadResponseError()
    return payload


def _parse_chat(response: _HttpResult) -> LlmReply:
    _raise_for_status(response)
    payload = _json(response)
    choices = payload.get("choices")
    if not isinstance(choices, list) or not choices or not isinstance(choices[0], dict):
        raise LlmBadResponseError()
    choice = choices[0]
    if choice.get("finish_reason") == "blacklist":
        raise LlmRefusedError()
    message = choice.get("message")
    content = message.get("content") if isinstance(message, dict) else None
    if not isinstance(content, str) or len(content) > MAX_REPLY_CHARS:
        raise LlmBadResponseError()
    usage = payload.get("usage") if isinstance(payload.get("usage"), dict) else {}
    return LlmReply(
        content=content,
        prompt_tokens=_int(usage.get("prompt_tokens")),
        completion_tokens=_int(usage.get("completion_tokens")),
    )


def _int(value: object) -> int:
    return value if isinstance(value, int) and value >= 0 else 0
