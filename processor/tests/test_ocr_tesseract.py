"""Интеграционный тест с настоящим Tesseract: пропускается, если программа или русский язык не установлены."""
import os
from pathlib import Path

import pymupdf
import pytest
from dotenv import dotenv_values

from app.config import Settings
from app.extraction.ocr import TesseractEngine, check_tesseract
from app.pipeline import process_document
from app.schemas import Template
from tests.conftest import make_pdf

def _local_settings() -> Settings:
    """Путь к Tesseract — из окружения или из processor/.env (как при запуске сервиса с --env-file)."""
    env_file = Path(__file__).resolve().parents[1] / ".env"
    values = {k: v for k, v in dotenv_values(env_file).items() if v is not None} if env_file.exists() else {}
    return Settings.from_env({**values, **os.environ})


SETTINGS = _local_settings()
pytestmark = pytest.mark.skipif(
    not check_tesseract(SETTINGS.tesseract_cmd, SETTINGS.ocr_languages),
    reason="Tesseract с языками rus+eng не установлен",
)


def make_image_only_pdf(text: str) -> bytes:
    """Страница с текстом рендерится в картинку и вставляется в новый PDF — текстового слоя нет."""
    source = pymupdf.open(stream=make_pdf([text]), filetype="pdf")
    pixmap = source[0].get_pixmap(dpi=200)
    doc = pymupdf.open()
    page = doc.new_page(width=source[0].rect.width, height=source[0].rect.height)
    page.insert_image(page.rect, pixmap=pixmap)
    data = doc.tobytes()
    doc.close()
    source.close()
    return data


def test_real_scan_is_recognized_and_fields_found():
    scan = make_image_only_pdf("Счёт на оплату № 15 от 12.03.2026\nИНН 7707083893\nИтого: 15 000,00 руб.")
    template = Template.model_validate(
        {
            "fields": [
                {"name": "inn", "label": "ИНН", "data_type": "inn", "keywords": ["ИНН"]},
                {"name": "date", "label": "Дата", "data_type": "date", "keywords": ["от"]},
            ]
        }
    )
    engine = TesseractEngine(SETTINGS.tesseract_cmd, SETTINGS.ocr_languages)

    result = process_document(scan, template, Settings(ner_enabled=False, llm_enabled=False), ocr=engine)
    fields = {f.name: f.value for f in result.fields}

    assert result.document.ocr_pages == 1
    assert result.document.has_text_layer is False
    assert fields == {"inn": "7707083893", "date": "2026-03-12"}
