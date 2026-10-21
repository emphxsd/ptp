"""Генерация синтетической тестовой выборки.

    python -m evaluation.generate --out evaluation/data/synthetic --per-type 25 --seed 2026

Документы каждого типа по очереди сохраняются как PDF с текстом, DOCX и скан (PDF из картинок или JPEG):
так в выборке есть все поддерживаемые форматы и путь через OCR. Выборка детерминирована по seed."""
import argparse
import json
import random
from pathlib import Path

from evaluation.dataset import FORMAT_VERSION, MANIFEST, TEMPLATES_DIR
from evaluation.documents import BUILDERS, SyntheticDocument
from evaluation.render import to_docx, to_pdf, to_scan
from evaluation.templates import TEMPLATES

KINDS = ("pdf", "docx", "scan")


def generate(out: Path, per_type: int, seed: int) -> dict[str, object]:
    out.mkdir(parents=True, exist_ok=True)
    (out / TEMPLATES_DIR).mkdir(exist_ok=True)
    for doc_type, template in TEMPLATES.items():
        (out / TEMPLATES_DIR / f"{doc_type}.json").write_text(
            json.dumps(template, ensure_ascii=False, indent=2), encoding="utf-8"
        )
    rng = random.Random(seed)
    documents = []
    for doc_type, build in BUILDERS.items():
        for number in range(1, per_type + 1):
            document = build(rng)
            kind = KINDS[(number - 1) % len(KINDS)]
            data, extension = _render(document, kind, number, rng)
            name = f"{doc_type}-{number:03d}.{extension}"
            (out / name).write_bytes(data)
            documents.append(
                {"id": name.rsplit(".", 1)[0], "file": name, "doc_type": doc_type, "kind": kind,
                 "expected": document.expected}
            )
    manifest = {"version": FORMAT_VERSION, "seed": seed, "synthetic": True, "documents": documents}
    (out / MANIFEST).write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    return manifest


def _render(document: SyntheticDocument, kind: str, number: int, rng: random.Random) -> tuple[bytes, str]:
    if kind == "pdf":
        return to_pdf(document), "pdf"
    if kind == "docx":
        return to_docx(document), "docx"
    # каждый второй скан — фотография/картинка страницы (JPEG), остальные — PDF из картинок
    as_image = document.doc_type != "contract" and number % 2 == 0
    return to_scan(document, rng, as_image=as_image), "jpg" if as_image else "pdf"


def main() -> None:
    parser = argparse.ArgumentParser(description="Генерация синтетической выборки документов")
    parser.add_argument("--out", type=Path, default=Path("evaluation/data/synthetic"))
    parser.add_argument("--per-type", type=int, default=25)
    parser.add_argument("--seed", type=int, default=2026)
    args = parser.parse_args()
    manifest = generate(args.out, args.per_type, args.seed)
    print(f"Создано документов: {len(manifest['documents'])} в {args.out}")


if __name__ == "__main__":
    main()
