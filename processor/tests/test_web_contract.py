"""Контракт с веб-приложением (папка web рядом с processor): шаблоны и регулярные выражения,
которые сохраняет конструктор шаблонов, сервис должен принимать той же схемой, что при обработке."""
import json
from pathlib import Path

import pytest

from app.fields.user_regex import UserRegexError, compile_user_regex
from app.schemas import Template
from evaluation.templates import TEMPLATES

WEB = Path(__file__).resolve().parents[2] / "web"
SYSTEM_TEMPLATES = WEB / "database" / "data" / "system_templates.json"
REGEX_CASES = WEB / "tests" / "Fixtures" / "regex_cases.json"
CONSTRUCTOR_TEMPLATE = WEB / "tests" / "Fixtures" / "constructor_template.json"

pytestmark = pytest.mark.skipif(not WEB.exists(), reason="нет папки web рядом с processor")


def _load(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def test_web_system_templates_match_evaluation_templates():
    """Системные шаблоны веб-приложения — те, на которых измерено качество (evaluation/templates.py)."""
    web = {item["key"]: item for item in _load(SYSTEM_TEMPLATES)}

    assert web.keys() == TEMPLATES.keys()
    for key, template in TEMPLATES.items():
        assert web[key]["name"] == template["name"]
        assert web[key]["fields"] == template["fields"]
        Template.model_validate({"name": web[key]["name"], "fields": web[key]["fields"]})


def test_template_built_in_constructor_passes_service_schema():
    """Фикстуру строит тест веб-приложения через форму конструктора (TemplateConstructorTest)."""
    Template.model_validate(_load(CONSTRUCTOR_TEMPLATE))


@pytest.mark.parametrize("pattern", _load(REGEX_CASES)["valid"] if REGEX_CASES.exists() else [])
def test_regex_accepted_by_web_form_compiles_in_re2(pattern):
    compile_user_regex(pattern)


@pytest.mark.parametrize(
    "pattern",
    (_load(REGEX_CASES)["rejected"] + _load(REGEX_CASES)["too_complex"]) if REGEX_CASES.exists() else [],
)
def test_regex_rejected_cases_are_rejected_by_re2(pattern):
    with pytest.raises(UserRegexError):
        compile_user_regex(pattern)
