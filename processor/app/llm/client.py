"""Общий интерфейс LLM-клиента: этап извлечения не зависит от конкретного провайдера (GigaChat, Ollama)."""
from collections.abc import Sequence
from dataclasses import dataclass
from typing import Literal, Protocol


@dataclass(frozen=True)
class ChatMessage:
    role: Literal["system", "user"]
    content: str


@dataclass(frozen=True)
class LlmReply:
    content: str
    prompt_tokens: int = 0
    completion_tokens: int = 0


class LlmClient(Protocol):
    def complete(self, messages: Sequence[ChatMessage], max_tokens: int, timeout: float | None = None) -> LlmReply:
        """Один запрос к модели; timeout — не дольше стольких секунд (остаток общего срока документа).
        Ошибки — подклассы LlmError."""
        ...
