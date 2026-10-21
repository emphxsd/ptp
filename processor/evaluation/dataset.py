"""Формат тестовой выборки: папка с документами, manifest.json с эталонами и templates/<тип>.json.
Тот же формат подходит для реальных обезличенных документов, размеченных вручную.

manifest.json:
{"version": 1, "documents": [{"id": "invoice-001", "file": "invoice-001.pdf", "doc_type": "invoice",
  "kind": "pdf", "expected": {"number": "15", "vat": null}}]}"""
import json
from dataclasses import dataclass
from pathlib import Path

from pydantic import ValidationError

from app.schemas import Template

MANIFEST = "manifest.json"
TEMPLATES_DIR = "templates"
FORMAT_VERSION = 1


@dataclass(frozen=True)
class Case:
    id: str
    path: Path
    doc_type: str
    kind: str
    expected: dict[str, str | None]


@dataclass(frozen=True)
class Dataset:
    root: Path
    templates: dict[str, Template]
    cases: tuple[Case, ...]
    synthetic: bool = False
    seed: int | None = None


class DatasetError(ValueError):
    pass


def load_dataset(root: Path) -> Dataset:
    root = root.resolve()
    try:
        manifest = json.loads((root / MANIFEST).read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise DatasetError(f"не удалось прочитать {MANIFEST}: {type(exc).__name__}") from None
    if not isinstance(manifest, dict) or manifest.get("version") != FORMAT_VERSION:
        raise DatasetError(f"неподдерживаемый формат {MANIFEST} (нужна версия {FORMAT_VERSION})")
    documents = manifest.get("documents")
    if not isinstance(documents, list):
        raise DatasetError(f"в {MANIFEST} нет списка documents")
    templates = _templates(root)
    cases = tuple(_case(root, item, templates) for item in documents)
    ids = [case.id for case in cases]
    duplicates = sorted({i for i in ids if ids.count(i) > 1})
    if duplicates:
        raise DatasetError(f"повторяются id документов: {', '.join(duplicates)}")
    seed = manifest.get("seed")
    return Dataset(
        root=root,
        templates=templates,
        cases=cases,
        synthetic=manifest.get("synthetic") is True,
        seed=seed if isinstance(seed, int) else None,
    )


def _templates(root: Path) -> dict[str, Template]:
    templates = {}
    for path in sorted((root / TEMPLATES_DIR).glob("*.json")):
        resolved = _inside(root, path, path.name)
        try:
            templates[path.stem] = Template.model_validate_json(resolved.read_text(encoding="utf-8"))
        except (OSError, ValidationError) as exc:
            raise DatasetError(f"некорректный шаблон {path.name}: {type(exc).__name__}") from None
    return templates


def _inside(root: Path, path: Path, name: str) -> Path:
    """Файл (в том числе по символической ссылке) не может указывать за пределы выборки."""
    resolved = path.resolve()
    if not resolved.is_relative_to(root):
        raise DatasetError(f"файл вне каталога выборки: {name}")
    return resolved


def _case(root: Path, item: object, templates: dict[str, Template]) -> Case:
    if not isinstance(item, dict) or not all(isinstance(item.get(k), str) for k in ("id", "file", "doc_type")):
        raise DatasetError("у документа в manifest.json должны быть строковые id, file и doc_type")
    path = _inside(root, root / item["file"], item["file"])
    if not path.is_file():
        raise DatasetError(f"нет файла: {item['file']}")
    doc_type = item["doc_type"]
    if doc_type not in templates:
        raise DatasetError(f"нет шаблона для типа {doc_type}")
    expected = item.get("expected", {})
    if not isinstance(expected, dict) or not all(v is None or isinstance(v, str) for v in expected.values()):
        raise DatasetError(f"{item['id']}: эталонные значения — строки или null")
    fields = {f.name for f in templates[doc_type].fields}
    unknown = set(expected) - fields
    if unknown:
        raise DatasetError(f"{item['id']}: поля нет в шаблоне: {', '.join(sorted(unknown))}")
    # поля шаблона без эталона считаются отсутствующими в документе
    return Case(
        id=item["id"],
        path=path,
        doc_type=doc_type,
        kind=str(item.get("kind", path.suffix.lstrip("."))),
        expected={name: expected.get(name) for name in sorted(fields)},
    )
