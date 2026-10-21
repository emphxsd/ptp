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
    def complete(self, messages: Sequence[ChatMessage], max_tokens: int) -> LlmReply:
        """Один запрос к модели. Ошибки — подклассы LlmError."""
        ...
