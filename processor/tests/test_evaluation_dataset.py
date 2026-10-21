"""Шаг 7: синтетическая выборка — детерминированность, корректность эталонов, формат manifest.json."""
import json
import random

import pymupdf
import pytest

from app.fields.checksums import is_valid_inn
from evaluation import fakes
from evaluation.dataset import DatasetError, load_dataset
from evaluation.documents import BUILDERS
from evaluation.generate import generate
from evaluation.templates import TEMPLATES, template_for


def test_generated_inns_are_valid():
    rng = random.Random(1)

    assert all(is_valid_inn(fakes.inn_legal(rng)) for _ in range(200))


def test_person_forms():
    who = fakes.Person("Петрова", "Анна", "Ивановна", "Петровой", "Анны", "Ивановны")

    assert who.full == "Петрова Анна Ивановна"
    assert who.full_genitive == "Петровой Анны Ивановны"
    assert who.initials == "Петрова А. И."


@pytest.mark.parametrize("doc_type", sorted(BUILDERS))
def test_expected_fields_match_template(doc_type):
    document = BUILDERS[doc_type](random.Random(7))
    template_fields = {f.name for f in template_for(doc_type).fields}

    assert set(document.expected) == template_fields


@pytest.fixture(scope="module")
def dataset_dir(tmp_path_factory):
    out = tmp_path_factory.mktemp("synthetic")
    generate(out, per_type=3, seed=11)
    return out


def test_generation_is_deterministic(dataset_dir, tmp_path):
    again = generate(tmp_path, per_type=3, seed=11)

    assert json.loads((dataset_dir / "manifest.json").read_text(encoding="utf-8")) == again


def test_dataset_contains_all_types_and_formats(dataset_dir):
    dataset = load_dataset(dataset_dir)

    assert len(dataset.cases) == 12
    assert {c.doc_type for c in dataset.cases} == set(TEMPLATES)
    assert {c.kind for c in dataset.cases} == {"pdf", "docx", "scan"}
    assert set(dataset.templates) == set(TEMPLATES)


def test_expected_values_are_present_in_text_documents(dataset_dir):
    dataset = load_dataset(dataset_dir)
    invoice = next(c for c in dataset.cases if c.doc_type == "invoice" and c.kind == "pdf")

    with pymupdf.open(invoice.path) as doc:
        text = " ".join(page.get_text() for page in doc)
    assert invoice.expected["supplier_inn"] in text
    assert invoice.expected["number"] in text


def test_scans_have_no_text_layer(dataset_dir):
    dataset = load_dataset(dataset_dir)
    scan = next(c for c in dataset.cases if c.kind == "scan" and c.path.suffix == ".pdf")

    with pymupdf.open(scan.path) as doc:
        assert all(not page.get_text().strip() for page in doc)


def _manifest(tmp_path, documents):
    (tmp_path / "templates").mkdir()
    (tmp_path / "templates" / "invoice.json").write_text(json.dumps(TEMPLATES["invoice"], ensure_ascii=False), encoding="utf-8")
    (tmp_path / "manifest.json").write_text(json.dumps({"version": 1, "documents": documents}), encoding="utf-8")


def test_manifest_cannot_point_outside_dataset(tmp_path):
    _manifest(tmp_path, [{"id": "x", "file": "../secret.pdf", "doc_type": "invoice", "expected": {}}])

    with pytest.raises(DatasetError):
        load_dataset(tmp_path)


def test_manifest_with_unknown_field_is_rejected(tmp_path):
    (tmp_path / "a.pdf").write_bytes(b"%PDF-1.4")
    _manifest(tmp_path, [{"id": "a", "file": "a.pdf", "doc_type": "invoice", "expected": {"nope": "1"}}])

    with pytest.raises(DatasetError):
        load_dataset(tmp_path)


def test_missing_expected_fields_mean_absent(tmp_path):
    (tmp_path / "a.pdf").write_bytes(b"%PDF-1.4")
    _manifest(tmp_path, [{"id": "a", "file": "a.pdf", "doc_type": "invoice", "expected": {"number": "15"}}])

    case = load_dataset(tmp_path).cases[0]

    assert case.expected["number"] == "15"
    assert case.expected["vat"] is None


def test_duplicate_ids_are_rejected(tmp_path):
    (tmp_path / "a.pdf").write_bytes(b"%PDF-1.4")
    item = {"id": "a", "file": "a.pdf", "doc_type": "invoice", "expected": {}}
    _manifest(tmp_path, [item, item])

    with pytest.raises(DatasetError):
        load_dataset(tmp_path)


@pytest.mark.parametrize(
    "item",
    [
        {"file": "a.pdf", "doc_type": "invoice"},
        {"id": "a", "file": "a.pdf", "doc_type": "invoice", "expected": {"number": 15}},
        "не объект",
    ],
    ids=["no-id", "number-not-string", "not-object"],
)
def test_malformed_items_are_dataset_errors(tmp_path, item):
    (tmp_path / "a.pdf").write_bytes(b"%PDF-1.4")
    _manifest(tmp_path, [item])

    with pytest.raises(DatasetError):
        load_dataset(tmp_path)


def test_missing_manifest_is_dataset_error(tmp_path):
    with pytest.raises(DatasetError):
        load_dataset(tmp_path)


def test_synthetic_flag_and_seed_are_read(dataset_dir):
    dataset = load_dataset(dataset_dir)

    assert dataset.synthetic is True
    assert dataset.seed == 11
