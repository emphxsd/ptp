"""Распознавание текста на изображениях через Tesseract (внешняя программа, языки rus+eng).
Запуск без оболочки, с таймаутом; вывод в формате TSV — текст по строкам и уверенность по словам."""
import os
import re
import subprocess
from collections.abc import Callable
from dataclasses import dataclass
from typing import Protocol

# язык Tesseract — имя файла tessdata: rus, eng, chi_sim; несколько — через «+»
LANGUAGES_PATTERN = r"[a-z][a-z_]{1,15}(\+[a-z][a-z_]{1,15}){0,4}"
# в процесс Tesseract передаётся только необходимое окружение (без ключей сервиса)
_ENV_PASSTHROUGH = ("PATH", "SYSTEMROOT", "TEMP", "TMP", "TESSDATA_PREFIX", "LANG", "LC_ALL")
PAGE_SEGMENTATION_MODE = "3"  # автоматическая разметка страницы
LIST_LANGS_TIMEOUT_SECONDS = 10.0
_TSV_LEVEL_WORD = "5"
_TSV_COLUMNS = 12

Runner = Callable[..., subprocess.CompletedProcess]


@dataclass(frozen=True)
class OcrResult:
    text: str
    confidence: float | None  # средняя уверенность Tesseract по словам, 0–100


class OcrEngine(Protocol):
    def recognize(self, image: bytes, timeout: float) -> OcrResult:
        """image — PNG или JPEG. Ошибки — подклассы OcrError."""
        ...


class OcrError(Exception):
    code = "OCR_ERROR"


class OcrUnavailableError(OcrError):
    code = "OCR_UNAVAILABLE"


class OcrTimeoutError(OcrError):
    code = "OCR_TIMEOUT"


class OcrFailedRunError(OcrError):
    code = "OCR_RUN_FAILED"


class TesseractEngine:
    def __init__(self, cmd: str, languages: str, runner: Runner = subprocess.run):
        if not re.fullmatch(LANGUAGES_PATTERN, languages):
            raise ValueError("некорректный список языков Tesseract")
        self._cmd = cmd
        self._languages = languages
        self._run = runner
        # OpenMP внутри Tesseract занимает все ядра; параллельность задаётся числом одновременных запросов
        self._env = _child_env()

    def recognize(self, image: bytes, timeout: float) -> OcrResult:
        args = [self._cmd, "stdin", "stdout", "-l", self._languages, "--psm", PAGE_SEGMENTATION_MODE, "tsv"]
        try:
            completed = self._run(args, input=image, capture_output=True, timeout=timeout, env=self._env, check=False)
        except OSError:  # не найден, нет прав, не исполняемый файл
            raise OcrUnavailableError() from None
        except subprocess.TimeoutExpired:  # subprocess.run сам завершает процесс по таймауту
            raise OcrTimeoutError() from None
        if completed.returncode != 0:
            raise OcrFailedRunError()
        return parse_tsv(completed.stdout.decode("utf-8", errors="replace"))


def parse_tsv(tsv: str) -> OcrResult:
    """Слова (level 5) собираются в строки по (блок, абзац, строка); уверенность — среднее по словам."""
    lines: dict[tuple[str, str, str, str], list[str]] = {}
    confidences: list[float] = []
    for row in tsv.splitlines()[1:]:
        cells = row.split("\t")
        if len(cells) < _TSV_COLUMNS or cells[0] != _TSV_LEVEL_WORD:
            continue
        word = cells[11].strip()
        if not word:
            continue
        lines.setdefault((cells[1], cells[2], cells[3], cells[4]), []).append(word)
        try:
            confidence = float(cells[10])
        except ValueError:
            continue
        if confidence >= 0:
            confidences.append(confidence)
    text = "\n".join(" ".join(words) for words in lines.values())
    return OcrResult(text=text, confidence=sum(confidences) / len(confidences) if confidences else None)


def check_tesseract(cmd: str, languages: str, runner: Runner = subprocess.run) -> bool:
    """Tesseract установлен и в нём есть все нужные языки (например, rus — отдельный файл tessdata)."""
    try:
        completed = runner(
            [cmd, "--list-langs"], capture_output=True, timeout=LIST_LANGS_TIMEOUT_SECONDS, check=False, env=_child_env()
        )
    except (OSError, subprocess.TimeoutExpired):
        return False
    if completed.returncode != 0:
        return False
    output = (completed.stdout or b"").decode("utf-8", errors="replace").splitlines()
    available = {line.strip() for line in output[1:]}
    return all(language in available for language in languages.split("+"))


def _child_env() -> dict[str, str]:
    env = {name: os.environ[name] for name in _ENV_PASSTHROUGH if name in os.environ}
    env["OMP_THREAD_LIMIT"] = "1"
    return env


def resolve_command(cmd: str) -> str | None:
    """Абсолютный путь к программе. Ищется только по PATH: на Windows Python 3.11 shutil.which
    сначала смотрит текущий каталог, где может оказаться посторонний tesseract.exe."""
    if os.path.isabs(cmd):
        return cmd if os.path.isfile(cmd) else None
    if os.sep in cmd or (os.altsep and os.altsep in cmd):
        return None
    extensions = [""] + (os.environ.get("PATHEXT", ".EXE").split(os.pathsep) if os.name == "nt" else [])
    for directory in os.environ.get("PATH", "").split(os.pathsep):
        if not os.path.isabs(directory):
            continue
        for extension in extensions:
            candidate = os.path.join(directory, cmd + extension)
            if os.path.isfile(candidate):
                return candidate
    return None
