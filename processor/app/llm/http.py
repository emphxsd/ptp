"""Общая HTTP-часть LLM-клиентов: срок на весь вызов, предел размера ответа, коды ошибок без утечек."""
import json
from collections.abc import Callable
from dataclasses import dataclass

import httpx

from app.llm.errors import (
    LlmAuthError,
    LlmBadResponseError,
    LlmBusyError,
    LlmQuotaError,
    LlmRequestRejectedError,
    LlmUnavailableError,
)

CONNECT_TIMEOUT_SECONDS = 5.0
# ответ читается потоком и обрывается, если он больше лимита или не уложился в общий срок
MAX_RESPONSE_BYTES = 1024 * 1024


@dataclass(frozen=True)
class HttpResult:
    status: int
    body: bytes


def send(
    http: httpx.Client, method: str, url: str, deadline: float, monotonic: Callable[[], float], **kwargs: object
) -> HttpResult:
    remaining = deadline - monotonic()
    if remaining <= 0:
        raise LlmUnavailableError()
    timeout = httpx.Timeout(remaining, connect=min(CONNECT_TIMEOUT_SECONDS, remaining))
    try:
        with http.stream(method, url, timeout=timeout, **kwargs) as response:
            body = bytearray()
            for chunk in response.iter_bytes():
                body += chunk
                if len(body) > MAX_RESPONSE_BYTES:
                    raise LlmBadResponseError()
                if monotonic() > deadline:  # сервер отдаёт ответ по капле
                    raise LlmUnavailableError()
            return HttpResult(response.status_code, bytes(body))
    except (httpx.HTTPError, RuntimeError, UnicodeError):
        # текст исключения может содержать адрес, заголовки и фрагменты документа — наружу только код ошибки;
        # RuntimeError — клиент закрыт при остановке сервиса
        raise LlmUnavailableError() from None


def raise_for_status(response: HttpResult) -> None:
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


def parse_json(response: HttpResult) -> dict:
    try:
        payload = json.loads(response.body)
    except (ValueError, RecursionError):
        raise LlmBadResponseError() from None
    if not isinstance(payload, dict):
        raise LlmBadResponseError()
    return payload


def non_negative_int(value: object) -> int:
    return value if isinstance(value, int) and not isinstance(value, bool) and value >= 0 else 0
