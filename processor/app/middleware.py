"""ASGI-middleware: ограничение размера тела запроса ДО разбора multipart.

Starlette сохраняет загружаемые части во временные файлы ещё до вызова обработчика,
поэтому проверка размера внутри эндпоинта не защищает диск и время. Здесь запрос
отклоняется по Content-Length сразу, а при chunked-передаче — как только принято больше лимита."""
import json
import logging
import traceback

from starlette.types import ASGIApp, Message, Receive, Scope, Send

from app.schemas import ApiResponse

logger = logging.getLogger("processor")


class _BodyTooLarge(Exception):
    pass


class BodySizeLimitMiddleware:
    def __init__(self, app: ASGIApp, max_body_bytes: int):
        self.app = app
        self.max_body_bytes = max_body_bytes

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return
        if self._declared_length(scope) > self.max_body_bytes:
            await _send_too_large(send)
            return

        received = 0
        exceeded = False
        response_started = False

        async def limited_receive() -> Message:
            nonlocal received, exceeded
            message = await receive()
            if message["type"] == "http.request":
                received += len(message.get("body", b""))
                if received > self.max_body_bytes:
                    exceeded = True
                    raise _BodyTooLarge
            return message

        async def guarded_send(message: Message) -> None:
            nonlocal response_started
            if exceeded:
                # приложение могло превратить наше исключение в 400 — подменяем ответ на 413
                if message["type"] == "http.response.start" and not response_started:
                    response_started = True
                    await _send_too_large(send)
                return
            if message["type"] == "http.response.start":
                response_started = True
            await send(message)

        try:
            await self.app(scope, limited_receive, guarded_send)
        except _BodyTooLarge:
            if not response_started:
                await _send_too_large(send)

    @staticmethod
    def _declared_length(scope: Scope) -> int:
        for name, value in scope.get("headers", []):
            if name == b"content-length":
                return int(value) if value.isdigit() else 0
        return 0


class UnhandledErrorMiddleware:
    """Последний рубеж: непредвиденное исключение → 500 в едином конверте.

    Исключение не пробрасывается дальше: иначе ServerErrorMiddleware Starlette передаст его
    uvicorn, а тот запишет в лог текст исключения — он может содержать данные документа (ПДн).
    В лог пишутся только тип исключения и стек вызовов."""

    def __init__(self, app: ASGIApp):
        self.app = app

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return
        response_started = False

        async def tracking_send(message: Message) -> None:
            nonlocal response_started
            if message["type"] == "http.response.start":
                response_started = True
            await send(message)

        try:
            await self.app(scope, receive, tracking_send)
        except Exception as exc:
            # кадры без строк исходного кода: в них тоже могут оказаться данные
            stack = "\n".join(
                f"  {frame.filename}:{frame.lineno} in {frame.name}" for frame in traceback.extract_tb(exc.__traceback__)
            )
            logger.error("Необработанная ошибка %s\n%s", type(exc).__name__, stack)
            if not response_started:
                await _send_json(send, 500, "INTERNAL_ERROR", "Внутренняя ошибка сервиса")


async def _send_too_large(send: Send) -> None:
    await _send_json(send, 413, "FILE_TOO_LARGE", "Запрос слишком большой")


async def _send_json(send: Send, status: int, code: str, message: str) -> None:
    body = ApiResponse.fail(code, message).model_dump()
    payload = json.dumps(body, ensure_ascii=False).encode("utf-8")
    await send(
        {
            "type": "http.response.start",
            "status": status,
            "headers": [
                (b"content-type", b"application/json; charset=utf-8"),
                (b"content-length", str(len(payload)).encode()),
                (b"cache-control", b"no-store"),
            ],
        }
    )
    await send({"type": "http.response.body", "body": payload})
