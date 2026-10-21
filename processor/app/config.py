"""Настройки сервиса. Значения проверяются при создании, чтобы ошибка конфигурации всплывала при старте."""
import math
import os
from collections.abc import Mapping
from functools import lru_cache

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator

BYTES_IN_MB = 1024 * 1024
ENV_PREFIX = "PROCESSOR_"
# запас на служебные части multipart (поле template, заголовки, boundary)
MULTIPART_OVERHEAD_BYTES = 64 * 1024
UTF8_MAX_BYTES_PER_CHAR = 4


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
    # одновременных /extract на процесс и сколько ждать свободного места, прежде чем ответить 503
    max_concurrent_extractions: int = Field(default=4, gt=0, le=64)
    queue_wait_seconds: float = Field(default=10.0, gt=0, le=120)
    # production: токен обязателен, документация API отключена (fail closed)
    environment: Literal["development", "production"] = "development"
    # если задан, /extract требует заголовок Authorization: Bearer <token>
    api_token: str | None = None
    docs_enabled: bool = True

    @model_validator(mode="after")
    def production_requires_token(self) -> "Settings":
        if self.environment == "production" and not self.api_token:
            raise ValueError(f"в режиме production нужно задать {ENV_PREFIX}API_TOKEN")
        return self

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
        ):
            if (raw := env.get(f"{ENV_PREFIX}{name.upper()}")) is not None:
                values[name] = raw
        if token := env.get(f"{ENV_PREFIX}API_TOKEN"):
            values["api_token"] = token
        if environment := env.get(f"{ENV_PREFIX}ENV"):
            values["environment"] = environment.strip().lower()
        for flag in ("docs_enabled", "ner_enabled"):
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
