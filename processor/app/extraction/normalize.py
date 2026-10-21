"""Предобработка текста: единые пробелы, кавычки и дефисы, склейка переносов.

Компромисс: перенос «строчная-\\nстрочная» всегда склеивается, поэтому настоящие дефисные слова,
разорванные на границе строки («кто-\\nто»), превращаются в «ктото». Для поиска реквизитов
(даты, суммы, ИНН) это не влияет, а разорванные переносом слова встречаются в PDF намного чаще."""
import re

_SPACES = "           　\t"
_ZERO_WIDTH = "​‌‍⁠﻿­"  # включая мягкий перенос
_LINE_BREAKS = "\x0b\x0c  "
_DOUBLE_QUOTES = "«»“”„‟″"
_SINGLE_QUOTES = "‘’‚‛"
_DASHES = "‐‑‒–—―−"
_LIGATURES = {"ﬀ": "ff", "ﬁ": "fi", "ﬂ": "fl", "ﬃ": "ffi", "ﬄ": "ffl", "ﬅ": "st", "ﬆ": "st"}
_FULLWIDTH_DIGITS = {chr(0xFF10 + d): str(d) for d in range(10)}

_TRANSLATION = str.maketrans(
    {c: " " for c in _SPACES}
    | {c: None for c in _ZERO_WIDTH}
    | {c: "\n" for c in _LINE_BREAKS}
    | {c: '"' for c in _DOUBLE_QUOTES}
    | {c: "'" for c in _SINGLE_QUOTES}
    | {c: "-" for c in _DASHES}
    | _LIGATURES
    | _FULLWIDTH_DIGITS
)

# перенос слова: строчная буква, дефис (возможно с пробелами) в конце строки, продолжение со строчной
_HYPHENATION = re.compile(r"(?<=[a-zа-яё])-[ \t]*\n[ \t]*(?=[a-zа-яё])")
_MULTI_SPACE = re.compile(r" {2,}")
_MULTI_BLANK_LINES = re.compile(r"\n{3,}")


def normalize_text(text: str) -> str:
    text = text.replace("\r\n", "\n").replace("\r", "\n").translate(_TRANSLATION)
    text = _HYPHENATION.sub("", text)
    text = "\n".join(_MULTI_SPACE.sub(" ", line).strip() for line in text.split("\n"))
    return _MULTI_BLANK_LINES.sub("\n\n", text).strip()
