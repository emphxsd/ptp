"""Ошибки обработки: у каждой есть машинный код и HTTP-статус для ответа API.
Сообщения не должны содержать данных из документа (ПДн)."""
from typing import ClassVar


class ProcessingError(Exception):
    code: ClassVar[str] = "PROCESSING_ERROR"
    http_status: ClassVar[int] = 422

    def __init__(self, message: str):
        super().__init__(message)
        self.message = message


class EmptyFileError(ProcessingError):
    code = "EMPTY_FILE"
    http_status = 400


class UnauthorizedError(ProcessingError):
    code = "UNAUTHORIZED"
    http_status = 401


class FileTooLargeError(ProcessingError):
    code = "FILE_TOO_LARGE"
    http_status = 413


class UnsupportedFormatError(ProcessingError):
    code = "UNSUPPORTED_FORMAT"
    http_status = 415


class DocumentReadError(ProcessingError):
    code = "DOCUMENT_READ_ERROR"


class PasswordProtectedError(ProcessingError):
    code = "PASSWORD_PROTECTED"


class UnsafeDocumentError(ProcessingError):
    """Документ похож на zip-бомбу или превышает структурные лимиты."""

    code = "UNSAFE_DOCUMENT"


class TooManyPagesError(ProcessingError):
    code = "TOO_MANY_PAGES"


class TextTooLargeError(ProcessingError):
    code = "TEXT_TOO_LARGE"


class NoTextLayerError(ProcessingError):
    code = "NO_TEXT_LAYER"


class EmptyDocumentError(ProcessingError):
    code = "EMPTY_DOCUMENT"


class InvalidTemplateError(ProcessingError):
    code = "INVALID_TEMPLATE"
