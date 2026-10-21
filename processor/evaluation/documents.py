"""Синтетические документы четырёх типов с эталонными значениями полей.
У каждого типа несколько вариантов вёрстки и формулировок, есть «отвлекающие» значения (НДС, даты оплаты,
реквизиты договора-основания, банк) и необязательные поля, которых в части документов нет (эталон — null)."""
import random
from dataclasses import dataclass
from datetime import date

from evaluation import fakes

Block = tuple[str, object]  # ("h" | "p", текст) или ("table", строки)


@dataclass(frozen=True)
class SyntheticDocument:
    doc_type: str
    blocks: tuple[Block, ...]
    expected: dict[str, str | None]


def invoice(rng: random.Random) -> SyntheticDocument:
    supplier, buyer = fakes.organization(rng), fakes.organization(rng)
    number, day = fakes.document_number(rng), fakes.random_date(rng)
    items = [(rng.choice(fakes.GOODS), rng.randint(1, 20), fakes.random_amount(rng, 100, 50_000)) for _ in range(rng.randint(1, 4))]
    total = sum(qty * price for _, qty, price in items)
    with_vat = rng.random() < 0.7
    vat = round(total * 20 / 120) if with_vat else None
    bik = fakes.bik(rng)
    supplier_label, buyer_label = rng.choice((("Поставщик", "Покупатель"), ("Исполнитель", "Заказчик"), ("Продавец", "Плательщик")))
    total_label = rng.choice(("Итого к оплате", "Всего к оплате", "Итого"))
    rows = [["№", "Наименование", "Кол-во", "Цена", "Сумма"]] + [
        [str(i), name, str(qty), fakes.written_amount(rng, price), fakes.written_amount(rng, qty * price)]
        for i, (name, qty, price) in enumerate(items, start=1)
    ]
    blocks: list[Block] = [
        ("h", f"Счёт на оплату № {number} от {fakes.written_date(rng, day)}"),
        ("p", f"{supplier_label}: {supplier.written(rng)}, ИНН {supplier.inn}, КПП {supplier.kpp}"),
        ("p", f"Банк получателя: ПАО «Сбербанк», БИК {bik}, р/с {fakes.account(rng)}"),
        ("p", f"{buyer_label}: {buyer.written(rng)}, ИНН {buyer.inn}"),
        ("table", rows),
        ("p", f"{total_label}: {fakes.written_amount(rng, total)}"),
        ("p", f"В том числе НДС 20%: {fakes.written_amount(rng, vat)}" if with_vat else "Без НДС"),
        ("p", f"Оплатить до {fakes.written_date(rng, fakes.random_date(rng))}"),
        ("p", f"Руководитель ____________ {fakes.person(rng).initials}"),
    ]
    if rng.random() < 0.5:  # реквизиты банка иногда идут после покупателя
        blocks[2], blocks[3] = blocks[3], blocks[2]
    return SyntheticDocument(
        "invoice",
        tuple(blocks),
        {
            "number": number,
            "date": day.isoformat(),
            "supplier": supplier.normalized,
            "supplier_inn": supplier.inn,
            "buyer": buyer.normalized,
            "bik": bik,
            "total": fakes.normalized_amount(total),
            "vat": fakes.normalized_amount(vat) if vat is not None else None,
        },
    )


def act(rng: random.Random) -> SyntheticDocument:
    contractor, customer = fakes.organization(rng), fakes.organization(rng)
    number, day = fakes.document_number(rng), fakes.random_date(rng)
    signer, other = fakes.person(rng), fakes.person(rng)
    services = [(rng.choice(fakes.SERVICES), fakes.random_amount(rng, 5_000, 300_000)) for _ in range(rng.randint(1, 3))]
    total = sum(price for _, price in services)
    header = (
        [("h", f"Акт № {number} от {fakes.written_date(rng, day)}")]
        if rng.random() < 0.5
        else [("h", f"АКТ ВЫПОЛНЕННЫХ РАБОТ № {number}"), ("p", f"Дата составления: {fakes.written_date(rng, day)}")]
    )
    rows = [["№", "Наименование работ, услуг", "Сумма"]] + [
        [str(i), name, fakes.written_amount(rng, price)] for i, (name, price) in enumerate(services, start=1)
    ]
    total_label = rng.choice(("Итого", "Всего оказано услуг на сумму", "Общая стоимость работ"))
    blocks: list[Block] = [
        *header,
        ("p", f"Исполнитель: {contractor.written(rng)}, ИНН {contractor.inn}"),
        ("p", f"Заказчик: {customer.written(rng)}, ИНН {customer.inn}"),
        ("p", f"Основание: Договор № {fakes.document_number(rng)} от {fakes.written_date(rng, fakes.random_date(rng))}"),
        ("table", rows),
        ("p", f"{total_label}: {fakes.written_amount(rng, total)}"),
        ("p", "Вышеперечисленные работы (услуги) выполнены полностью и в срок. Заказчик претензий по объёму, "
              "качеству и срокам оказания услуг не имеет."),
        ("p", f"От исполнителя: ____________ {signer.initials}"),
        ("p", f"От заказчика: ____________ {other.initials}"),
    ]
    return SyntheticDocument(
        "act",
        tuple(blocks),
        {
            "number": number,
            "date": day.isoformat(),
            "contractor": contractor.normalized,
            "contractor_inn": contractor.inn,
            "customer": customer.normalized,
            "total": fakes.normalized_amount(total),
            "signer": signer.initials,
        },
    )


def contract(rng: random.Random) -> SyntheticDocument:
    supplier = fakes.organization(rng)
    buyer = fakes.organization(rng, exclude=supplier.name)
    director, buyer_director = fakes.person(rng), fakes.person(rng)
    number, day, city = fakes.document_number(rng), fakes.random_date(rng), rng.choice(fakes.CITIES)
    amount = fakes.random_amount(rng, 50_000, 5_000_000) // 100 * 100
    days = rng.choice((5, 10, 14, 20, 30))
    term = f"в течение {days} рабочих дней с момента оплаты"
    kind, roles, other_dative = rng.choice(
        (("ПОСТАВКИ", ("Поставщик", "Покупатель"), "покупателю"), ("ОКАЗАНИЯ УСЛУГ", ("Исполнитель", "Заказчик"), "заказчику"))
    )
    blocks: list[Block] = [
        ("h", f"ДОГОВОР {kind} № {number}"),
        ("p", f"г. {city}        {fakes.written_date(rng, day)}"),
        ("p", f"{supplier.written(rng)}, именуемое в дальнейшем «{roles[0]}», в лице генерального директора "
              f"{director.full_genitive}, действующего на основании Устава, с одной стороны, и {buyer.written(rng)}, "
              f"именуемое в дальнейшем «{roles[1]}», в лице директора {buyer_director.full_genitive}, действующего "
              "на основании Устава, с другой стороны, заключили настоящий договор о нижеследующем."),
        ("h", "1. Предмет договора"),
        ("p", f"1.1. {roles[0]} обязуется передать (оказать) {other_dative} товары (услуги) согласно "
              "спецификации, являющейся неотъемлемой частью настоящего договора."),
        ("h", "2. Цена договора и порядок расчётов"),
        ("p", f"2.1. Цена договора составляет {fakes.written_amount(rng, amount)} (включая НДС 20%)."),
        ("p", "2.2. Оплата производится в течение 5 банковских дней с даты выставления счёта."),
        ("h", "3. Сроки исполнения"),
        ("p", f"3.1. Срок исполнения: {term}."),
        ("p", f"3.2. Договор действует до {fakes.written_date(rng, fakes.random_date(rng))}."),
        ("h", "4. Реквизиты сторон"),
        ("p", f"{roles[0]}: {supplier.written(rng)}, ИНН {supplier.inn}, КПП {supplier.kpp}"),
        ("p", f"{roles[1]}: {buyer.written(rng)}, ИНН {buyer.inn}, КПП {buyer.kpp}"),
    ]
    return SyntheticDocument(
        "contract",
        tuple(blocks),
        {
            "number": number,
            "date": day.isoformat(),
            "city": city,
            "supplier": supplier.normalized,
            "supplier_inn": supplier.inn,
            "director": director.full,
            "amount": fakes.normalized_amount(amount),
            "term": term,
        },
    )


def resume(rng: random.Random) -> SyntheticDocument:
    who = fakes.person(rng)
    birth = fakes.random_date(rng, start=date(1970, 1, 1), days=12_000)
    phone_written, phone_normalized = fakes.phone(rng)
    email = fakes.email(rng, who)
    city, position = rng.choice(fakes.CITIES), rng.choice(fakes.POSITIONS)
    salary = fakes.random_amount(rng, 40_000, 300_000) // 100_000 * 100_000 if rng.random() < 0.6 else None
    contacts = [
        ("p", f"Телефон: {phone_written}"),
        ("p", f"E-mail: {email}"),
        ("p", f"Город проживания: {city}"),
    ]
    rng.shuffle(contacts)
    blocks: list[Block] = [
        ("h", who.full),
        ("p", f"Желаемая должность: {position}"),
        ("p", f"Дата рождения: {fakes.written_date(rng, birth)}"),
        *contacts,
    ]
    if salary is not None:
        blocks.append(("p", f"Желаемая зарплата: {fakes.written_amount(rng, salary)}"))
    blocks.append(("h", "Опыт работы"))
    for _ in range(rng.randint(1, 3)):
        start = fakes.random_date(rng, start=date(2010, 1, 1), days=4000)
        blocks.append(
            ("p", f"{start.strftime('%m.%Y')} — настоящее время: {fakes.organization(rng).written(rng)}, "
                  f"{rng.choice(fakes.POSITIONS).lower()}")
        )
    blocks += [("h", "Образование"), ("p", f"Высшее, {rng.choice(fakes.UNIVERSITIES)}")]
    return SyntheticDocument(
        "resume",
        tuple(blocks),
        {
            "full_name": who.full,
            "position": position,
            "birth_date": birth.isoformat(),
            "phone": phone_normalized,
            "email": email,
            "city": city,
            "salary": fakes.normalized_amount(salary) if salary is not None else None,
        },
    )


BUILDERS = {"invoice": invoice, "act": act, "contract": contract, "resume": resume}
