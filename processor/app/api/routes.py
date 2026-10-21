"""Маршруты API. Обработчики тонкие: разбор входа, вызов конвейера, упаковка в конверт."""
import asyncio
import hmac
from typing import Literal

from fastapi import APIRouter, Depends, File, Form, Request, Response, UploadFile
from fastapi.concurrency import run_in_threadpool
from pydantic import ValidationError

from app.config import Settings
from app.errors import InvalidTemplateError, ServiceBusyError, UnauthorizedError
from app.pipeline import process_document
from app.schemas import ApiResponse, ExtractionResult, HealthStatus, Template, TemplateCheck, TemplateIssue

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


_VALUE_ERROR_PREFIX = "Value error, "


def _template_issues(exc: ValidationError) -> tuple[TemplateIssue, ...]:
    """Ошибки схемы без входных данных: где (fields.1.regex_pattern) и что (текст без префикса Pydantic)."""
    errors = exc.errors(include_input=False, include_url=False)
    has_field_errors = any(len(e["loc"]) > 1 and e["loc"][0] == "fields" for e in errors)
    return tuple(
        TemplateIssue(
            field=".".join(map(str, e["loc"])) or "template",
            message=e["msg"].removeprefix(_VALUE_ERROR_PREFIX),
        )
        for e in errors
        # эхо ошибки поля: Pydantic отбрасывает неверный элемент и сообщает ещё и о слишком коротком списке
        if not (has_field_errors and e["type"] == "too_short" and tuple(e["loc"]) == ("fields",))
    )


def _validate_template(raw: str, max_chars: int) -> Template:
    """Разбор шаблона; ValidationError — ошибки схемы, InvalidTemplateError — размер или не JSON."""
    if len(raw) > max_chars:
        raise InvalidTemplateError("Шаблон слишком большой")
    try:
        return Template.model_validate_json(raw)
    except ValidationError as exc:
        if any(e["type"] == "json_invalid" for e in exc.errors()):
            raise InvalidTemplateError("Шаблон не является корректным JSON") from None
        raise
    except (ValueError, RecursionError):
        raise InvalidTemplateError("Шаблон не является корректным JSON") from None


def parse_template(raw: str, max_chars: int) -> Template:
    try:
        return _validate_template(raw, max_chars)
    except ValidationError as exc:
        details = "; ".join(f"{issue.field}: {issue.message}" for issue in _template_issues(exc))
        raise InvalidTemplateError(f"Некорректный шаблон: {details}") from None


def check_template(raw: str, max_chars: int) -> TemplateCheck:
    try:
        _validate_template(raw, max_chars)
    except ValidationError as exc:
        return TemplateCheck(valid=False, issues=_template_issues(exc))
    return TemplateCheck(valid=True)


@router.get("/health", response_model=ApiResponse[HealthStatus])
async def health(request: Request) -> ApiResponse[HealthStatus]:
    state = request.app.state
    ner, llm, ocr = state.ner_status, state.llm_status, state.ocr_status
    status = "degraded" if "unavailable" in (ner, llm, ocr) else "ok"
    return ApiResponse.ok(HealthStatus(status=status, ner=ner, llm=llm, ocr=ocr))


@router.post(
    "/templates/validate",
    response_model=ApiResponse[TemplateCheck],
    responses=ERROR_RESPONSES,
    dependencies=[Depends(require_api_token)],
)
async def validate_template(
    response: Response,
    template: str = Form(description="Шаблон извлечения в формате JSON"),
    settings: Settings = Depends(get_app_settings),
) -> ApiResponse[TemplateCheck]:
    """Проверка шаблона без документа: веб-приложение вызывает её при сохранении поля с регулярным выражением."""
    response.headers["Cache-Control"] = "no-store"
    # компиляция regex через RE2 — не блокируем event loop
    result = await run_in_threadpool(check_template, template, settings.max_template_chars)
    return ApiResponse.ok(result)


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
    # строка, а не bool: отсутствующее bool-поле формы FastAPI считает false (как чекбокс HTML)
    use_llm: Literal["true", "false"] = Form("true", description="Разрешить отправку фрагментов документа во внешнюю LLM"),
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
        result = await run_in_threadpool(
            process_document,
            data,
            parsed_template,
            settings,
            include_text,
            llm=request.app.state.llm_client if use_llm == "true" else None,
            ocr=request.app.state.ocr_engine,
        )
    finally:
        slots.release()
    return ApiResponse.ok(result)
