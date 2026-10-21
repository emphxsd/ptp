"""Шаг 7: прогон оценки и отчёт на маленькой синтетической выборке (только правила, без OCR — быстро)."""
import csv
import json

import pytest

from app.config import Settings
from app.llm.client import LlmReply
from evaluation.dataset import load_dataset
from evaluation.generate import generate
from evaluation.report import build_report
from evaluation.run import evaluate, main, write_outputs


@pytest.fixture(scope="module")
def dataset(tmp_path_factory):
    out = tmp_path_factory.mktemp("eval")
    generate(out, per_type=3, seed=5)
    return load_dataset(out)


SETTINGS = Settings(ocr_enabled=False)


def test_rules_mode_produces_outcomes_for_every_field(dataset):
    result = evaluate(dataset, ["rules"], SETTINGS, ocr=None, llm=None)

    expected_fields = sum(len(c.expected) for c in dataset.cases)
    assert len(result.outcomes["rules"]) == expected_fields
    assert len(result.runs["rules"]) == len(dataset.cases)
    # без OCR сканы отклоняются — фиксируется код ошибки, а поля считаются пропущенными
    scans = [r for r in result.runs["rules"] if r.kind == "scan"]
    assert scans and all(r.error in ("NO_TEXT_LAYER",) for r in scans)


def test_llm_mode_skipped_without_client(dataset):
    result = evaluate(dataset, ["rules", "llm"], SETTINGS, ocr=None, llm=None)

    assert "llm" in result.skipped
    assert "llm" not in result.outcomes


def test_llm_mode_runs_with_client(dataset):
    class EmptyLlm:
        def complete(self, messages, max_tokens, timeout=None):
            return LlmReply("{}")

    result = evaluate(dataset, ["llm"], SETTINGS.model_copy(update={"ner_enabled": False}), ocr=None, llm=EmptyLlm())

    assert len(result.runs["llm"]) == len(dataset.cases)


def test_report_and_files(dataset, tmp_path):
    result = evaluate(dataset, ["rules"], SETTINGS, ocr=None, llm=None)
    report = build_report(result, dataset, SETTINGS, manual_s=960, review_s=15)

    write_outputs(result, dataset, tmp_path, report)

    assert "## Общие метрики" in report and "| Правила |" in report
    assert "Счёт на оплату" in report and "Скан (OCR)" in report
    with (tmp_path / "outcomes.csv").open(encoding="utf-8-sig") as f:
        rows = list(csv.DictReader(f))
    assert {row["outcome"] for row in rows} <= {"tp", "fp", "fn", "wrong", "tn"}
    assert json.loads((tmp_path / "summary.json").read_text(encoding="utf-8"))["documents"] == len(dataset.cases)


def test_cli_rejects_unknown_mode(dataset, tmp_path):
    with pytest.raises(SystemExit):
        main(["--dataset", str(dataset.root), "--modes", "magic", "--out", str(tmp_path)])


# ---------------------------------------------------------------- исправления по итогам ревью
@pytest.mark.parametrize("value", ["=HYPERLINK(\"x\")", "+7 999", "-cmd|x", "@SUM(A1)", "\tтаб"])
def test_csv_cells_cannot_be_formulas(value):
    from evaluation.run import csv_cell

    assert csv_cell(value).startswith("'")


def test_csv_cells_keep_ordinary_values():
    from evaluation.run import csv_cell

    assert csv_cell("15000.00") == "15000.00"
    assert csv_cell(None) is None
    assert csv_cell(3) == 3


def test_markdown_cells_are_escaped():
    from evaluation.report import _md

    assert _md("a|b\nc `x` <script>") == r"a\|b c \`x\` &lt;script&gt;"
    assert _md(None) == "—"


def test_missing_ocr_for_scans_is_reported(dataset):
    result = evaluate(dataset, ["rules"], SETTINGS, ocr=None, llm=None)
    report = build_report(result, dataset, SETTINGS, manual_s=960, review_s=15)

    assert result.notes and "OCR недоступен" in result.notes[0]
    assert "Внимание" in report
    assert "Ограничения" in report  # выборка синтетическая


def test_unexpected_error_in_one_document_does_not_stop_run(dataset, monkeypatch):
    import evaluation.run as run_module

    original = run_module.process_document
    first = dataset.cases[0]
    broken = first.path.read_bytes()

    def flaky(data, template, settings, **kwargs):
        if data == broken:
            raise RuntimeError("сбой")
        return original(data, template, settings, **kwargs)

    monkeypatch.setattr(run_module, "process_document", flaky)
    result = evaluate(dataset, ["rules"], SETTINGS, ocr=None, llm=None)

    failed = [r for r in result.runs["rules"] if r.doc_id == first.id]
    assert failed[0].error == "UNEXPECTED:RuntimeError"
    assert len(result.runs["rules"]) == len(dataset.cases)
