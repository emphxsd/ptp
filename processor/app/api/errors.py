"""Обработчики ошибок: любой ответ — в едином конверте; в логи не попадают данные документов."""
import logging
from http import HTTPStatus

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

from app.errors import ProcessingError
from app.schemas import ApiResponse

logger = logging.getLogger("processor")

_HTTP_CODES = {
    400: "BAD_REQUEST",
    401: "UNAUTHORIZED",
    403: "FORBIDDEN",
    404: "NOT_FOUND",
    405: "METHOD_NOT_ALLOWED",
    413: "FILE_TOO_LARGE",
}
_NO_STORE = {"Cache-Control": "no-store"}


def error_response(status: int, code: str, message: str, headers: dict[str, str] | None = None) -> JSONResponse:
    body = ApiResponse.fail(code, message).model_dump()
    return JSONResponse(status_code=status, content=body, headers={**_NO_STORE, **(headers or {})})


def register_error_handlers(app: FastAPI) -> None:
    @app.exception_handler(ProcessingError)
    async def processing_error(_: Request, exc: ProcessingError) -> JSONResponse:
        logger.info("Запрос отклонён: %s (%s)", exc.code, exc.http_status)
        return error_response(exc.http_status, exc.code, exc.message)

    @app.exception_handler(RequestValidationError)
    async def validation_error(_: Request, exc: RequestValidationError) -> JSONResponse:
        details = "; ".join(f"{'.'.join(map(str, e['loc']))}: {e['msg']}" for e in exc.errors())
        return error_response(422, "VALIDATION_ERROR", f"Некорректный запрос: {details}")

    @app.exception_handler(StarletteHTTPException)
    async def http_error(_: Request, exc: StarletteHTTPException) -> JSONResponse:
        code = _HTTP_CODES.get(exc.status_code, f"HTTP_{exc.status_code}")
        message = exc.detail if isinstance(exc.detail, str) else HTTPStatus(exc.status_code).phrase
        return error_response(exc.status_code, code, message, headers=dict(exc.headers or {}))

    # непредвиденные исключения обрабатывает UnhandledErrorMiddleware (app/middleware.py):
    # обработчик Exception в FastAPI всё равно пробрасывает исключение в uvicorn, и тот логирует его текст
