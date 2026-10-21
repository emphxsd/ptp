"""Маршруты API. Обработчики тонкие: разбор входа, вызов конвейера, упаковка в конверт."""
import asyncio
import hmac

from fastapi import APIRouter, Depends, File, Form, Request, Response, UploadFile
from fastapi.concurrency import run_in_threadpool
from pydantic import ValidationError

from app.config import Settings
from app.errors import InvalidTemplateError, ServiceBusyError, UnauthorizedError
from app.pipeline import process_document
from app.schemas import ApiResponse, ExtractionResult, HealthStatus, Template

router = APIRouter()

ERROR_RESPONSES = {status: {"model": ApiResponse[None]} for status in (400, 401, 413, 415, 422, 500)}


def get_app_settings(request: Request) -> Settings:
    return request.app.state.settings


def require_api_token(request: Request, settings: Settings = Depends(get_app_settings)) -> None:
    if not settings.api_token:
        return
    supplied = request.headers.get("authorization", "").encode()
    expected = f"Bearer {settings.api_token}".encode()
    if not hmac.compare_digest(supplied, expected):
        raise UnauthorizedError("Требуется корректный токен доступа")


def parse_template(raw: str, max_chars: int) -> Template:
    if len(raw) > max_chars:
        raise InvalidTemplateError("Шаблон слишком большой")
    try:
        return Template.model_validate_json(raw)
    except ValidationError as exc:
        details = "; ".join(
            f"{'.'.join(map(str, e['loc'])) or 'template'}: {e['msg']}"
            for e in exc.errors(include_input=False, include_url=False)
        )
        raise InvalidTemplateError(f"Некорректный шаблон: {details}") from None
    except (ValueError, RecursionError):
        raise InvalidTemplateError("Шаблон не является корректным JSON") from None


@router.get("/health", response_model=ApiResponse[HealthStatus])
async def health(request: Request) -> ApiResponse[HealthStatus]:
    ner = request.app.state.ner_status
    return ApiResponse.ok(HealthStatus(status="degraded" if ner == "unavailable" else "ok", ner=ner))


@router.post(
    "/extract",
    response_model=ApiResponse[ExtractionResult],
    responses=ERROR_RESPONSES,
    dependencies=[Depends(require_api_token)],
)
async def extract(
    request: Request,
    response: Response,
    file: UploadFile = File(description="Документ: PDF, DOCX, PNG или JPG"),
    template: str = Form(description="Шаблон извлечения в формате JSON"),
    include_text: bool = Form(False, description="Вернуть извлечённый текст"),
    settings: Settings = Depends(get_app_settings),
) -> ApiResponse[ExtractionResult]:
    response.headers["Cache-Control"] = "no-store"
    # проверка шаблона компилирует regex через RE2 — не блокируем event loop
    parsed_template = await run_in_threadpool(parse_template, template, settings.max_template_chars)
    data = await file.read()
    slots: asyncio.Semaphore = request.app.state.extract_slots
    try:
        await asyncio.wait_for(slots.acquire(), timeout=settings.queue_wait_seconds)
    except TimeoutError:
        raise ServiceBusyError("Сервис перегружен, повторите запрос позже") from None
    try:
        result = await run_in_threadpool(process_document, data, parsed_template, settings, include_text)
    finally:
        slots.release()
    return ApiResponse.ok(result)
