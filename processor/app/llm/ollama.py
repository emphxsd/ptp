"""Клиент локальной LLM через Ollama (https://ollama.com): документ не покидает сервер.
Синхронный — вызывается из потока обработки документа (run_in_threadpool)."""
import logging
import threading
import time
from collections.abc import Callable, Sequence

import httpx

from app import __version__
from app.llm.client import ChatMessage, LlmReply
from app.llm.errors import LlmBadResponseError, LlmBusyError, LlmError
from app.llm.http import HttpResult, non_negative_int, parse_json, raise_for_status, send
from app.llm.urls import require_local_or_https

DEFAULT_URL = "http://127.0.0.1:11434"
DEFAULT_MODEL = "qwen2.5:7b"
# окно по умолчанию у Ollama — 2–4 тыс. токенов: длинный документ обрезался бы молча
DEFAULT_NUM_CTX = 8192
# сколько модель остаётся в видеопамяти после запроса; первая загрузка — десятки секунд
DEFAULT_KEEP_ALIVE = "30m"
# загрузка модели с диска в видеопамять при старте сервиса
PRELOAD_TIMEOUT_SECONDS = 180.0
MAX_REPLY_CHARS = 200_000
USER_AGENT = f"doc-processor/{__version__}"


class OllamaClient:
    def __init__(
        self,
        url: str = DEFAULT_URL,
        *,
        model: str = DEFAULT_MODEL,
        timeout: float = 20.0,
        num_ctx: int = DEFAULT_NUM_CTX,
        keep_alive: str = DEFAULT_KEEP_ALIVE,
        max_concurrent: int = 1,
        queue_wait: float = 5.0,
        transport: httpx.BaseTransport | None = None,
        monotonic: Callable[[], float] = time.monotonic,
    ):
        self._url = require_local_or_https(url)
        self._model = model
        self._timeout = timeout
        self._num_ctx = num_ctx
        self._keep_alive = keep_alive
        self._queue_wait = queue_wait
        self._monotonic = monotonic
        # одна видеокарта: параллельные запросы только мешают друг другу, лишние ждут здесь
        self._slots = threading.BoundedSemaphore(max_concurrent)
        self._http = httpx.Client(
            transport=transport,
            headers={"User-Agent": USER_AGENT, "Accept": "application/json"},
            follow_redirects=False,
            trust_env=False,  # документ не должен уйти через прокси из переменных окружения
        )

    def __repr__(self) -> str:
        return f"OllamaClient(model={self._model!r}, url={self._url!r})"

    def close(self) -> None:
        self._http.close()

    def complete(self, messages: Sequence[ChatMessage], max_tokens: int, timeout: float | None = None) -> LlmReply:
        """Срок — на весь вызов (ожидание очереди и ответ); не больше настроенного."""
        limit = self._timeout if timeout is None else min(self._timeout, timeout)
        deadline = self._monotonic() + limit
        if not self._slots.acquire(timeout=max(0.0, min(self._queue_wait, limit))):
            raise LlmBusyError()
        try:
            body = {
                "model": self._model,
                "messages": [{"role": m.role, "content": m.content} for m in messages],
                "stream": False,
                "format": "json",
                "options": {"temperature": 0, "num_predict": max_tokens, "num_ctx": self._num_ctx},
                "keep_alive": self._keep_alive,
            }
            return _parse_chat(self._post(body, deadline))
        finally:
            self._slots.release()

    def preload(self) -> bool:
        """Загрузить модель в память заранее, чтобы первый документ не упёрся в таймаут.
        Сбой не ошибка: Ollama могут запустить позже, тогда модель загрузится на первом запросе."""
        # тот же num_ctx, что у запросов: с другим окном Ollama перезагрузила бы модель на первом документе
        body = {
            "model": self._model,
            "messages": [],
            "stream": False,
            "options": {"num_ctx": self._num_ctx},
            "keep_alive": self._keep_alive,
        }
        try:
            raise_for_status(self._post(body, self._monotonic() + PRELOAD_TIMEOUT_SECONDS))
        except LlmError as exc:
            # не запущена (LLM_UNAVAILABLE) или модель не скачана (LLM_REQUEST_REJECTED) — без текста ошибки
            logging.getLogger("processor").warning("Локальная LLM не загружена: %s", exc.code)
            return False
        return True

    def _post(self, body: dict, deadline: float) -> HttpResult:
        return send(self._http, "POST", f"{self._url}/api/chat", deadline, self._monotonic, json=body)


def _parse_chat(response: HttpResult) -> LlmReply:
    raise_for_status(response)
    payload = parse_json(response)
    message = payload.get("message")
    content = message.get("content") if isinstance(message, dict) else None
    if not isinstance(content, str) or len(content) > MAX_REPLY_CHARS:
        raise LlmBadResponseError()
    return LlmReply(
        content=content,
        prompt_tokens=non_negative_int(payload.get("prompt_eval_count")),
        completion_tokens=non_negative_int(payload.get("eval_count")),
    )
