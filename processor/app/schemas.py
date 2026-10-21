"""Схемы API: шаблон извлечения (вход) и результат обработки (выход)."""
import re
from enum import Enum
from typing import Annotated, Generic, Literal, TypeVar

from pydantic import BaseModel, ConfigDict, Field, StringConstraints, field_validator, model_validator

from app.extraction.file_types import DocumentFormat
from app.fields.user_regex import UserRegexError, compile_user_regex

T = TypeVar("T")

_CONTROL_CHARS = re.compile(r"[\x00-\x08\x0b-\x1f\x7f]")

Keyword = Annotated[str, StringConstraints(strip_whitespace=True, min_length=1, max_length=100)]
NerLabel = Literal["PER", "ORG", "LOC"]
MIN_KEYWORD_CHARS = 2
# каждое поле с regex — отдельный проход RE2; ограничиваем суммарное время обработки
MAX_REGEX_FIELDS = 20


class DataType(str, Enum):
    STRING = "string"
    DATE = "date"
    AMOUNT = "amount"
    NUMBER = "number"
    INN = "inn"
    KPP = "kpp"
    BIK = "bik"
    PHONE = "phone"
    EMAIL = "email"
    PERSON = "person"
    ORGANIZATION = "organization"


class TemplateField(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid")

    name: str = Field(pattern=r"^[a-z][a-z0-9_]{0,63}$", description="Системное имя поля (ключ в JSON)")
    label: str = Field(min_length=1, max_length=150, description="Название поля для пользователя")
    data_type: DataType = DataType.STRING
    is_required: bool = False
    keywords: tuple[Keyword, ...] = Field(default=(), max_length=20, description="Слова-якоря рядом со значением")
    # выполняется через RE2 (линейное время, без ReDoS); группа 1, если есть, — само значение
    regex_pattern: str | None = Field(default=None, min_length=1, max_length=300)
    ner_label: NerLabel | None = None
    llm_hint: str | None = Field(default=None, max_length=500)

    @field_validator("keywords")
    @classmethod
    def keywords_must_be_meaningful(cls, keywords: tuple[str, ...]) -> tuple[str, ...]:
        # «*» в конце — поиск по началу слова (поставщик* → поставщиком); якорь — от 2 символов с буквой/цифрой
        for keyword in keywords:
            core = keyword[:-1] if keyword.endswith("*") else keyword
            if len(core) < MIN_KEYWORD_CHARS or "*" in core or not any(ch.isalnum() for ch in core):
                raise ValueError(f"слово-якорь должно содержать не меньше {MIN_KEYWORD_CHARS} символов и букву или цифру")
        return keywords

    @field_validator("label", "llm_hint")
    @classmethod
    def no_control_chars(cls, value: str | None) -> str | None:
        if value is not None and _CONTROL_CHARS.search(value):
            raise ValueError("недопустимые управляющие символы")
        return value

    @field_validator("regex_pattern")
    @classmethod
    def regex_must_compile(cls, value: str | None) -> str | None:
        if value is not None:
            try:
                compile_user_regex(value)
            except UserRegexError as exc:
                raise ValueError(str(exc)) from None
        return value


class Template(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid")

    name: str = Field(default="", max_length=150)
    fields: tuple[TemplateField, ...] = Field(min_length=1, max_length=100)

    @field_validator("fields")
    @classmethod
    def field_names_must_be_unique(cls, fields: tuple[TemplateField, ...]) -> tuple[TemplateField, ...]:
        names = [f.name for f in fields]
        duplicates = sorted({n for n in names if names.count(n) > 1})
        if duplicates:
            raise ValueError(f"повторяются имена полей: {', '.join(duplicates)}")
        if sum(1 for f in fields if f.regex_pattern) > MAX_REGEX_FIELDS:
            raise ValueError(f"не больше {MAX_REGEX_FIELDS} полей с регулярным выражением")
        return fields


class FieldResult(BaseModel):
    model_config = ConfigDict(frozen=True)

    name: str
    value: str | None = Field(description="Значение в нормализованном виде (дата — ГГГГ-ММ-ДД, сумма — 15000.00)")
    source: Literal["rule", "ner", "llm"] | None
    raw: str | None = Field(description="Фрагмент текста документа, из которого взято значение (для подсветки)")
    start: int | None = Field(default=None, description="Позиция raw в извлечённом тексте (начало)")
    end: int | None = Field(default=None, description="Позиция raw в извлечённом тексте (конец, не включая)")
    confidence: float = Field(ge=0.0, le=1.0)
    needs_review: bool
    alternatives: tuple[str, ...] = Field(default=(), description="Другие найденные варианты при неоднозначности")


class DocumentInfo(BaseModel):
    model_config = ConfigDict(frozen=True)

    format: DocumentFormat
    pages: int | None
    pages_without_text: int
    has_text_layer: bool
    chars: int


class ExtractionResult(BaseModel):
    model_config = ConfigDict(frozen=True)

    document: DocumentInfo
    fields: tuple[FieldResult, ...]
    llm_used: bool
    processing_ms: int
    text: str | None = None


class HealthStatus(BaseModel):
    status: Literal["ok"]


class ApiError(BaseModel):
    code: str
    message: str


class ApiResponse(BaseModel, Generic[T]):
    """Единый конверт ответа: success + data или error."""

    success: bool
    data: T | None = None
    error: ApiError | None = None

    @model_validator(mode="after")
    def consistent(self) -> "ApiResponse[T]":
        if self.success and self.error is not None:
            raise ValueError("успешный ответ не может содержать ошибку")
        if not self.success and (self.error is None or self.data is not None):
            raise ValueError("ответ с ошибкой должен содержать error и не содержать data")
        return self

    @classmethod
    def ok(cls, data: T) -> "ApiResponse[T]":
        return cls(success=True, data=data)

    @classmethod
    def fail(cls, code: str, message: str) -> "ApiResponse[None]":
        return ApiResponse[None](success=False, error=ApiError(code=code, message=message))
