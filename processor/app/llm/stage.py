"""LLM-этап каскада: один запрос на все поля, которые правила и NER не нашли или нашли неуверенно.
Ответ модели проверяется по тексту документа (защита от галлюцинаций и от инструкций, внедрённых в документ):
- значение, не подтверждённое фрагментом документа, получает уверенность ниже порога;
- свободный текст без якоря поля тоже уходит на проверку человеку: документ может «подсунуть» модели
  правдоподобный фрагмент, а строгого формата, как у даты или ИНН, у такого значения нет."""
import logging
import re
from collections.abc import Sequence
from dataclasses import dataclass

from app.extraction.normalize import normalize_text
from app.fields.normalizers import normalize_organization, normalize_person, normalize_text_value
from app.fields.patterns import TYPE_NORMALIZERS, TYPE_RULES
from app.fields.rules import WINDOW_CHARS, DocumentIndex, RuleMatch
from app.llm.client import LlmClient
from app.llm.prompt import LlmAnswer, build_messages, parse_reply, select_context
from app.schemas import DataType, TemplateField

logger = logging.getLogger("processor")

# строгий формат (дата, ИНН…) рядом с якорем поля
CONFIDENCE_VERIFIED_NEAR_KEYWORD = 0.8
# строгий формат по цитате или свободный текст рядом с якорем
CONFIDENCE_VERIFIED = 0.75
# подтверждено текстом, но без якоря и без строгого формата, или найдено само значение без цитаты
CONFIDENCE_LOOSE = 0.55
CONFIDENCE_VALUE_ONLY = CONFIDENCE_LOOSE
# в тексте нет подтверждения
CONFIDENCE_UNVERIFIED = 0.4
# типы со строгим форматом: значение должно пройти тот же шаблон и проверку, что и у правил
STRICT_TYPES = frozenset(
    {DataType.DATE, DataType.AMOUNT, DataType.INN, DataType.KPP, DataType.BIK, DataType.PHONE, DataType.EMAIL}
)
MAX_PATTERN_MATCHES = 5
MAX_QUOTE_HITS = 20
MAX_NUMBER_CHARS = 30
# слово значения подтверждается словом фрагмента с тем же началом: «Петров» — «Петрова», «Павел» — «Павлу»
MIN_STEM_CHARS = 3
MAX_STEM_CHARS = 6
STEM_SUFFIX_CHARS = 2
SHORT_WORD_CHARS = 3
_WORD = re.compile(r"\w+")


@dataclass(frozen=True)
class LlmRequest:
    field: TemplateField
    candidates: tuple[str, ...] = ()  # значения, найденные предыдущими этапами, — модель их проверяет


@dataclass(frozen=True)
class LlmOutcome:
    matches: dict[str, RuleMatch]
    warnings: tuple[str, ...] = ()


@dataclass(frozen=True)
class _Hit:
    start: int
    end: int
    near: bool


class LlmStage:
    def __init__(
        self,
        client: LlmClient,
        index: DocumentIndex,
        max_input_chars: int,
        max_output_tokens: int,
        timeout: float | None = None,
    ):
        self._client = client
        self._timeout = timeout
        self._index = index
        self._max_input_chars = max_input_chars
        self._max_output_tokens = max_output_tokens

    def find(self, requests: Sequence[LlmRequest]) -> LlmOutcome:
        """Ошибки клиента (LlmError) пробрасываются: решение о продолжении без LLM — за конвейером."""
        fields = [r.field for r in requests]
        context, truncated = select_context(self._index.text, fields, self._index, self._max_input_chars)
        candidates = {r.field.name: r.candidates for r in requests if r.candidates}
        messages = build_messages(fields, candidates, context)
        reply = self._client.complete(messages, self._max_output_tokens, timeout=self._timeout)
        logger.info("LLM: полей %d, токенов %d/%d", len(fields), reply.prompt_tokens, reply.completion_tokens)
        answers = parse_reply(reply.content, [f.name for f in fields])
        matches = {}
        for field in fields:
            answer = answers.get(field.name)
            if answer is not None and (match := self._verify(field, answer)) is not None:
                matches[field.name] = match
        return LlmOutcome(matches, ("LLM_CONTEXT_TRUNCATED",) if truncated else ())

    def _verify(self, field: TemplateField, answer: LlmAnswer) -> RuleMatch | None:
        value = _normalize(field.data_type, normalize_text(answer.value))
        if value is None:
            return None
        strict = field.data_type in STRICT_TYPES
        if answer.quote and (hit := self._supported_hit(field, answer.quote, value)) is not None:
            confidence = (
                (CONFIDENCE_VERIFIED_NEAR_KEYWORD if hit.near else CONFIDENCE_VERIFIED)
                if strict
                else (CONFIDENCE_VERIFIED if hit.near else CONFIDENCE_LOOSE)
            )
            return self._match(field, value, hit, confidence)
        # цитаты нет или она не подтверждает значение: ищем само значение — это слабее
        if (hit := self._supported_hit(field, answer.value, value)) is not None:
            confidence = CONFIDENCE_VERIFIED if hit.near else CONFIDENCE_VALUE_ONLY
            return self._match(field, value, hit, confidence)
        return RuleMatch(value=value, raw=None, start=None, end=None, confidence=CONFIDENCE_UNVERIFIED)

    def _supported_hit(self, field: TemplateField, phrase: str, value: str) -> _Hit | None:
        hits = [h for h in self._locate(normalize_text(phrase), field) if _supports(field.data_type, self._raw(h), value)]
        return next((h for h in hits if h.near), hits[0] if hits else None)

    def _match(self, field: TemplateField, value: str, hit: _Hit, confidence: float) -> RuleMatch:
        start, end = hit.start, hit.end
        if field.data_type in STRICT_TYPES:  # подсветка — само значение, а не вся цитата
            start, end = _typed_span(field.data_type, self._index.text, start, end, value) or (start, end)
        return RuleMatch(value=value, raw=self._index.text[start:end], start=start, end=end, confidence=confidence)

    def _raw(self, hit: _Hit) -> str:
        return self._index.text[hit.start:hit.end]

    def _locate(self, phrase: str, field: TemplateField) -> list[_Hit]:
        """Вхождения фрагмента целыми словами, без учёта регистра и количества пробелов."""
        words = phrase.split()
        if not words:
            return []
        body = r"\s+".join(re.escape(w) for w in words)
        left = r"(?<!\w)" if _WORD.match(words[0][0]) else ""
        right = r"(?!\w)" if _WORD.match(words[-1][-1]) else ""
        pattern = re.compile(left + body + right, re.IGNORECASE)
        hits = []
        for hit_number, hit in enumerate(pattern.finditer(self._index.text)):
            if hit_number >= MAX_QUOTE_HITS:
                break
            hits.append(_Hit(hit.start(), hit.end(), self._near_keyword(field, hit.start(), hit.end())))
        return hits

    def _near_keyword(self, field: TemplateField, start: int, end: int) -> bool:
        """Якорь заканчивается внутри фрагмента («ИНН 7707…») или не дальше окна правил перед ним."""
        return any(
            start - WINDOW_CHARS <= keyword_end <= end
            for keyword in field.keywords
            for keyword_end in self._index.keyword_ends(keyword)
        )


def _normalize(data_type: DataType, value: str) -> str | None:
    if data_type in STRICT_TYPES:
        values = _typed_values(data_type, value)
        return values[0][0] if values else None
    if data_type is DataType.NUMBER:
        number = value.removeprefix("№").strip()
        return TYPE_NORMALIZERS[data_type](number) if len(number) <= MAX_NUMBER_CHARS else None
    if data_type is DataType.PERSON:
        # полное ФИО модель уже приводит к именительному падежу; «Иванов И.И.» — к единому виду
        return normalize_person(value) or normalize_text_value(value)
    if data_type is DataType.ORGANIZATION:
        return normalize_organization(value)
    return normalize_text_value(value)


def _typed_values(data_type: DataType, text: str) -> list[tuple[str, int, int]]:
    """Значения строгого типа в тексте — тем же шаблоном и нормализатором, что и у правил."""
    rule = TYPE_RULES[data_type]
    values = []
    for match_number, match in enumerate(rule.pattern.finditer(text)):
        if match_number >= MAX_PATTERN_MATCHES:
            break
        if (value := rule.normalize(match["v"])) is not None:
            values.append((value, match.start("v"), match.end("v")))
    return values


def _typed_span(data_type: DataType, text: str, start: int, end: int, value: str) -> tuple[int, int] | None:
    for found, value_start, value_end in _typed_values(data_type, text[start:end]):
        if found == value:
            return start + value_start, start + value_end
    return None


def _supports(data_type: DataType, fragment: str, value: str) -> bool:
    """Подтверждает ли фрагмент документа значение модели: для строгих типов — то же нормализованное
    значение; для текста — каждому слову значения соответствует своё слово фрагмента."""
    if data_type in STRICT_TYPES:
        return any(found == value for found, _, _ in _typed_values(data_type, fragment))
    value_words = [_fold(w) for w in _WORD.findall(value)]
    available = [_fold(w) for w in _WORD.findall(fragment)]
    if not value_words:
        return False
    for word in sorted(value_words, key=len, reverse=True):
        matched = next((w for w in available if _same_word(word, w)), None)
        if matched is None:
            return False
        available.remove(matched)
    return True


def _same_word(value_word: str, fragment_word: str) -> bool:
    if len(value_word) <= SHORT_WORD_CHARS or value_word.isdigit():
        return value_word == fragment_word
    stem_length = min(MAX_STEM_CHARS, max(MIN_STEM_CHARS, len(value_word) - STEM_SUFFIX_CHARS))
    return fragment_word.startswith(value_word[:stem_length])


def _fold(word: str) -> str:
    return word.lower().replace("ё", "е")
