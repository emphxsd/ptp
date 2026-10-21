"""Ошибки обращения к LLM. Это не ошибки запроса пользователя: конвейер продолжает работу без LLM
и сообщает код в warnings. Сообщения фиксированные — без ключей, токенов и текста документа."""
from typing import ClassVar


class LlmError(Exception):
    code: ClassVar[str] = "LLM_ERROR"
    default_message: ClassVar[str] = "Ошибка обращения к LLM"

    def __init__(self, message: str | None = None):
        super().__init__(message or self.default_message)


class LlmUnavailableError(LlmError):
    code = "LLM_UNAVAILABLE"
    default_message = "LLM недоступна (сеть, таймаут или ошибка сервера)"


class LlmBusyError(LlmError):
    code = "LLM_BUSY"
    default_message = "LLM занята: превышено число одновременных запросов"


class LlmQuotaError(LlmError):
    code = "LLM_QUOTA_EXCEEDED"
    default_message = "Закончились токены LLM"


class LlmAuthError(LlmError):
    code = "LLM_AUTH_FAILED"
    default_message = "LLM отклонила ключ авторизации"


class LlmRequestRejectedError(LlmError):
    code = "LLM_REQUEST_REJECTED"
    default_message = "LLM отклонила запрос (модель или параметры)"


class LlmBadResponseError(LlmError):
    code = "LLM_BAD_RESPONSE"
    default_message = "Некорректный ответ LLM"


class LlmRefusedError(LlmError):
    code = "LLM_REFUSED"
    default_message = "LLM отказалась отвечать на запрос"
