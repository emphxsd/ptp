"""Создание LLM-клиента по настройкам. None — этап LLM выключен или ключ не задан."""
from app.config import Settings
from app.llm.client import LlmClient
from app.llm.gigachat import GigaChatClient


def build_llm_client(settings: Settings) -> LlmClient | None:
    if not settings.llm_configured or settings.gigachat_auth_key is None:
        return None
    return GigaChatClient(
        settings.gigachat_auth_key.get_secret_value(),
        scope=settings.gigachat_scope,
        model=settings.gigachat_model,
        oauth_url=settings.gigachat_oauth_url,
        api_url=settings.gigachat_api_url,
        ca_bundle=settings.gigachat_ca_bundle,
        timeout=settings.llm_timeout_seconds,
        max_concurrent=settings.llm_max_concurrent,
        queue_wait=settings.llm_queue_wait_seconds,
    )
