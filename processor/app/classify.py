"""Определение типа документа: какой из шаблонов пользователя подходит к тексту лучше всего.
Сравниваются подписи и слова-якоря полей шаблона (поиск — как у правил извлечения, DocumentIndex)
и название шаблона: «Счёт на оплату» в заголовке — сильный признак. Без уверенного победителя шаблон не предлагается."""
from collections.abc import Sequence

from app.config import Settings
from app.extraction.file_types import detect_format
from app.extraction.ocr import OcrEngine
from app.extraction.ocr_stage import apply_ocr
from app.extraction.text import extract_text
from app.fields.rules import DocumentIndex
from app.schemas import CandidateScore, ClassifyCandidate, ClassifyResult

# доля найденных фраз шаблона, ниже которой шаблон не предлагается
MIN_SCORE = 0.3
# отрыв от второго места: при почти равных кандидатах лучше не угадывать
MIN_MARGIN = 0.15
# балл = вес фраз × доля найденных фраз шаблона + бонус за заголовок; максимум — 1
PHRASES_WEIGHT = 0.6
# название шаблона целиком — в заголовке документа (первая строка: «Счёт на оплату № 15»)
NAME_BONUS = 0.4
# в заголовке только первое слово названия: акт ссылается на договор, но начинается словом «АКТ»
TITLE_WORD_BONUS = 0.3
TITLE_CHARS = 80
MIN_TITLE_WORD = 3
# для определения типа достаточно первых страниц скана
OCR_PAGES = 2


def classify(text: str, candidates: Sequence[ClassifyCandidate]) -> ClassifyResult:
    index = DocumentIndex(text)
    # заголовок — первая непустая строка: во второй («Основание: договор …») уже может быть другой тип документа
    title = DocumentIndex(text.lstrip().split("\n", 1)[0][:TITLE_CHARS])
    scores = sorted(
        (CandidateScore(id=c.id, score=round(_score(index, title, c), 3)) for c in candidates),
        key=lambda s: s.score,
        reverse=True,
    )
    best = scores[0] if scores else None
    runner_up = scores[1].score if len(scores) > 1 else 0.0
    confident = best is not None and best.score >= MIN_SCORE and best.score - runner_up >= MIN_MARGIN
    return ClassifyResult(
        template_id=best.id if confident else None,
        confidence=best.score if best else 0.0,
        scores=tuple(scores),
    )


def classify_document(
    data: bytes, candidates: Sequence[ClassifyCandidate], settings: Settings, ocr: OcrEngine | None
) -> ClassifyResult:
    """Текст документа (у скана — первые страницы через OCR) и определение шаблона; без текста — без предложения."""
    extracted = extract_text(data, detect_format(data), settings)
    if extracted.pages_without_text and ocr is not None and settings.ocr_enabled:
        extracted, _ = apply_ocr(data, extracted, settings.model_copy(update={"ocr_max_pages": OCR_PAGES}), ocr)
    return classify(extracted.text, candidates)


def _score(index: DocumentIndex, title: DocumentIndex, candidate: ClassifyCandidate) -> float:
    phrases = {p.lower() for p in candidate.phrases}
    found = sum(1 for p in phrases if index.keyword_ends(p))
    share = found / len(phrases) if phrases else 0.0
    name = candidate.name.lower()
    first_word = name.split()[0] if name.split() else ""
    if title.keyword_ends(name):
        bonus = NAME_BONUS
    elif len(first_word) >= MIN_TITLE_WORD and title.keyword_ends(first_word):
        bonus = TITLE_WORD_BONUS
    else:
        bonus = 0.0
    return PHRASES_WEIGHT * share + bonus
