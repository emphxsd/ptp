"""Настройки сервиса. Значения проверяются при создании, чтобы ошибка конфигурации всплывала при старте."""
import math
import os
import re
from collections.abc import Mapping
from functools import lru_cache
from urllib.parse import urlsplit

from typing import Literal

from app.extraction.ocr import LANGUAGES_PATTERN
from app.llm.urls import is_loopback_url, require_local_or_https
from pydantic import BaseModel, ConfigDict, Field, SecretStr, field_validator, model_validator

BYTES_IN_MB = 1024 * 1024
ENV_PREFIX = "PROCESSOR_"
# запас на служебные части multipart (поле template, заголовки, boundary)
MULTIPART_OVERHEAD_BYTES = 64 * 1024
UTF8_MAX_BYTES_PER_CHAR = 4
# ключ авторизации GigaChat — Base64; иное — ошибка копирования (и сбой кодирования заголовка)
_AUTH_KEY = re.compile(r"^[A-Za-z0-9+/=_-]{8,4096}$")


class Settings(BaseModel):
    model_config = ConfigDict(frozen=True)

    max_file_bytes: int = Field(default=20 * BYTES_IN_MB, gt=0, le=200 * BYTES_IN_MB)
    # минимум «значимых» символов, чтобы страница PDF считалась текстовой (а не сканом)
    min_chars_per_page: int = Field(default=20, ge=0)
    max_pages: int = Field(default=300, gt=0)
    max_text_chars: int = Field(default=2_000_000, gt=0)
    max_template_chars: int = Field(default=64 * 1024, gt=0)
    # защита от zip-бомб и «раздутых» DOCX
    docx_max_entries: int = Field(default=1000, gt=0)
    docx_max_unpacked_bytes: int = Field(default=100 * BYTES_IN_MB, gt=0)
    docx_max_compression_ratio: int = Field(default=100, gt=0)
    docx_max_table_rows: int = Field(default=5000, gt=0)
    # значения с уверенностью ниже порога помечаются «требует проверки» (и позже уходят в LLM)
    review_threshold: float = Field(default=0.6, gt=0.0, lt=1.0)
    # этапы каскада можно отключать — для сравнения подходов в экспериментах
    ner_enabled: bool = True
    # этап LLM: ollama — локальная модель (документ не покидает сервер), gigachat — облако, нужен ключ;
    # llm_enabled=false — отключить для экспериментов
    llm_enabled: bool = True
    llm_provider: Literal["gigachat", "ollama"] = "gigachat"
    # Ollama: по http — только этот компьютер; num_ctx — окно модели в токенах (весь отправляемый текст)
    ollama_url: str = "http://127.0.0.1:11434"
    ollama_model: str = Field(default="qwen2.5:7b", pattern=r"^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$")
    ollama_num_ctx: int = Field(default=8192, ge=2048, le=131_072)
    ollama_keep_alive: str = Field(default="30m", pattern=r"^(-1|\d{1,6}[smh]?)$")
    # ключ авторизации GigaChat (Base64 от Client ID:Client Secret) — только из окружения, в логи не попадает
    gigachat_auth_key: SecretStr | None = None
    gigachat_scope: Literal["GIGACHAT_API_PERS", "GIGACHAT_API_B2B", "GIGACHAT_API_CORP"] = "GIGACHAT_API_PERS"
    gigachat_model: str = Field(default="GigaChat-2-Pro", pattern=r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
    gigachat_oauth_url: str = "https://ngw.devices.sberbank.ru:9443/api/v2/oauth"
    gigachat_api_url: str = "https://api.giga.chat/v1"
    # путь к корневому сертификату Минцифры (PEM), которым подписаны серверы GigaChat
    gigachat_ca_bundle: str | None = None
    # общий бюджет обработки документа — 30 с, из них на LLM не больше
    llm_timeout_seconds: float = Field(default=20.0, gt=0, le=60)
    # сколько символов документа отправлять в LLM (длинный документ — начало и окна вокруг якорей)
    llm_max_input_chars: int = Field(default=12_000, ge=1000, le=100_000)
    llm_max_output_tokens: int = Field(default=1024, ge=64, le=8192)
    # у физлиц GigaChat — один поток генерации
    llm_max_concurrent: int = Field(default=1, gt=0, le=10)
    llm_queue_wait_seconds: float = Field(default=5.0, gt=0, le=60)
    # OCR сканов и изображений (Tesseract): путь к программе, языки, качество рендеринга и лимиты
    ocr_enabled: bool = True
    tesseract_cmd: str = Field(default="tesseract", min_length=1, max_length=500)
    ocr_languages: str = Field(default="rus+eng", pattern=rf"^{LANGUAGES_PATTERN}$")
    ocr_dpi: int = Field(default=300, ge=72, le=600)
    ocr_max_pages: int = Field(default=20, gt=0, le=300)
    # общий срок на распознавание всех страниц документа
    ocr_timeout_seconds: float = Field(default=20.0, gt=0, le=300)
    # защита памяти: больше пикселей на страницу не рендерим и не распознаём (A4 при 300 dpi — 8,7 млн)
    # Tesseract (leptonica) держит изображение в памяти ~4 байта на пиксель: 25 млн — около 100 МБ на запрос
    ocr_max_pixels: int = Field(default=25_000_000, ge=100_000, le=200_000_000)
    # средняя уверенность Tesseract ниже порога — предупреждение и проверка всех полей человеком
    ocr_min_confidence: float = Field(default=60.0, ge=0, le=100)
    # общий срок обработки документа (цель — не больше 30 с): OCR и LLM получают оставшееся время,
    # LLM пропускается, если осталось меньше llm_min_seconds
    processing_budget_seconds: float = Field(default=30.0, gt=0, le=600)
    llm_min_seconds: float = Field(default=5.0, gt=0, le=60)
    # одновременных /extract на процесс и сколько ждать свободного места, прежде чем ответить 503
    max_concurrent_extractions: int = Field(default=4, gt=0, le=64)
    queue_wait_seconds: float = Field(default=10.0, gt=0, le=120)
    # production: токен обязателен, документация API отключена (fail closed)
    environment: Literal["development", "production"] = "development"
    # если задан, /extract требует заголовок Authorization: Bearer <token>
    api_token: str | None = None
    docs_enabled: bool = True

    @field_validator("gigachat_auth_key")
    @classmethod
    def auth_key_format(cls, value: SecretStr | None) -> SecretStr | None:
        if value is not None and value.get_secret_value() and not _AUTH_KEY.match(value.get_secret_value()):
            raise ValueError("ключ GigaChat должен быть строкой Base64 из личного кабинета")
        return value

    @field_validator("gigachat_oauth_url", "gigachat_api_url")
    @classmethod
    def https_without_credentials(cls, url: str) -> str:
        parts = urlsplit(url)
        if parts.scheme != "https" or not parts.hostname or parts.username is not None or parts.password is not None:
            raise ValueError("адрес GigaChat должен иметь вид https://хост/путь")
        return url

    @field_validator("ollama_url")
    @classmethod
    def ollama_local_or_https(cls, url: str) -> str:
        return require_local_or_https(url)

    @model_validator(mode="after")
    def production_requires_token(self) -> "Settings":
        if self.environment == "production" and not self.api_token:
            raise ValueError(f"в режиме production нужно задать {ENV_PREFIX}API_TOKEN")
        return self

    @property
    def llm_configured(self) -> bool:
        if not self.llm_enabled:
            return False
        if self.llm_provider == "ollama":
            return True
        return self.gigachat_auth_key is not None and bool(self.gigachat_auth_key.get_secret_value())

    @property
    def llm_is_local(self) -> bool:
        """Документ не покидает компьютер: Ollama на этом же компьютере."""
        return self.llm_provider == "ollama" and is_loopback_url(self.ollama_url)

    @property
    def docs_available(self) -> bool:
        return self.docs_enabled and self.environment != "production"

    @property
    def max_file_mb(self) -> float:
        return self.max_file_bytes / BYTES_IN_MB

    @property
    def max_request_bytes(self) -> int:
        return self.max_file_bytes + self.max_template_chars * UTF8_MAX_BYTES_PER_CHAR + MULTIPART_OVERHEAD_BYTES

    @classmethod
    def from_env(cls, env: Mapping[str, str] | None = None) -> "Settings":
        env = os.environ if env is None else env
        values: dict[str, object] = {}
        if (raw_mb := env.get(f"{ENV_PREFIX}MAX_FILE_MB")) is not None:
            values["max_file_bytes"] = _megabytes_to_bytes(raw_mb)
        for name in (
            "min_chars_per_page",
            "max_pages",
            "max_text_chars",
            "max_template_chars",
            "docx_max_table_rows",
            "review_threshold",
            "llm_provider",
            "ollama_url",
            "ollama_model",
            "ollama_num_ctx",
            "ollama_keep_alive",
            "gigachat_scope",
            "gigachat_model",
            "gigachat_oauth_url",
            "gigachat_api_url",
            "llm_timeout_seconds",
            "llm_max_input_chars",
            "llm_max_output_tokens",
            "llm_max_concurrent",
            "llm_queue_wait_seconds",
            "tesseract_cmd",
            "ocr_languages",
            "ocr_dpi",
            "ocr_max_pages",
            "ocr_timeout_seconds",
            "ocr_max_pixels",
            "ocr_min_confidence",
            "processing_budget_seconds",
            "llm_min_seconds",
        ):
            if (raw := env.get(f"{ENV_PREFIX}{name.upper()}")) is not None:
                values[name] = raw
        if token := env.get(f"{ENV_PREFIX}API_TOKEN"):
            values["api_token"] = token
        if auth_key := env.get(f"{ENV_PREFIX}GIGACHAT_AUTH_KEY", "").strip():
            values["gigachat_auth_key"] = auth_key
        if ca_bundle := env.get(f"{ENV_PREFIX}GIGACHAT_CA_BUNDLE", "").strip():
            values["gigachat_ca_bundle"] = ca_bundle
        if environment := env.get(f"{ENV_PREFIX}ENV"):
            values["environment"] = environment.strip().lower()
        for flag in ("docs_enabled", "ner_enabled", "llm_enabled", "ocr_enabled"):
            if (raw_flag := env.get(f"{ENV_PREFIX}{flag.upper()}")) is not None:
                values[flag] = raw_flag.strip().lower() in ("1", "true", "yes", "on")
        return cls(**values)


def _megabytes_to_bytes(raw: str) -> int:
    megabytes = float(raw)  # ValueError для нечисловых значений
    if not math.isfinite(megabytes) or megabytes <= 0:
        raise ValueError(f"{ENV_PREFIX}MAX_FILE_MB должен быть положительным числом, получено: {raw!r}")
    return int(megabytes * BYTES_IN_MB)


@lru_cache
def get_settings() -> Settings:
    return Settings.from_env()
