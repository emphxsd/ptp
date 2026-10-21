"""Пользовательские регулярные выражения выполняются через RE2 — без риска ReDoS."""
import time

import pytest

from app.fields.user_regex import UserRegexError, compile_user_regex


def test_simple_pattern_compiles_and_matches():
    pattern = compile_user_regex(r"Р/с\s*(\d{20})")

    match = pattern.search("Р/с 40702810400000012345")

    assert match.group(1) == "40702810400000012345"


@pytest.mark.parametrize("bad", [r"(?=x)abc", r"(a)\1", r"(?<!\d)\d+", r"([0-9"])
def test_unsupported_or_invalid_patterns_are_rejected(bad):
    with pytest.raises(UserRegexError):
        compile_user_regex(bad)


@pytest.mark.parametrize("empty_matching", [r"\d*", r"a?", r"(x|)"])
def test_patterns_matching_empty_string_are_rejected(empty_matching):
    with pytest.raises(UserRegexError):
        compile_user_regex(empty_matching)


def test_catastrophic_pattern_runs_in_linear_time():
    pattern = compile_user_regex(r"(a+)+$")
    text = "a" * 500_000 + "!"

    started = time.perf_counter()
    assert pattern.search(text) is None
    assert time.perf_counter() - started < 1.0
