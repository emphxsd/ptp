"""Синтетические значения для тестовой выборки: ФИО со склонением, организации, реквизиты, даты, суммы.
Все данные вымышленные; ИНН генерируются с верной контрольной суммой, как настоящие."""
import random
from dataclasses import dataclass
from datetime import date, timedelta

from app.fields.checksums import is_valid_inn

# (именительный, родительный) — для оборотов «в лице директора Петрова Петра Петровича»
_MALE_SURNAMES = (
    ("Иванов", "Иванова"), ("Петров", "Петрова"), ("Смирнов", "Смирнова"), ("Кузнецов", "Кузнецова"),
    ("Соколов", "Соколова"), ("Попов", "Попова"), ("Лебедев", "Лебедева"), ("Новиков", "Новикова"),
    ("Морозов", "Морозова"), ("Волков", "Волкова"), ("Захаров", "Захарова"), ("Белов", "Белова"),
)
_MALE_NAMES = (
    ("Иван", "Ивана"), ("Пётр", "Петра"), ("Алексей", "Алексея"), ("Сергей", "Сергея"), ("Андрей", "Андрея"),
    ("Дмитрий", "Дмитрия"), ("Михаил", "Михаила"), ("Николай", "Николая"), ("Олег", "Олега"),
    ("Владимир", "Владимира"),
)
_FEMALE_NAMES = (
    ("Анна", "Анны"), ("Мария", "Марии"), ("Елена", "Елены"), ("Ольга", "Ольги"), ("Татьяна", "Татьяны"),
    ("Наталья", "Натальи"), ("Ирина", "Ирины"), ("Светлана", "Светланы"), ("Екатерина", "Екатерины"),
)
# основа отчества: Иван → Иванович / Ивановна
_PATRONYMIC_BASES = ("Иванов", "Петров", "Алексеев", "Сергеев", "Андреев", "Дмитриев", "Михайлов", "Николаев",
                     "Олегов", "Владимиров")
_ORG_NAMES = ("Ромашка", "Вектор", "Альфа-Строй", "Северный ветер", "ТехноПарк", "Гранит", "Меридиан",
              "СтройИнвест", "Лотос", "Восход", "Прогресс", "Орион", "Сибирь-Трейд", "Импульс", "Кедр", "Бриз")
_LEGAL_FORMS = (
    ("ООО", "Общество с ограниченной ответственностью"),
    ("АО", "Акционерное общество"),
    ("ПАО", "Публичное акционерное общество"),
)
MONTHS_GENITIVE = ("января", "февраля", "марта", "апреля", "мая", "июня", "июля", "августа", "сентября",
                   "октября", "ноября", "декабря")
CITIES = ("Москва", "Санкт-Петербург", "Казань", "Новосибирск", "Екатеринбург", "Нижний Новгород", "Самара",
          "Краснодар", "Пермь", "Воронеж")
POSITIONS = ("Менеджер по продажам", "Бухгалтер", "Инженер-программист", "Аналитик данных", "Юрист",
             "Специалист по кадрам", "Офис-менеджер", "Логист", "Маркетолог", "Системный администратор")
# «им. М. В. Ломоносова» — намеренная помеха: инициалы и фамилия, не относящиеся к владельцу резюме
UNIVERSITIES = ("МГУ им. М. В. Ломоносова", "Санкт-Петербургский государственный университет",
                "Казанский федеральный университет", "Новосибирский государственный университет",
                "Уральский федеральный университет им. Б. Н. Ельцина", "НИУ ВШЭ")
SERVICES = ("Консультационные услуги", "Разработка программного обеспечения", "Техническое обслуживание",
            "Монтаж оборудования", "Транспортные услуги", "Аудит бухгалтерской отчётности", "Обучение персонала")
GOODS = ("Бумага офисная А4", "Картридж лазерный", "Монитор 24\"", "Клавиатура", "Кресло офисное",
         "Стол письменный", "Ноутбук", "Маршрутизатор", "Кабель UTP 305 м")
_TRANSLIT = str.maketrans({
    "а": "a", "б": "b", "в": "v", "г": "g", "д": "d", "е": "e", "ё": "e", "ж": "zh", "з": "z", "и": "i", "й": "y",
    "к": "k", "л": "l", "м": "m", "н": "n", "о": "o", "п": "p", "р": "r", "с": "s", "т": "t", "у": "u", "ф": "f",
    "х": "h", "ц": "ts", "ч": "ch", "ш": "sh", "щ": "sch", "ы": "y", "э": "e", "ю": "yu", "я": "ya", "ь": "",
    "ъ": "",
})


@dataclass(frozen=True)
class Person:
    surname: str
    name: str
    patronymic: str
    surname_gen: str
    name_gen: str
    patronymic_gen: str

    @property
    def full(self) -> str:
        return f"{self.surname} {self.name} {self.patronymic}"

    @property
    def full_genitive(self) -> str:
        return f"{self.surname_gen} {self.name_gen} {self.patronymic_gen}"

    @property
    def initials(self) -> str:
        """Как нормализует сервис: «Иванов И. И.»."""
        return f"{self.surname} {self.name[0]}. {self.patronymic[0]}."

    @property
    def latin(self) -> str:
        return self.surname.lower().translate(_TRANSLIT)


def person(rng: random.Random) -> Person:
    surname, surname_gen = rng.choice(_MALE_SURNAMES)
    base = rng.choice(_PATRONYMIC_BASES)
    if rng.random() < 0.5:
        name, name_gen = rng.choice(_MALE_NAMES)
        return Person(surname, name, base + "ич", surname_gen, name_gen, base + "ича")
    name, name_gen = rng.choice(_FEMALE_NAMES)
    # Иванова → Ивановой, Иванова Анна → Ивановой Анны, Ивановна → Ивановны
    return Person(surname + "а", name, base + "на", surname + "ой", name_gen, base + "ны")


@dataclass(frozen=True)
class Organization:
    form: str
    form_full: str
    name: str
    inn: str
    kpp: str

    @property
    def normalized(self) -> str:
        """Как нормализует сервис: «ООО "Ромашка"»."""
        return f'{self.form} "{self.name}"'

    def written(self, rng: random.Random) -> str:
        variant = rng.randrange(3)
        if variant == 0:
            return f"{self.form} «{self.name}»"
        if variant == 1:
            return f"{self.form_full} «{self.name}»"
        return f'{self.form} "{self.name}"'


def organization(rng: random.Random, exclude: str | None = None) -> Organization:
    form, form_full = rng.choice(_LEGAL_FORMS)
    name = rng.choice([n for n in _ORG_NAMES if n != exclude])
    return Organization(form, form_full, name, inn_legal(rng), kpp(rng))


def inn_legal(rng: random.Random) -> str:
    """ИНН юрлица: 10 цифр, контрольная цифра по весам ФНС."""
    while True:
        digits = [rng.randint(1, 9)] + [rng.randint(0, 9) for _ in range(8)]
        weights = (2, 4, 10, 3, 5, 9, 4, 6, 8)
        check = sum(d * w for d, w in zip(digits, weights)) % 11 % 10
        inn = "".join(map(str, digits)) + str(check)
        if is_valid_inn(inn):
            return inn


def kpp(rng: random.Random) -> str:
    return f"{rng.randint(10, 99)}{rng.randint(1, 99):02d}01{rng.randint(1, 999):03d}"


def bik(rng: random.Random) -> str:
    return f"04{rng.randint(10, 99)}{rng.randint(10, 99)}{rng.randint(100, 999)}"


def account(rng: random.Random) -> str:
    """Расчётный счёт — 20 цифр: не должен путаться с ИНН и суммами."""
    return "40702810" + "".join(str(rng.randint(0, 9)) for _ in range(12))


def random_date(rng: random.Random, start: date = date(2024, 1, 1), days: int = 1000) -> date:
    return start + timedelta(days=rng.randrange(days))


def written_date(rng: random.Random, value: date) -> str:
    variant = rng.randrange(3)
    if variant == 0:
        return value.strftime("%d.%m.%Y")
    if variant == 1:
        return f"«{value.day:02d}» {MONTHS_GENITIVE[value.month - 1]} {value.year} г."
    return f"{value.day} {MONTHS_GENITIVE[value.month - 1]} {value.year} года"


def random_amount(rng: random.Random, low: int = 1_000, high: int = 2_000_000) -> int:
    """Сумма в копейках."""
    rubles = rng.randint(low, high)
    kopecks = rng.choice((0, 0, rng.randint(1, 99)))
    return rubles * 100 + kopecks


def normalized_amount(kopecks: int) -> str:
    return f"{kopecks // 100}.{kopecks % 100:02d}"


def written_amount(rng: random.Random, kopecks: int) -> str:
    rubles, rest = divmod(kopecks, 100)
    grouped = f"{rubles:,}".replace(",", " ")
    variant = rng.randrange(3)
    if variant == 0:
        return f"{grouped},{rest:02d} руб."
    if variant == 1:
        return f"{rubles}.{rest:02d} ₽"
    return f"{grouped}-{rest:02d}"


def phone(rng: random.Random) -> tuple[str, str]:
    """(как написано, как нормализует сервис)."""
    code, a, b, c = rng.randint(900, 999), rng.randint(100, 999), rng.randint(10, 99), rng.randint(10, 99)
    variant = rng.randrange(3)
    written = (
        f"+7 ({code}) {a}-{b}-{c}" if variant == 0 else f"8-{code}-{a}-{b}-{c}" if variant == 1 else f"+7 {code} {a} {b} {c}"
    )
    return written, f"+7{code}{a}{b}{c}"


def email(rng: random.Random, who: Person) -> str:
    domain = rng.choice(("mail.ru", "yandex.ru", "gmail.com", "inbox.ru"))
    return f"{who.latin}{rng.randint(1, 999)}@{domain}"


def document_number(rng: random.Random) -> str:
    variant = rng.randrange(3)
    if variant == 0:
        return str(rng.randint(1, 999))
    if variant == 1:
        return f"{rng.randint(2024, 2026)}-{rng.randint(1, 9999):04d}"
    return f"{rng.choice('АБВКМП')}-{rng.randint(1, 99)}"
