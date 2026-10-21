import pytest

from app.extraction.normalize import normalize_text


def test_replaces_non_breaking_and_thin_spaces():
    assert normalize_text("15 000,00 руб. итого") == "15 000,00 руб. итого"


def test_removes_zero_width_characters_and_bom():
    assert normalize_text("﻿ИНН​ 77‌01‍23⁠") == "ИНН 770123"


def test_form_feed_and_vertical_tab_become_line_breaks():
    assert normalize_text("стр. 1\x0cстр. 2\x0bконец") == "стр. 1\nстр. 2\nконец"


def test_unifies_quotes():
    assert normalize_text("ООО «Ромашка» и “Лютик” „Тест“") == 'ООО "Ромашка" и "Лютик" "Тест"'


def test_unifies_dashes():
    assert normalize_text("2025–2026 — период ‑ дефис") == "2025-2026 - период - дефис"


def test_replaces_pdf_ligatures_and_fullwidth_digits():
    assert normalize_text("ﬁle ﬂow １２３") == "file flow 123"


def test_removes_soft_hyphens():
    assert normalize_text("доку­мент") == "документ"


def test_joins_words_broken_by_hyphenation():
    assert normalize_text("настоящего догово-\nра") == "настоящего договора"


def test_joins_hyphenation_with_trailing_spaces():
    assert normalize_text("настоящего догово- \n  ра") == "настоящего договора"


def test_keeps_hyphen_before_uppercase_or_digit_on_next_line():
    assert normalize_text("ИНН-\n7701") == "ИНН-\n7701"


def test_collapses_spaces_and_tabs_and_strips_lines():
    assert normalize_text("  Итого:\t\t15   000  \n") == "Итого: 15 000"


def test_normalizes_line_endings_and_limits_blank_lines():
    assert normalize_text("a\r\n\r\n\r\n\r\nb\rc") == "a\n\nb\nc"


def test_empty_text_stays_empty():
    assert normalize_text("   \n\n  ") == ""


@pytest.mark.parametrize(
    "sample",
    [
        "ООО «Ромашка» ИНН​7701 — догово- \nра",
        "﻿a\r\n\r\n\r\nb\x0cc\tﬁ １",
        "  кто-\nто  ",
    ],
)
def test_normalization_is_idempotent(sample):
    once = normalize_text(sample)
    assert normalize_text(once) == once
