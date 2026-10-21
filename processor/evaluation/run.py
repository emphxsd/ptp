"""Оценка качества извлечения на размеченной выборке в нескольких режимах каскада.

    python -m evaluation.run --dataset evaluation/data/synthetic --modes rules,ner,llm --out evaluation/reports/synthetic

Режимы: rules — только правила; ner — правила + NER; llm — правила + NER + LLM (нужен ключ GigaChat).
Настройки сервиса (путь к Tesseract, ключ) берутся из окружения и processor/.env, как при запуске сервиса.
Результат: report.md (таблицы для текста ВКР), outcomes.csv (каждое поле), documents.csv (время, ошибки), summary.json."""
import argparse
import csv
import json
import os
import sys
import time
from dataclasses import asdict
from pathlib import Path

from dotenv import dotenv_values

from app.config import Settings
from app.errors import ProcessingError
from app.extraction.ocr import OcrEngine, TesseractEngine, check_tesseract, resolve_command
from app.fields.ner import get_recognizer
from app.llm.client import LlmClient
from app.llm.factory import build_llm_client
from app.pipeline import process_document
from app.schemas import ExtractionResult
from evaluation.dataset import Case, Dataset, load_dataset
from evaluation.metrics import FieldOutcome
from evaluation.results import MODES, DocumentRun, Evaluation

ENV_FILE = Path(__file__).resolve().parents[1] / ".env"
# ячейка CSV, начинающаяся с этих символов, в Excel становится формулой — значения берутся из документов
_FORMULA_PREFIXES = ("=", "+", "-", "@", "\t", "\r")


def service_settings(env_file: Path = ENV_FILE) -> Settings:
    values = {k: v for k, v in dotenv_values(env_file).items() if v is not None} if env_file.exists() else {}
    return Settings.from_env({**values, **os.environ})


def build_ocr(settings: Settings) -> OcrEngine | None:
    if not settings.ocr_enabled:
        return None
    cmd = resolve_command(settings.tesseract_cmd) or settings.tesseract_cmd
    return TesseractEngine(cmd, settings.ocr_languages) if check_tesseract(cmd, settings.ocr_languages) else None


def evaluate(
    dataset: Dataset, modes: list[str], settings: Settings, ocr: OcrEngine | None, llm: LlmClient | None
) -> Evaluation:
    result = Evaluation(environment={"ocr": "tesseract" if ocr else "нет", "llm": settings.gigachat_model if llm else "нет"})
    scans = sum(1 for case in dataset.cases if case.kind == "scan")
    if scans and ocr is None:
        result.notes.append(
            f"OCR недоступен: {scans} сканов отклонены сервисом (NO_TEXT_LAYER) и посчитаны как пропуски всех полей"
        )
    for mode in modes:
        if mode == "llm" and llm is None:
            result.skipped[mode] = "LLM не настроена (не задан ключ GigaChat)"
            continue
        mode_settings = settings.model_copy(update=MODES[mode])
        _warm_up(dataset, mode_settings, ocr, llm)
        outcomes, runs = [], []
        for case in dataset.cases:
            case_outcomes, run = _run_case(case, dataset, mode, mode_settings, ocr, llm)
            outcomes += case_outcomes
            runs.append(run)
        result.outcomes[mode], result.runs[mode] = outcomes, runs
    return result


def _warm_up(dataset: Dataset, settings: Settings, ocr: OcrEngine | None, llm: LlmClient | None) -> None:
    """Первый документ каждого формата обрабатывается заранее, без замера: загрузка моделей, библиотек
    и первый запуск Tesseract не должны попасть во время первого документа режима."""
    if settings.ner_enabled:
        get_recognizer()
    first_of_kind = {}
    for case in dataset.cases:
        first_of_kind.setdefault(case.kind, case)
    for case in first_of_kind.values():
        try:
            process_document(case.path.read_bytes(), dataset.templates[case.doc_type], settings, ocr=ocr)
        except Exception:  # ошибки разогрева не важны: документ будет обработан и учтён в основном прогоне
            pass


def _run_case(
    case: Case, dataset: Dataset, mode: str, settings: Settings, ocr: OcrEngine | None, llm: LlmClient | None
) -> tuple[list[FieldOutcome], DocumentRun]:
    data = case.path.read_bytes()
    started = time.perf_counter()
    result: ExtractionResult | None = None
    error = None
    try:
        result = process_document(data, dataset.templates[case.doc_type], settings, llm=llm, ocr=ocr)
    except ProcessingError as exc:  # документ отклонён — все поля остаются человеку
        error = exc.code
    except Exception as exc:  # непредвиденный сбой одного документа не обрывает весь прогон
        error = f"UNEXPECTED:{type(exc).__name__}"
        print(f"{case.id}: {error}", file=sys.stderr)
    ms = (time.perf_counter() - started) * 1000
    found = {f.name: f for f in result.fields} if result is not None else {}
    outcomes = []
    for name, expected in case.expected.items():
        field_result = found.get(name)
        outcomes.append(
            FieldOutcome(
                doc_id=case.id, doc_type=case.doc_type, kind=case.kind, field=name, expected=expected,
                predicted=field_result.value if field_result else None,
                needs_review=field_result.needs_review if field_result else True,
                source=field_result.source if field_result else None,
            )
        )
    flagged = sum(1 for o in outcomes if o.needs_review and o.outcome != "tn")
    warnings = result.warnings if result is not None else ()
    return outcomes, DocumentRun(case.id, case.doc_type, case.kind, mode, ms, error, tuple(warnings), flagged)


def csv_cell(value: object) -> object:
    """Значение из документа не должно стать формулой при открытии CSV в Excel."""
    if isinstance(value, str) and value.startswith(_FORMULA_PREFIXES):
        return "'" + value
    return value


def write_outputs(result: Evaluation, dataset: Dataset, out: Path, report: str) -> None:
    out.mkdir(parents=True, exist_ok=True)
    (out / "report.md").write_text(report, encoding="utf-8")
    with (out / "outcomes.csv").open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.writer(f)
        writer.writerow(["mode", "doc_id", "doc_type", "kind", "field", "expected", "predicted", "outcome",
                         "needs_review", "source"])
        for mode, outcomes in result.outcomes.items():
            for o in outcomes:
                row = [mode, o.doc_id, o.doc_type, o.kind, o.field, o.expected, o.predicted, o.outcome,
                       o.needs_review, o.source]
                writer.writerow([csv_cell(cell) for cell in row])
    with (out / "documents.csv").open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.writer(f)
        writer.writerow(["mode", "doc_id", "doc_type", "kind", "ms", "error", "warnings", "flagged_fields"])
        for mode, runs in result.runs.items():
            for r in runs:
                row = [mode, r.doc_id, r.doc_type, r.kind, round(r.ms), r.error, " ".join(r.warnings), r.flagged_fields]
                writer.writerow([csv_cell(cell) for cell in row])
    summary = {
        "dataset": dataset.root.name,
        "documents": len(dataset.cases),
        "skipped_modes": result.skipped,
        "notes": result.notes,
        "environment": result.environment,
        "runs": {mode: [asdict(r) for r in runs] for mode, runs in result.runs.items()},
    }
    (out / "summary.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")


def main(argv: list[str] | None = None) -> None:
    from evaluation.report import build_report

    parser = argparse.ArgumentParser(description="Оценка качества извлечения: precision, recall, F1, время")
    parser.add_argument("--dataset", type=Path, default=Path("evaluation/data/synthetic"))
    parser.add_argument("--modes", default="rules,ner,llm")
    parser.add_argument("--out", type=Path, default=Path("evaluation/reports/synthetic"))
    parser.add_argument("--manual-seconds", type=float, default=960.0,
                        help="время ручного ввода одного документа (по умолчанию — оценка из модели AS-IS)")
    parser.add_argument("--review-seconds", type=float, default=15.0, help="время проверки одного помеченного поля")
    args = parser.parse_args(argv)
    modes = [m.strip() for m in args.modes.split(",") if m.strip()]
    unknown = set(modes) - set(MODES)
    if unknown:
        parser.error(f"неизвестные режимы: {', '.join(sorted(unknown))}")
    settings = service_settings()
    dataset = load_dataset(args.dataset)
    llm = build_llm_client(settings) if "llm" in modes else None
    result = evaluate(dataset, modes, settings, build_ocr(settings), llm)
    report = build_report(result, dataset, settings, args.manual_seconds, args.review_seconds)
    write_outputs(result, dataset, args.out, report)
    for note in result.notes:
        print(f"ВНИМАНИЕ: {note}", file=sys.stderr)
    for mode, reason in result.skipped.items():
        print(f"Режим {mode} пропущен: {reason}", file=sys.stderr)
    print(f"Отчёт: {args.out / 'report.md'}")


if __name__ == "__main__":
    main()
