"""Сборка приложения. Запуск: uvicorn app.main:app --reload"""
import asyncio
import logging
import threading
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.concurrency import run_in_threadpool

from app import __version__
from app.extraction.ocr import TesseractEngine, check_tesseract, resolve_command
from app.fields.ner import get_recognizer
from app.llm.factory import build_llm_client
from app.llm.ollama import OllamaClient
from app.api.errors import register_error_handlers
from app.api.routes import router
from app.config import Settings, get_settings
from app.middleware import BodySizeLimitMiddleware, UnhandledErrorMiddleware


def create_app(settings: Settings | None = None) -> FastAPI:
    # настройки читаются здесь, при старте: некорректная конфигурация сразу останавливает сервис
    settings = settings or get_settings()
    docs = settings.docs_available

    @asynccontextmanager
    async def lifespan(_: FastAPI) -> AsyncIterator[None]:
        # модели NER (≈1 с) загружаются при старте, а не на первом запросе пользователя
        app.state.ner_status = "disabled"
        if settings.ner_enabled:
            try:
                await run_in_threadpool(get_recognizer)
                app.state.ner_status = "ready"
            except Exception as exc:  # сервис работает и без NER — на одних правилах, /health сообщит degraded
                app.state.ner_status = "unavailable"
                logging.getLogger("processor").warning("Модели NER не загружены: %s", type(exc).__name__)
        _start_llm(app, settings)
        await run_in_threadpool(_start_ocr, app, settings)
        try:
            yield
        finally:
            if app.state.llm_client is not None:
                app.state.llm_client.close()

    app = FastAPI(
        title="Сервис обработки документов",
        version=__version__,
        lifespan=lifespan,
        docs_url="/docs" if docs else None,
        redoc_url="/redoc" if docs else None,
        openapi_url="/openapi.json" if docs else None,
    )
    app.state.settings = settings
    app.state.ner_status = "disabled"
    app.state.llm_client = None
    app.state.llm_status = "disabled"
    app.state.ocr_engine = None
    app.state.ocr_status = "disabled"
    app.state.extract_slots = asyncio.Semaphore(settings.max_concurrent_extractions)
    # порядок: последний добавленный — внешний; ошибки ловятся внутри лимита тела
    app.add_middleware(UnhandledErrorMiddleware)
    app.add_middleware(BodySizeLimitMiddleware, max_body_bytes=settings.max_request_bytes)
    register_error_handlers(app)
    app.include_router(router)
    return app


def _start_ocr(app: FastAPI, settings: Settings) -> None:
    """Tesseract — внешняя программа: проверяем наличие и языки при старте, без него сканы не обрабатываются."""
    app.state.ocr_engine = None
    if not settings.ocr_enabled:
        app.state.ocr_status = "disabled"
        return
    # абсолютный путь: иначе Windows ищет tesseract.exe и в текущем каталоге
    cmd = resolve_command(settings.tesseract_cmd) or settings.tesseract_cmd
    if not check_tesseract(cmd, settings.ocr_languages):
        app.state.ocr_status = "unavailable"
        logging.getLogger("processor").warning("Tesseract не найден или нет языков %s", settings.ocr_languages)
        return
    app.state.ocr_engine = TesseractEngine(cmd, settings.ocr_languages)
    app.state.ocr_status = "ready"


def _start_llm(app: FastAPI, settings: Settings) -> None:
    """Клиент LLM создаётся при старте. Облачный API не вызывается (лимиты и токены);
    локальная модель загружается в память в фоне, не задерживая старт."""
    app.state.llm_client = None
    if not settings.llm_enabled:
        app.state.llm_status = "disabled"
        return
    try:
        app.state.llm_client = build_llm_client(settings)
    except (OSError, ValueError) as exc:  # например, не найден файл сертификата — работаем без LLM
        app.state.llm_status = "unavailable"
        logging.getLogger("processor").warning("LLM-клиент не создан: %s", type(exc).__name__)
        return
    app.state.llm_status = "ready" if app.state.llm_client is not None else "not_configured"
    if isinstance(app.state.llm_client, OllamaClient):
        threading.Thread(target=app.state.llm_client.preload, name="ollama-preload", daemon=True).start()


app = create_app()
