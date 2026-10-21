"""Пользовательские регулярные выражения: только через RE2.

RE2 гарантирует линейное время по длине текста, поэтому шаблон вида (a+)+$ не может
«подвесить» сервис (ReDoS). Но константа зависит от размера скомпилированной программы:
короткий (\\pL?){300}x разворачивается в сотни тысяч инструкций и обрабатывает 2 млн символов
минутами. Поэтому размер программы ограничен; обратные ссылки и lookaround RE2 не поддерживает —
такие шаблоны отклоняются при проверке шаблона, а не во время обработки."""
from functools import lru_cache

import re2

MAX_PROGRAM_SIZE = 300  # инструкций; обычные шаблоны реквизитов занимают 10–60
MAX_PROGRAM_MEMORY = 2 * 1024 * 1024
COMPILED_CACHE_SIZE = 64
# пробные строки: шаблон не должен давать пустых совпадений ни на одной (например, \b или ^)
_EMPTY_MATCH_PROBES = ("", "a", "1", " ", "аб", "a 1", "\n")
# \C в RE2 совпадает с одним байтом UTF-8 и режет кириллицу пополам
_BYTE_ESCAPE = "\\C"


class UserRegexError(ValueError):
    pass


def _options() -> "re2.Options":
    """Ограничение памяти программы RE2 и отключение логирования ошибок разбора в stderr."""
    options = re2.Options()
    options.max_mem = MAX_PROGRAM_MEMORY
    options.log_errors = False
    return options


@lru_cache(maxsize=COMPILED_CACHE_SIZE)
def compile_user_regex(pattern: str) -> "re2._Regexp":
    """Компилирует шаблон пользователя через RE2 или бросает UserRegexError с понятным текстом.
    В RE2 \\w и \\b — только латиница; для кириллицы используйте [А-Яа-яЁё] или \\pL."""
    if _BYTE_ESCAPE in pattern:
        raise UserRegexError("конструкция \\C не поддерживается")
    try:
        compiled = re2.compile(pattern, _options())
    except Exception:  # re2 бросает собственное исключение; его текст содержит шаблон — не пробрасываем
        raise UserRegexError(
            "некорректное или неподдерживаемое регулярное выражение "
            "(не поддерживаются обратные ссылки и проверки вида (?=…), (?<!…))"
        ) from None
    if not 0 < compiled.programsize <= MAX_PROGRAM_SIZE or not 0 < compiled.reverseprogramsize <= MAX_PROGRAM_SIZE:
        raise UserRegexError("регулярное выражение слишком сложное (уменьшите число повторов {n} и вложенность)")
    for probe in _EMPTY_MATCH_PROBES:
        if any(m.start() == m.end() for m in compiled.finditer(probe)):
            raise UserRegexError("регулярное выражение не должно совпадать с пустой строкой")
    return compiled
