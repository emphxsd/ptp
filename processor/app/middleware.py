"""ASGI-middleware: ограничение размера тела запроса ДО разбора multipart.

Starlette сохраняет загружаемые части во временные файлы ещё до вызова обработчика,
поэтому проверка размера внутри эндпоинта не защищает диск и время. Здесь запрос
отклоняется по Content-Length сразу, а при chunked-передаче — как только принято больше лимита."""
import json

from starlette.types import ASGIApp, Message, Receive, Scope, Send

from app.schemas import ApiResponse


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


async def _send_too_large(send: Send) -> None:
    body = ApiResponse.fail("FILE_TOO_LARGE", "Запрос слишком большой").model_dump()
    payload = json.dumps(body, ensure_ascii=False).encode("utf-8")
    await send(
        {
            "type": "http.response.start",
            "status": 413,
            "headers": [
                (b"content-type", b"application/json; charset=utf-8"),
                (b"content-length", str(len(payload)).encode()),
                (b"cache-control", b"no-store"),
            ],
        }
    )
    await send({"type": "http.response.body", "body": payload})
