"""Метрики качества извлечения на уровне полей.

Для каждого поля документа эталон сравнивается с результатом сервиса:
- tp — значение найдено и совпало с эталоном;
- fn — эталон есть, а значение не найдено;
- fp — эталона нет (поля нет в документе), а значение найдено;
- wrong — найдено другое значение: считается и ложным срабатыванием, и пропуском (как в задачах slot filling);
- tn — поля нет и ничего не найдено (в метриках не участвует).
Precision = TP / (TP + FP), Recall = TP / (TP + FN), F1 — их гармоническое среднее (микроусреднение)."""
import re
import statistics
from collections.abc import Callable, Iterable
from dataclasses import dataclass
from typing import Literal

OutcomeKind = Literal["tp", "fp", "fn", "wrong", "tn"]
_QUOTES = str.maketrans({"«": '"', "»": '"', "“": '"', "”": '"', "„": '"'})
# «Иванов И.И.» и «Иванов И. И.» — одно и то же
_INITIAL_SPACE = re.compile(r"(?<=\b\w\.)\s+(?=\w\.)")


def values_match(expected: str, predicted: str) -> bool:
    return _canonical(expected) == _canonical(predicted)


def _canonical(value: str) -> str:
    """Регистр, пробелы, кавычки, ё и точка в конце фразы на верность значения не влияют."""
    text = " ".join(value.translate(_QUOTES).split()).casefold().replace("ё", "е")
    return _INITIAL_SPACE.sub("", text).rstrip(" .;,")


@dataclass(frozen=True)
class FieldOutcome:
    doc_id: str
    doc_type: str
    kind: str  # формат документа: pdf, docx, scan
    field: str
    expected: str | None
    predicted: str | None
    needs_review: bool
    source: str | None = None  # этап, давший значение: rule, ner, llm

    @property
    def outcome(self) -> OutcomeKind:
        if self.expected is None:
            return "tn" if self.predicted is None else "fp"
        if self.predicted is None:
            return "fn"
        return "tp" if values_match(self.expected, self.predicted) else "wrong"


@dataclass(frozen=True)
class Scores:
    tp: int
    fp: int
    fn: int

    @property
    def precision(self) -> float:
        return self.tp / (self.tp + self.fp) if self.tp + self.fp else 0.0

    @property
    def recall(self) -> float:
        return self.tp / (self.tp + self.fn) if self.tp + self.fn else 0.0

    @property
    def f1(self) -> float:
        p, r = self.precision, self.recall
        return 2 * p * r / (p + r) if p + r else 0.0


def score(outcomes: Iterable[FieldOutcome]) -> Scores:
    tp = fp = fn = 0
    for item in outcomes:
        kind = item.outcome
        tp += kind == "tp"
        fp += kind in ("fp", "wrong")
        fn += kind in ("fn", "wrong")
    return Scores(tp=tp, fp=fp, fn=fn)


def score_by(outcomes: Iterable[FieldOutcome], key: Callable[[FieldOutcome], str]) -> dict[str, Scores]:
    groups: dict[str, list[FieldOutcome]] = {}
    for item in outcomes:
        groups.setdefault(key(item), []).append(item)
    return {name: score(items) for name, items in groups.items()}


@dataclass(frozen=True)
class ReviewStats:
    """Насколько пометка «требует проверки» помогает: ловит ли ошибки и не мешает ли верным значениям."""

    errors: int
    errors_flagged: int
    correct: int
    correct_flagged: int
    expected_fields: int

    @property
    def error_catch_rate(self) -> float:
        """Доля ошибок (неверных, пропущенных и лишних значений), помеченных для проверки."""
        return self.errors_flagged / self.errors if self.errors else 1.0

    @property
    def false_alarm_rate(self) -> float:
        """Доля верных значений, которые всё же отправлены на проверку."""
        return self.correct_flagged / self.correct if self.correct else 0.0

    @property
    def automation_rate(self) -> float:
        """Доля полей с эталоном, принятых без участия человека: значение верно и не помечено."""
        return (self.correct - self.correct_flagged) / self.expected_fields if self.expected_fields else 0.0


def review_stats(outcomes: Iterable[FieldOutcome]) -> ReviewStats:
    errors = errors_flagged = correct = correct_flagged = expected = 0
    for item in outcomes:
        kind = item.outcome
        if kind == "tn":
            continue
        expected += item.expected is not None
        if kind == "tp":
            correct += 1
            correct_flagged += item.needs_review
        else:
            errors += 1
            errors_flagged += item.needs_review
    return ReviewStats(errors, errors_flagged, correct, correct_flagged, expected)


@dataclass(frozen=True)
class TimeStats:
    count: int
    mean_ms: float
    median_ms: float
    p95_ms: float
    max_ms: float
    within_limit: float  # доля документов, обработанных не дольше limit_ms


def time_stats(durations_ms: list[float], limit_ms: float) -> TimeStats:
    if not durations_ms:
        return TimeStats(0, 0.0, 0.0, 0.0, 0.0, 0.0)
    ordered = sorted(durations_ms)
    p95_index = min(len(ordered) - 1, round(0.95 * (len(ordered) - 1)))
    return TimeStats(
        count=len(ordered),
        mean_ms=statistics.fmean(ordered),
        median_ms=statistics.median(ordered),
        p95_ms=ordered[p95_index],
        max_ms=ordered[-1],
        within_limit=sum(1 for d in ordered if d <= limit_ms) / len(ordered),
    )
