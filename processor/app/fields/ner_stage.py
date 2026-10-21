"""NER-этап: уточняет поля-людей, поля-организации и поля с явным ner_label, если правила
ничего не нашли или нашли неуверенно. Логика окон и уверенности — как у правил:
сначала окно после якоря, затем начало документа; неоднозначность понижает уверенность."""
import time
from dataclasses import dataclass

from app.fields.ner import FEMININE_PATRONYMIC, LEGAL_FORMS, MASCULINE_PATRONYMIC, Entity, EntityRecognizer
from app.fields.normalizers import normalize_organization, normalize_person
from app.fields.rules import DocumentIndex, RuleMatch, keyword_window
from app.schemas import DataType, TemplateField

LABEL_BY_TYPE = {DataType.PERSON: "PER", DataType.ORGANIZATION: "ORG"}

CONFIDENCE_NEAR_KEYWORD = 0.85
CONFIDENCE_NEXT_LINE = 0.75
CONFIDENCE_NAMES_NEAR_KEYWORD = 0.75
CONFIDENCE_AMBIGUOUS_NEAR = 0.5
CONFIDENCE_UNIQUE = 0.65
CONFIDENCE_NAMES_UNIQUE = 0.55  # словарный поиск без якоря — самый слабый источник
CONFIDENCE_AMBIGUOUS = 0.4
CONFIDENCE_KEYWORD_MISSED = 0.5
CONFIDENCE_SINGLE_WORD_PERSON = 0.5
# «Михаил Евграфович» без фамилии; организация без правовой формы и кавычек («Владение», «SQL»)
CONFIDENCE_INCOMPLETE_PERSON = 0.5
CONFIDENCE_BARE_ORGANIZATION = 0.55

# NER на коротких фрагментах: окно после якоря + немного контекста перед ним; начало документа
NER_WINDOW_CHARS = 300
NER_CONTEXT_BEFORE = 60
NER_SCAN_CHARS = 3_000  # «шапка» документа: стороны, ФИО в резюме
# общий бюджет NER на документ: ≈0,15 с на 360 символов, под общей блокировкой моделей
MAX_NER_CHARS_PER_DOCUMENT = 10_000
# каждая строка — отдельный прогон моделей (≈0,5 мс накладных); плюс жёсткий лимит времени
MAX_NER_LINES_PER_DOCUMENT = 500
MAX_NER_SECONDS_PER_DOCUMENT = 2.0
NAMES_SCAN_CHARS = 2_000
MAX_ALTERNATIVES = 3  # минимизация ПДн: имена других людей документа


@dataclass(frozen=True)
class _Found:
    entity: Entity
    from_names: bool
    next_line: bool


def ner_label_for(field: TemplateField) -> str | None:
    return field.ner_label or LABEL_BY_TYPE.get(field.data_type)


class NerStage:
    def __init__(self, recognizer: EntityRecognizer, index: DocumentIndex):
        self._recognizer = recognizer
        self._index = index
        self._cache: dict[tuple[str, int, int], tuple[Entity, ...]] = {}
        self._budget = MAX_NER_CHARS_PER_DOCUMENT
        self._lines_left = MAX_NER_LINES_PER_DOCUMENT
        self._deadline = time.perf_counter() + MAX_NER_SECONDS_PER_DOCUMENT

    @staticmethod
    def applies_to(field: TemplateField) -> bool:
        return ner_label_for(field) is not None

    def find(self, field: TemplateField) -> RuleMatch | None:
        label = ner_label_for(field)
        if label is None:
            return None
        return self._near_keywords(field, label) or self._in_document(field, label)

    def _near_keywords(self, field: TemplateField, label: str) -> RuleMatch | None:
        text = self._index.text
        for keyword in field.keywords:
            found: list[_Found] = []
            for keyword_end in self._index.keyword_ends(keyword):
                window = keyword_window(text, keyword_end, NER_WINDOW_CHARS)
                if window is None:
                    continue
                context_start = _word_start(text, max(0, window.start - NER_CONTEXT_BEFORE))
                found.extend(
                    _Found(entity, from_names, window.next_line)
                    for entity, from_names in self._entities(label, context_start, window.end, window.start, window.end)
                )
            if found:
                return self._decide(found, label, near_keyword=True, keywords_given=True)
        return None

    def _in_document(self, field: TemplateField, label: str) -> RuleMatch | None:
        length = len(self._index.text)
        found = [
            _Found(entity, from_names, next_line=False)
            for entity, from_names in self._entities(label, 0, min(length, NER_SCAN_CHARS), 0, min(length, NAMES_SCAN_CHARS))
        ]
        if not found:
            return None
        return self._decide(found, label, near_keyword=False, keywords_given=bool(field.keywords))

    def _entities(self, label: str, start: int, end: int, min_start: int, names_end: int) -> list[tuple[Entity, bool]]:
        """Сущности нужного типа в [start, end), начинающиеся не раньше min_start.
        Для людей, если нейросеть ничего не нашла, — словарный поиск ФИО в [min_start, names_end)."""
        selected = [(e, False) for e in self._recognize("ner", start, end) if e.label == label and e.start >= min_start]
        if selected or label != "PER" or names_end <= min_start:
            return selected
        return [(e, True) for e in self._recognize("names", min_start, names_end)]

    def _recognize(self, kind: str, start: int, end: int) -> tuple[Entity, ...]:
        key = (kind, start, end)
        if key in self._cache:
            return self._cache[key]
        text = self._index.text
        fragment = text[start:end]
        lines = fragment.count("\n") + 1
        if end - start > self._budget or lines > self._lines_left or time.perf_counter() > self._deadline:
            return ()  # бюджет документа исчерпан: оставшиеся поля останутся за правилами / проверкой
        self._budget -= end - start
        self._lines_left -= lines
        raw = self._recognizer.entities(fragment) if kind == "ner" else self._recognizer.names(fragment)
        self._cache[key] = tuple(
            Entity(e.label, e.text, e.start + start, e.end + start, e.normal)
            for e in raw
            # сущность, обрезанная границей фрагмента посреди слова («Иванова Мар»), не берётся
            if not (e.end == len(fragment) and end < len(text) and text[end].isalnum())
        )
        return self._cache[key]

    def _decide(self, found: list[_Found], label: str, near_keyword: bool, keywords_given: bool) -> RuleMatch:
        values = tuple(dict.fromkeys(_value(f.entity, label) for f in found))[:MAX_ALTERNATIVES]
        best = found[0]
        alternatives: tuple[str, ...] = ()
        if len(values) > 1:
            confidence = CONFIDENCE_AMBIGUOUS_NEAR if near_keyword else CONFIDENCE_AMBIGUOUS
            alternatives = values
        elif near_keyword:
            if best.from_names:
                confidence = CONFIDENCE_NAMES_NEAR_KEYWORD
            elif all(f.next_line for f in found):
                confidence = CONFIDENCE_NEXT_LINE
            else:
                confidence = CONFIDENCE_NEAR_KEYWORD
        else:
            confidence = CONFIDENCE_NAMES_UNIQUE if best.from_names else CONFIDENCE_UNIQUE
        value = values[0] if not alternatives else _value(best.entity, label)
        if label == "PER" and " " not in value.strip():
            # одно слово («Иванов», «Мария») — не ФИО
            confidence = min(confidence, CONFIDENCE_SINGLE_WORD_PERSON)
        if label == "PER" and _first_name_and_patronymic_only(value):
            confidence = min(confidence, CONFIDENCE_INCOMPLETE_PERSON)
        if label == "ORG" and '"' not in value and value.split()[0] not in LEGAL_FORMS:
            confidence = min(confidence, CONFIDENCE_BARE_ORGANIZATION)
        if not near_keyword and keywords_given:
            confidence = min(confidence, CONFIDENCE_KEYWORD_MISSED)
        entity = best.entity
        return RuleMatch(
            value=value, raw=entity.text, start=entity.start, end=entity.end,
            confidence=confidence, alternatives=alternatives,
        )


def _value(entity: Entity, label: str) -> str:
    if label == "PER":
        return normalize_person(entity.normal) or entity.normal
    if label == "ORG":
        return normalize_organization(entity.normal) or entity.normal
    return entity.normal


def _first_name_and_patronymic_only(value: str) -> bool:
    words = value.split()
    last = words[-1].lower() if words else ""
    return len(words) == 2 and bool(FEMININE_PATRONYMIC.search(last) or MASCULINE_PATRONYMIC.search(last))


def _word_start(text: str, position: int) -> int:
    """Сдвигает начало контекста вперёд до границы слова."""
    for _ in range(NER_CONTEXT_BEFORE):  # не дальше длины контекста: иначе O(длина слова) на длинных «словах»
        if not (0 < position < len(text) and text[position - 1].isalnum()):
            break
        position += 1
    return position
