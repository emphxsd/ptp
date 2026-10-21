"""Отчёт об оценке в Markdown: таблицы для главы «Тестирование и оценка качества» ВКР."""
from collections import Counter
from collections.abc import Callable
from datetime import date
from itertools import chain, zip_longest

from app.config import Settings
from evaluation.dataset import Dataset
from evaluation.metrics import FieldOutcome, Scores, review_stats, score, score_by, time_stats
from evaluation.results import MODE_TITLES, Evaluation

DOC_TYPE_TITLES = {"invoice": "Счёт на оплату", "act": "Акт", "contract": "Договор", "resume": "Резюме"}
KIND_TITLES = {"pdf": "PDF (текст)", "docx": "DOCX", "scan": "Скан (OCR)"}
TARGET_F1 = 0.85
MAX_ERROR_EXAMPLES = 25
_MD_ESCAPES = str.maketrans({"|": "\\|", "`": "\\`", "<": "&lt;", ">": "&gt;", "\n": " ", "\r": " "})


def pct(value: float) -> str:
    return f"{value * 100:.1f}".replace(".", ",") + " %"


def seconds(ms: float) -> str:
    return f"{ms / 1000:.2f}".replace(".", ",")


def build_report(result: Evaluation, dataset: Dataset, settings: Settings, manual_s: float, review_s: float) -> str:
    modes = list(result.outcomes)
    lines = [
        "# Оценка качества извлечения",
        "",
        f"Дата: {date.today():%d.%m.%Y}. Выборка: `{_md(dataset.root.name)}`, документов: {len(dataset.cases)}"
        f"{f', seed {dataset.seed}' if dataset.seed is not None else ''}. "
        f"OCR: {result.environment.get('ocr')}; LLM: {result.environment.get('llm')}.",
        "",
        *_dataset_table(dataset),
        *_notes(result),
        *_limitations(dataset),
        "## Общие метрики",
        "",
        "Метрики на уровне полей (микроусреднение): неверное значение считается и ложным срабатыванием, и пропуском.",
        "",
        "| Режим | Precision | Recall | F1 | TP | FP | FN | Цель F1 ≥ 85 % |",
        "|---|---|---|---|---|---|---|---|",
    ]
    for mode in modes:
        s = score(result.outcomes[mode])
        lines.append(
            f"| {MODE_TITLES[mode]} | {pct(s.precision)} | {pct(s.recall)} | {_cell(s)} | {s.tp} | {s.fp} | {s.fn} | "
            f"{'да' if s.f1 >= TARGET_F1 else 'нет'} |"
        )
    lines += [""]
    lines += _grouped("F1 по типам документов", result, modes, lambda o: o.doc_type, DOC_TYPE_TITLES)
    lines += _grouped("F1 по форматам", result, modes, lambda o: o.kind, KIND_TITLES)
    lines += _grouped("F1 по полям", result, modes, lambda o: f"{o.doc_type}.{o.field}", {}, _field_title)
    lines += _review_table(result, modes)
    lines += _time_table(result, modes, settings, manual_s, review_s)
    lines += _errors(result, modes[-1]) if modes else []
    return "\n".join(lines) + "\n"


def _dataset_table(dataset: Dataset) -> list[str]:
    counts = Counter((case.doc_type, case.kind) for case in dataset.cases)
    kinds = sorted({case.kind for case in dataset.cases})
    lines = ["| Тип документа | " + " | ".join(KIND_TITLES.get(k, _md(k)) for k in kinds) + " | Всего |",
             "|---|" + "---|" * (len(kinds) + 1)]
    for doc_type in sorted({case.doc_type for case in dataset.cases}):
        row = [counts[(doc_type, k)] for k in kinds]
        lines.append(f"| {DOC_TYPE_TITLES.get(doc_type, _md(doc_type))} | " + " | ".join(map(str, row)) + f" | {sum(row)} |")
    return [*lines, ""]


def _notes(result: Evaluation) -> list[str]:
    notes = [f"> **Внимание:** {note}." for note in result.notes]
    notes += [f"> Режим «{MODE_TITLES[m]}» не выполнен: {reason}." for m, reason in result.skipped.items()]
    return [*notes, ""] if notes else []


def _limitations(dataset: Dataset) -> list[str]:
    if not dataset.synthetic:
        return []
    return [
        "> **Ограничения.** Выборка синтетическая: документы, шаблоны и правила извлечения написаны одним автором, "
        "поэтому метрики оптимистичны по сравнению с реальными документами. Часть правил исправлялась по ошибкам "
        "на выборке с seed 2026; контрольная выборка с другим seed прогоняется без изменения правил. "
        "Итоговые цифры для ВКР следует подтвердить на реальных обезличенных документах.",
        "",
    ]


def _grouped(
    title: str,
    result: Evaluation,
    modes: list[str],
    key: Callable[[FieldOutcome], str],
    titles: dict[str, str],
    describe: Callable[[str], str] | None = None,
) -> list[str]:
    per_mode = {mode: score_by(result.outcomes[mode], key) for mode in modes}
    groups = sorted({g for scores in per_mode.values() for g in scores})
    lines = [f"## {title}", "", "| | " + " | ".join(MODE_TITLES[m] for m in modes) + " |", "|---|" + "---|" * len(modes)]
    for group in groups:
        name = describe(group) if describe else titles.get(group, _md(group))
        lines.append(f"| {name} | " + " | ".join(_cell(per_mode[m].get(group)) for m in modes) + " |")
    return [*lines, ""]


def _field_title(group: str) -> str:
    doc_type, field_name = group.split(".", 1)
    return f"{DOC_TYPE_TITLES.get(doc_type, _md(doc_type))}: {_md(field_name)}"


def _cell(scores: Scores | None) -> str:
    """Группа, где нет ни эталонов, ни найденных значений, — «—», а не 0 %."""
    return pct(scores.f1) if scores is not None and scores.tp + scores.fp + scores.fn else "—"


def _review_table(result: Evaluation, modes: list[str]) -> list[str]:
    lines = [
        "## Пометка «требует проверки»",
        "",
        "Доля ошибок, которые сервис сам пометил для проверки, доля лишних пометок у верных значений и доля полей, "
        "принятых без участия человека (значение верно и не помечено). Поля отклонённых документов считаются "
        "помеченными: их обрабатывает человек.",
        "",
        "| Режим | Ошибок поймано | Лишние пометки | Автоматически без проверки |",
        "|---|---|---|---|",
    ]
    for mode in modes:
        stats = review_stats(result.outcomes[mode])
        lines.append(
            f"| {MODE_TITLES[mode]} | {pct(stats.error_catch_rate)} ({stats.errors_flagged} из {stats.errors}) | "
            f"{pct(stats.false_alarm_rate)} | {pct(stats.automation_rate)} |"
        )
    return [*lines, ""]


def _time_table(result: Evaluation, modes: list[str], settings: Settings, manual_s: float, review_s: float) -> list[str]:
    budget = settings.processing_budget_seconds
    lines = [
        "## Время обработки",
        "",
        "Время одного прогона; перед замером каждого режима обрабатывается по одному документу каждого формата "
        "(загрузка моделей и библиотек не попадает в замер).",
        "",
        f"| Режим | Среднее, с | Медиана, с | 95-й процентиль, с | Максимум, с | Не дольше {budget:g} с | "
        "Документов с ошибкой |",
        "|---|---|---|---|---|---|---|",
    ]
    for mode in modes:
        runs = result.runs[mode]
        stats = time_stats([r.ms for r in runs], budget * 1000)
        errors = sum(1 for r in runs if r.error)
        lines.append(
            f"| {MODE_TITLES[mode]} | {seconds(stats.mean_ms)} | {seconds(stats.median_ms)} | {seconds(stats.p95_ms)} | "
            f"{seconds(stats.max_ms)} | {pct(stats.within_limit)} | {errors} |"
        )
    lines += [
        "",
        f"Сравнение с ручным вводом: ручной ввод одного документа — {manual_s / 60:.0f} мин (оценка из модели AS-IS; "
        f"для точного значения заменить хронометражем, параметр `--manual-seconds`); проверка одного помеченного поля — "
        f"{review_s:.0f} с (`--review-seconds`).",
        "",
        "| Режим | Обработка + проверка, с/документ | Сокращение времени |",
        "|---|---|---|",
    ]
    for mode in modes:
        runs = result.runs[mode]
        per_doc = sum(r.ms / 1000 + r.flagged_fields * review_s for r in runs) / len(runs) if runs else 0.0
        lines.append(f"| {MODE_TITLES[mode]} | {per_doc:.1f}".replace(".", ",") + f" | {pct(1 - per_doc / manual_s)} |")
    return [*lines, ""]


def _errors(result: Evaluation, mode: str) -> list[str]:
    errors = [o for o in result.outcomes[mode] if o.outcome in ("fp", "fn", "wrong")]
    if not errors:
        return []
    by_field = Counter(f"{o.doc_type}.{o.field}" for o in errors)
    by_type: dict[str, list[FieldOutcome]] = {}
    for o in errors:
        by_type.setdefault(o.doc_type, []).append(o)
    # примеры по очереди из каждого типа документов, а не только первые документы выборки
    sample = [o for o in chain.from_iterable(zip_longest(*by_type.values())) if o is not None][:MAX_ERROR_EXAMPLES]
    lines = [
        f"## Ошибки режима «{MODE_TITLES[mode]}»",
        "",
        "Чаще всего: " + ", ".join(f"{_md(name)} — {count}" for name, count in by_field.most_common(8)) + ".",
        "",
        "| Документ | Поле | Эталон | Найдено | Тип ошибки | Помечено |",
        "|---|---|---|---|---|---|",
    ]
    for o in sample:
        lines.append(
            f"| {_md(o.doc_id)} | {_md(o.field)} | {_md(o.expected)} | {_md(o.predicted)} | {o.outcome} | "
            f"{'да' if o.needs_review else 'нет'} |"
        )
    return [*lines, ""]


def _md(value: str | None) -> str:
    """Значения из документов: без переводов строк, разметки таблицы, кода и HTML."""
    return "—" if value is None else value.translate(_MD_ESCAPES)
