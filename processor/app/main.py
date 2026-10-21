"""Сборка приложения. Запуск: uvicorn app.main:app --reload"""
from fastapi import FastAPI

from app import __version__
from app.api.errors import register_error_handlers
from app.api.routes import router
from app.config import Settings, get_settings
from app.middleware import BodySizeLimitMiddleware


def create_app(settings: Settings | None = None) -> FastAPI:
    # настройки читаются здесь, при старте: некорректная конфигурация сразу останавливает сервис
    settings = settings or get_settings()
    docs = settings.docs_enabled
    app = FastAPI(
        title="Сервис обработки документов",
        version=__version__,
        docs_url="/docs" if docs else None,
        redoc_url="/redoc" if docs else None,
        openapi_url="/openapi.json" if docs else None,
    )
    app.state.settings = settings
    app.add_middleware(BodySizeLimitMiddleware, max_body_bytes=settings.max_request_bytes)
    register_error_handlers(app)
    app.include_router(router)
    return app


app = create_app()
