"""Режимы каскада и результаты прогона оценки — общие для run.py (прогон) и report.py (отчёт)."""
from dataclasses import dataclass, field

from evaluation.metrics import FieldOutcome

MODES = {
    "rules": {"ner_enabled": False, "llm_enabled": False},
    "ner": {"ner_enabled": True, "llm_enabled": False},
    "llm": {"ner_enabled": True, "llm_enabled": True},
}
MODE_TITLES = {"rules": "Правила", "ner": "Правила + NER", "llm": "Правила + NER + LLM"}


@dataclass(frozen=True)
class DocumentRun:
    doc_id: str
    doc_type: str
    kind: str
    mode: str
    ms: float
    error: str | None  # код отказа сервиса (NO_TEXT_LAYER…) или UNEXPECTED:<тип исключения>
    warnings: tuple[str, ...]
    flagged_fields: int  # помеченные поля, у которых есть эталон или найденное значение (без tn)


@dataclass
class Evaluation:
    outcomes: dict[str, list[FieldOutcome]] = field(default_factory=dict)
    runs: dict[str, list[DocumentRun]] = field(default_factory=dict)
    skipped: dict[str, str] = field(default_factory=dict)  # режим → причина
    environment: dict[str, str] = field(default_factory=dict)
    notes: list[str] = field(default_factory=list)  # предупреждения о прогоне (например, нет OCR для сканов)
