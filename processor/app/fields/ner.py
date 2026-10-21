"""Распознавание именованных сущностей на русском языке через Natasha.

entities() — нейросетевой NER (PER — люди, ORG — организации, LOC — места).
names() — запасной словарный поиск полных ФИО (NamesExtractor): находит имя в одиночной
строке без контекста, где нейросеть ошибается; медленнее, поэтому только на коротких фрагментах.

Поправки к моделям (выявлены на реальных документах):
- текст подаётся построчно — иначе сущности «склеиваются» через перевод строки
  («Иванова Мария Сергеевна\\nМосква\\nПрограммист» становилось одним ФИО);
- ФИО приводится к именительному падежу своей функцией с учётом рода (по отчеству):
  лемматизация Natasha превращала «Смирнова Анна Викторовна» в «Смирнов Анна Викторовна»;
- название организации берётся как в тексте: лемматизация портила его («Сбербанк России» → «Сбербанк Россия»);
- ФИО, разбитое на две сущности, и двойная фамилия, принятая за место, склеиваются;
- обрезанная закрывающая кавычка дотягивается; шум (заголовки, реквизиты, голая форма) отбрасывается."""
import re
import threading
from dataclasses import dataclass, replace
from typing import Protocol

_REQUISITES = frozenset(
    {"ИНН", "КПП", "БИК", "ОГРН", "ОГРНИП", "НДС", "ОКПО", "ОКВЭД", "ОКТМО", "ОКАТО", "ЕГРЮЛ", "ЕГРИП", "РФ", "Р/С", "К/С"}
)
LEGAL_FORMS = frozenset({"ООО", "АО", "ПАО", "ЗАО", "ОАО", "НАО", "ИП", "АНО", "НКО", "ТСЖ", "ГУП", "МУП", "ФГУП"})
# закрывающая кавычка ищется не дальше этого числа символов (длинные названия встречаются редко)
MAX_QUOTE_EXTENSION = 60
MIN_NAME_PART_CHARS = 2
# склейка соседних PER не должна объединять двух разных людей
MAX_MERGED_PERSON_WORDS = 4
# словарный поиск имён сверхлинеен по времени и памяти (20 тыс. символов — до 11 с и 2,8 ГБ)
MAX_NAMES_CHARS = 3_000

_LINE = re.compile(r"[^\n]+")
_DOUBLE_SURNAME = re.compile(r"[А-ЯЁ][а-яё]+-[А-ЯЁ][а-яё]+")
_TRAILING_INITIAL = re.compile(r"(?:^|[\s.])[А-ЯЁ]$")
FEMININE_PATRONYMIC = re.compile(r"(?:вн|чн)(?:а|ы|е|у|ой|ою)$")
MASCULINE_PATRONYMIC = re.compile(r"(?:вич|ич)(?:а|у|ем|е)?$")
_QUOTE_DELIMITERS = frozenset(" \t,.;:)")


@dataclass(frozen=True)
class Entity:
    label: str  # PER | ORG | LOC
    text: str  # фрагмент исходного текста
    start: int  # смещения — относительно переданного распознавателю фрагмента
    end: int
    normal: str  # нормальная форма: ФИО и места — именительный падеж, организация — как в тексте


class EntityRecognizer(Protocol):
    """Смещения start/end сущностей отсчитываются от начала переданного фрагмента."""

    def entities(self, text: str) -> list[Entity]: ...

    def names(self, text: str) -> list[Entity]: ...


class NatashaRecognizer:
    """Модели загружаются один раз (≈1 с). Вызовы сериализуются: потокобезопасность моделей не гарантирована."""

    def __init__(self) -> None:
        from natasha import Doc, MorphVocab, NamesExtractor, NewsEmbedding, NewsMorphTagger, NewsNERTagger, Segmenter

        embedding = NewsEmbedding()
        self._doc = Doc
        self._segmenter = Segmenter()
        self._vocab = MorphVocab()
        self._ner = NewsNERTagger(embedding)
        self._morph = NewsMorphTagger(embedding)
        self._names = NamesExtractor(self._vocab)
        self._lock = threading.Lock()

    def entities(self, text: str) -> list[Entity]:
        found: list[Entity] = []
        with self._lock:
            for line in _LINE.finditer(text):
                found.extend(self._line_entities(line.group(), line.start()))
        return found

    def names(self, text: str) -> list[Entity]:
        found: list[Entity] = []
        text = text[:MAX_NAMES_CHARS]
        with self._lock:
            for match in self._names(text):
                fact = match.fact
                parts = [p for p in (fact.last, fact.first, fact.middle) if p]
                # только полные ФИО (фамилия + имя); инициалы и одиночные слова — забота правил
                if not (fact.last and fact.first) or any(
                    len(p) < MIN_NAME_PART_CHARS or not p[0].isupper() for p in parts
                ):
                    continue
                surface = text[match.start:match.stop]
                if "\n" in surface:
                    continue
                found.append(Entity("PER", surface, match.start, match.stop, self.normalize_person(surface)))
        return found

    def normalize_person(self, text: str) -> str:
        """ФИО в именительном падеже с сохранением порядка слов и «ё»; род — по отчеству."""
        words = text.split()
        gender = _gender(words)
        normalized = []
        for word in words:
            if "." in word or len(word) < MIN_NAME_PART_CHARS:
                normalized.append(word)  # инициалы
            else:
                normalized.append("-".join(self._nominative(part, gender) for part in word.split("-")))
        return " ".join(normalized)

    def _nominative(self, word: str, gender: str | None) -> str:
        forms = self._vocab.parse(word)
        compatible = [f for f in forms if gender is None or f.feats.get("Gender") in (gender, None)] or forms
        if any(f.feats.get("Case") == "Nom" for f in compatible):
            return word
        for form in compatible:
            inflected = form.inflect({"Nom"})
            if inflected is not None:
                return inflected.word.capitalize()
        return word

    def _line_entities(self, line: str, offset: int) -> list[Entity]:
        doc = self._doc(line)
        doc.segment(self._segmenter)
        doc.tag_morph(self._morph)
        doc.tag_ner(self._ner)
        raw: list[Entity] = []
        for span in doc.spans:
            normal = span.text
            if span.type == "LOC":
                span.normalize(self._vocab)
                normal = span.normal or span.text
            raw.append(Entity(span.type, span.text, span.start, span.stop, normal))

        entities = _merge_people(raw, line)
        result: list[Entity] = []
        for i, entity in enumerate(entities):
            next_start = entities[i + 1].start if i + 1 < len(entities) else len(line)
            entity = _close_quote(_extend_initials(entity, line), line, next_start)
            if _is_noise(entity):
                continue
            if entity.label == "PER":
                entity = replace(entity, normal=self.normalize_person(entity.text))
            result.append(replace(entity, start=entity.start + offset, end=entity.end + offset))
        return result


def _gender(words: list[str]) -> str | None:
    for word in words:
        lowered = word.lower()
        if FEMININE_PATRONYMIC.search(lowered):
            return "Fem"
        if len(lowered) > 4 and MASCULINE_PATRONYMIC.search(lowered):
            return "Masc"
    return None


def _merge_people(entities: list[Entity], line: str) -> list[Entity]:
    """«Ивановой» + «Марией Сергеевной» и «Салтыков-Щедрин» (принятый за место) + «Михаил Евграфович»."""
    merged: list[Entity] = []
    for entity in entities:
        previous = merged[-1] if merged else None
        gap = line[previous.end:entity.start] if previous else ""
        adjacent = previous is not None and entity.label == "PER" and gap != "" and gap.strip(" ") == ""
        if adjacent and (
            (previous.label == "PER" and _can_merge(previous.text, entity.text))
            or (previous.label in ("LOC", "ORG") and _DOUBLE_SURNAME.fullmatch(previous.text))
        ):
            text = line[previous.start:entity.end]
            merged[-1] = Entity("PER", text, previous.start, entity.end, text)
        else:
            merged.append(entity)
    return merged


def _can_merge(left: str, right: str) -> bool:
    # «Иванов И. И.» + «Петров П. П.» — два разных человека
    return "." not in left and len(left.split()) + len(right.split()) <= MAX_MERGED_PERSON_WORDS


def _extend_initials(entity: Entity, line: str) -> Entity:
    """«Иванов И.И» → «Иванов И.И.»: модель отрезает точку после последнего инициала."""
    if entity.label == "PER" and line[entity.end:entity.end + 1] == "." and _TRAILING_INITIAL.search(entity.text):
        return replace(entity, text=entity.text + ".", end=entity.end + 1)
    return entity


def _close_quote(entity: Entity, line: str, next_start: int) -> Entity:
    """АО "Тинькофф Банк → АО "Тинькофф Банк": до кавычки, за которой идёт разделитель,
    не заходя на следующую сущность."""
    if entity.label != "ORG" or entity.text.count('"') % 2 == 0:
        return entity
    limit = min(next_start, entity.end + MAX_QUOTE_EXTENSION, len(line))
    for position in range(entity.end, limit):
        if line[position] == '"' and (position + 1 == len(line) or line[position + 1] in _QUOTE_DELIMITERS):
            text = line[entity.start:position + 1]
            return replace(entity, text=text, end=position + 1, normal=text)
    return entity


def _is_noise(entity: Entity) -> bool:
    if entity.label != "ORG":
        return False
    text = entity.text.strip()
    first = text.split()[0].upper() if text else ""
    if first in _REQUISITES or text.upper() in LEGAL_FORMS:
        return True
    # заголовок капсом («ДОГОВОР ПОСТАВКИ», «СЧЁТ-ФАКТУРА») без правовой формы и кавычек
    return text.isupper() and '"' not in text and first not in LEGAL_FORMS


_recognizer: NatashaRecognizer | None = None
_load_lock = threading.Lock()


def get_recognizer() -> NatashaRecognizer:
    """Единственный экземпляр на процесс; блокировка исключает параллельную загрузку моделей."""
    global _recognizer
    if _recognizer is None:
        with _load_lock:
            if _recognizer is None:
                _recognizer = NatashaRecognizer()
    return _recognizer
