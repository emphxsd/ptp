"""Создание LLM-клиента по настройкам. None — этап LLM выключен или (для GigaChat) ключ не задан."""
from app.config import Settings
from app.llm.client import LlmClient
from app.llm.gigachat import GigaChatClient
from app.llm.ollama import OllamaClient


def build_llm_client(settings: Settings) -> LlmClient | None:
    if not settings.llm_configured:
        return None
    if settings.llm_provider == "ollama":
        return OllamaClient(
            settings.ollama_url,
            model=settings.ollama_model,
            timeout=settings.llm_timeout_seconds,
            num_ctx=settings.ollama_num_ctx,
            keep_alive=settings.ollama_keep_alive,
            max_concurrent=settings.llm_max_concurrent,
            queue_wait=settings.llm_queue_wait_seconds,
        )
    if settings.gigachat_auth_key is None:
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
