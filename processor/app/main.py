"""Сборка приложения. Запуск: uvicorn app.main:app --reload"""
import asyncio
import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.concurrency import run_in_threadpool

from app import __version__
from app.fields.ner import get_recognizer
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
        yield

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
    app.state.extract_slots = asyncio.Semaphore(settings.max_concurrent_extractions)
    # порядок: последний добавленный — внешний; ошибки ловятся внутри лимита тела
    app.add_middleware(UnhandledErrorMiddleware)
    app.add_middleware(BodySizeLimitMiddleware, max_body_bytes=settings.max_request_bytes)
    register_error_handlers(app)
    app.include_router(router)
    return app


app = create_app()
