"""Этап «правила»: поиск значения поля по шаблону (встроенному по типу или пользовательскому)
сначала рядом со словами-якорями, затем по всему документу.

Главный принцип: неверное значение не должно получать уверенность выше порога проверки.
Поэтому неоднозначность (несколько разных значений рядом с якорем, якорь задан, но не найден,
слабый формат без якоря) снижает уверенность, а варианты сохраняются для этапа LLM."""
import re
from collections.abc import Iterator
from dataclasses import dataclass
from decimal import Decimal

from app.fields.patterns import TYPE_NORMALIZERS, TYPE_RULES
from app.fields.user_regex import compile_user_regex
from app.schemas import DataType, TemplateField

# уверенность (порог проверки по умолчанию — 0.6)
CONFIDENCE_NEAR_KEYWORD = 0.9
CONFIDENCE_NEAR_KEYWORD_STRONG = 0.95
CONFIDENCE_NEXT_LINE = 0.8
CONFIDENCE_NEAR_KEYWORD_AMBIGUOUS = 0.5
CONFIDENCE_UNIQUE = 0.7
CONFIDENCE_UNIQUE_STRONG = 0.8
CONFIDENCE_KEYWORD_MISSED = 0.5
CONFIDENCE_AMBIGUOUS = 0.4
CONFIDENCE_TAIL = 0.65
CONFIDENCE_TAIL_NEXT_LINE = 0.6

# лимиты: защищают от сверхлинейного времени на больших и враждебных текстах
WINDOW_CHARS = 200
MAX_TAIL_CHARS = 200
MAX_KEYWORD_HITS = 20
MAX_KEYWORD_SCANS = 2000
MAX_PREFIX_EXTENSION = 40
MAX_NEAR_CANDIDATES = 50
MAX_GLOBAL_CANDIDATES = 100
MAX_SCANNED_MATCHES = 10_000
MAX_ALTERNATIVES = 10
# значение длиннее — не реквизит (например, (?s).+ вернул бы весь документ мимо include_text)
MAX_RAW_CHARS = 500
# пользовательский regex без якоря ищется только в начале документа: ограничение времени RE2
USER_REGEX_SCAN_CHARS = 300_000

_SEPARATORS = re.compile(r"\s*(?:[:=]|-(?=\s))?\s*")
# строка вида «Адрес: …» — это метка другого поля, а не значение
_LABEL_LINE = re.compile(r"[А-Яа-яЁёA-Za-z][А-Яа-яЁёA-Za-z .\-/]{0,40}:")
_CURRENCY_AFTER = re.compile(r"\s?(?:руб|р\.|₽|RUB)", re.IGNORECASE)


@dataclass(frozen=True)
class RuleMatch:
    value: str
    raw: str
    start: int
    end: int
    confidence: float
    alternatives: tuple[str, ...] = ()


@dataclass(frozen=True)
class _Candidate:
    value: str
    raw: str
    start: int
    end: int


@dataclass(frozen=True)
class _Window:
    start: int
    end: int
    next_line: bool


def _is_word_char(ch: str) -> bool:
    return ch.isalnum() or ch == "_"


def _fold_case(text: str) -> str:
    """Нижний регистр без изменения длины строки (позиции совпадают с исходным текстом)."""
    lowered = text.lower()
    if len(lowered) == len(text):
        return lowered
    return "".join(ch.lower() if len(ch.lower()) == 1 else ch for ch in text)


class _Matcher:
    """Единый интерфейс для встроенного шаблона (re) и пользовательского (RE2)."""

    def __init__(self, field: TemplateField):
        self.data_type = field.data_type
        self.is_user_regex = bool(field.regex_pattern)
        if field.regex_pattern:
            self._regex = compile_user_regex(field.regex_pattern)
            self._group: int | str = 1 if self._regex.groups > 0 else 0
            self._normalize = TYPE_NORMALIZERS[field.data_type]
            self.strong = False
            self.global_cap = 1.0
            self.key: tuple = ("user", field.regex_pattern, field.data_type)
        else:
            rule = TYPE_RULES[field.data_type]
            self._regex = rule.pattern
            self._group = "v"
            self._normalize = rule.normalize
            self.strong = rule.strong
            self.global_cap = rule.global_cap
            self.key = ("type", field.data_type)

    def candidates(self, text: str, start: int, end: int, limit: int) -> Iterator[_Candidate]:
        if self.is_user_regex:
            # обёртка RE2 перекодирует в UTF-8 всю переданную строку — передаём только фрагмент
            offset, matches = start, self._regex.finditer(text[start:end])
        else:
            # встроенный re: позиции pos/endpos, чтобы проверки (?<!…) видели символы перед окном
            offset, matches = 0, self._regex.finditer(text, start, end)
        found = 0
        for scanned, match in enumerate(matches, start=1):
            if scanned > MAX_SCANNED_MATCHES:
                return
            raw = match.group(self._group)
            if not raw or len(raw) > MAX_RAW_CHARS:
                continue
            value = self._normalize(raw)
            if value is None:
                continue
            yield _Candidate(
                value=value, raw=raw, start=offset + match.start(self._group), end=offset + match.end(self._group)
            )
            found += 1
            if found >= limit:
                return


class DocumentIndex:
    """Текст документа и кэши, общие для всех полей шаблона: позиции якорей и кандидаты по шаблонам."""

    def __init__(self, text: str):
        self.text = text
        self._folded = _fold_case(text)
        self._keyword_ends: dict[str, tuple[int, ...]] = {}
        self._global: dict[tuple, tuple[_Candidate, ...]] = {}

    def keyword_ends(self, keyword: str) -> tuple[int, ...]:
        if keyword not in self._keyword_ends:
            self._keyword_ends[keyword] = self._find_keyword(keyword)
        return self._keyword_ends[keyword]

    def global_candidates(self, matcher: _Matcher) -> tuple[_Candidate, ...]:
        if matcher.key not in self._global:
            end = min(len(self.text), USER_REGEX_SCAN_CHARS) if matcher.is_user_regex else len(self.text)
            found = matcher.candidates(self.text, 0, end, MAX_GLOBAL_CANDIDATES)
            self._global[matcher.key] = tuple(found)
        return self._global[matcher.key]

    def _find_keyword(self, keyword: str) -> tuple[int, ...]:
        """Концы вхождений якоря. Якорь — целое слово/фраза; «слово*» — по началу слова.
        Граница слова проверяется только со стороны буквы/цифры («Итого:» найдётся в «Итого:15»)."""
        prefix = keyword.endswith("*")
        core = keyword[:-1] if prefix else keyword
        needle = _fold_case(core)
        text = self._folded
        check_left, check_right = _is_word_char(core[0]), _is_word_char(core[-1])
        ends: list[int] = []
        pos = text.find(needle)
        scans = 0
        while pos >= 0 and len(ends) < MAX_KEYWORD_HITS and scans < MAX_KEYWORD_SCANS:
            scans += 1
            end = pos + len(needle)
            left_ok = not check_left or pos == 0 or not _is_word_char(text[pos - 1])
            if prefix:
                limit = min(len(text), end + MAX_PREFIX_EXTENSION)
                while end < limit and _is_word_char(text[end]):
                    end += 1
                right_ok = True
            else:
                right_ok = not check_right or end == len(text) or not _is_word_char(text[end])
            if left_ok and right_ok:
                ends.append(end)
            pos = text.find(needle, pos + 1)
        return tuple(ends)


def find_by_rules(field: TemplateField, text: str, index: DocumentIndex | None = None) -> RuleMatch | None:
    index = index or DocumentIndex(text)
    if field.data_type is DataType.STRING and not field.regex_pattern:
        return _keyword_tail(field, index)
    matcher = _Matcher(field)
    return _near_keywords(field, index, matcher) or _in_document(field, index, matcher)


def _near_keywords(field: TemplateField, index: DocumentIndex, matcher: _Matcher) -> RuleMatch | None:
    """Первый якорь (в порядке шаблона), рядом с которым что-то нашлось; учитываются все его вхождения."""
    for keyword in field.keywords:
        found: list[tuple[_Candidate, bool]] = []
        for keyword_end in index.keyword_ends(keyword):
            window = _window(index.text, keyword_end)
            if window is None:
                continue
            for candidate in matcher.candidates(index.text, window.start, window.end, MAX_NEAR_CANDIDATES):
                found.append((candidate, window.next_line))
            if len(found) >= MAX_NEAR_CANDIDATES:
                break
        if found:
            return _decide_near(found, matcher, index.text)
    return None


def _decide_near(found: list[tuple[_Candidate, bool]], matcher: _Matcher, text: str) -> RuleMatch:
    candidates = [candidate for candidate, _ in found]
    values = _distinct_values(candidates)
    best = _preferred(candidates, matcher.data_type, text)
    if len(values) > 1:
        return _to_match(best, CONFIDENCE_NEAR_KEYWORD_AMBIGUOUS, values)
    if all(next_line for _, next_line in found):
        return _to_match(best, CONFIDENCE_NEXT_LINE)
    return _to_match(best, CONFIDENCE_NEAR_KEYWORD_STRONG if matcher.strong else CONFIDENCE_NEAR_KEYWORD)


def _in_document(field: TemplateField, index: DocumentIndex, matcher: _Matcher) -> RuleMatch | None:
    candidates = index.global_candidates(matcher)
    if not candidates:
        return None
    values = _distinct_values(candidates)
    if len(values) == 1:
        confidence = CONFIDENCE_UNIQUE_STRONG if matcher.strong else CONFIDENCE_UNIQUE
        alternatives: tuple[str, ...] = ()
    else:
        confidence = CONFIDENCE_AMBIGUOUS
        alternatives = values
    confidence = min(confidence, matcher.global_cap)
    if field.keywords:
        # якоря заданы, но рядом с ними значения нет: найденное может относиться к другому полю
        confidence = min(confidence, CONFIDENCE_KEYWORD_MISSED)
    return _to_match(_preferred(list(candidates), matcher.data_type, index.text), confidence, alternatives)


def _keyword_tail(field: TemplateField, index: DocumentIndex) -> RuleMatch | None:
    """Для произвольного текста: всё, что стоит после якоря до конца строки (или следующая строка)."""
    for keyword in field.keywords:
        for keyword_end in index.keyword_ends(keyword):
            window = _window(index.text, keyword_end)
            if window is None:
                continue
            start = _SEPARATORS.match(index.text, window.start, window.end).end() if not window.next_line else window.start
            end = min(window.end, start + MAX_TAIL_CHARS)
            raw = index.text[start:end].strip()
            if raw:
                start = index.text.index(raw, start)
                confidence = CONFIDENCE_TAIL_NEXT_LINE if window.next_line else CONFIDENCE_TAIL
                return RuleMatch(value=raw, raw=raw, start=start, end=start + len(raw), confidence=confidence)
    return None


def _window(text: str, keyword_end: int) -> _Window | None:
    """Остаток строки после якоря; если он пуст — следующая строка (если это не «метка: …» другого поля)."""
    limit = min(len(text), keyword_end + WINDOW_CHARS)
    newline = text.find("\n", keyword_end, limit)
    line_end = newline if newline >= 0 else limit
    if _SEPARATORS.match(text, keyword_end, line_end).end() < line_end:
        return _Window(keyword_end, line_end, next_line=False)
    if newline < 0:
        return None
    next_start = newline + 1
    next_limit = min(len(text), next_start + WINDOW_CHARS)
    next_newline = text.find("\n", next_start, next_limit)
    next_end = next_newline if next_newline >= 0 else next_limit
    line = text[next_start:next_end].lstrip()
    if not line or _LABEL_LINE.match(line):
        return None
    return _Window(next_start, next_end, next_line=True)


def _distinct_values(candidates: list[_Candidate] | tuple[_Candidate, ...]) -> tuple[str, ...]:
    return tuple(dict.fromkeys(c.value for c in candidates))[:MAX_ALTERNATIVES]


def _preferred(candidates: list[_Candidate], data_type: DataType, text: str) -> _Candidate:
    """Для суммы предпочитаем значение с копейками или валютой, затем наибольшее; иначе — первое."""
    if data_type is not DataType.AMOUNT:
        return candidates[0]

    def score(candidate: _Candidate) -> tuple[bool, Decimal]:
        has_kopecks = any(sep in candidate.raw for sep in ",.-")
        has_currency = _CURRENCY_AFTER.match(text, candidate.end) is not None
        return has_kopecks or has_currency, Decimal(candidate.value)

    return max(candidates, key=score)


def _to_match(candidate: _Candidate, confidence: float, alternatives: tuple[str, ...] = ()) -> RuleMatch:
    return RuleMatch(
        value=candidate.value,
        raw=candidate.raw,
        start=candidate.start,
        end=candidate.end,
        confidence=confidence,
        alternatives=alternatives,
    )
