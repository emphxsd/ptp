"""Шаблоны извлечения для четырёх типов документов — так, как их настроил бы пользователь в конструкторе:
слова-якоря с синонимами меток, типы полей, подсказки для LLM. Генератор сохраняет их рядом с выборкой."""
from app.schemas import Template

TEMPLATES: dict[str, dict] = {
    "invoice": {
        "name": "Счёт на оплату",
        "fields": [
            {"name": "number", "label": "Номер счёта", "data_type": "number", "is_required": True,
             "keywords": ["Счёт на оплату", "Счет на оплату"]},
            {"name": "date", "label": "Дата счёта", "data_type": "date", "is_required": True,
             "keywords": ["Счёт на оплату", "Счет на оплату"]},
            {"name": "supplier", "label": "Поставщик", "data_type": "organization", "is_required": True,
             "keywords": ["Поставщик", "Исполнитель", "Продавец"]},
            {"name": "supplier_inn", "label": "ИНН поставщика", "data_type": "inn", "is_required": True,
             "keywords": ["Поставщик", "Исполнитель", "Продавец"]},
            {"name": "buyer", "label": "Покупатель", "data_type": "organization",
             "keywords": ["Покупатель", "Заказчик", "Плательщик"]},
            {"name": "bik", "label": "БИК банка", "data_type": "bik", "keywords": ["БИК"]},
            {"name": "total", "label": "Сумма к оплате", "data_type": "amount", "is_required": True,
             "keywords": ["Итого к оплате", "Всего к оплате", "Итого"]},
            {"name": "vat", "label": "Сумма НДС", "data_type": "amount", "keywords": ["НДС"],
             "llm_hint": "Если указано «Без НДС», значения нет"},
        ],
    },
    "act": {
        "name": "Акт выполненных работ",
        "fields": [
            {"name": "number", "label": "Номер акта", "data_type": "number", "is_required": True, "keywords": ["Акт"]},
            {"name": "date", "label": "Дата акта", "data_type": "date", "is_required": True,
             "keywords": ["Акт", "Дата составления"],
             "llm_hint": "Дата самого акта, а не договора-основания"},
            {"name": "contractor", "label": "Исполнитель", "data_type": "organization", "is_required": True,
             "keywords": ["Исполнитель"]},
            {"name": "contractor_inn", "label": "ИНН исполнителя", "data_type": "inn", "keywords": ["Исполнитель"]},
            {"name": "customer", "label": "Заказчик", "data_type": "organization", "keywords": ["Заказчик"]},
            {"name": "total", "label": "Стоимость работ", "data_type": "amount", "is_required": True,
             "keywords": ["Итого", "Всего оказано услуг на сумму", "Общая стоимость работ"]},
            {"name": "signer", "label": "Подписант от исполнителя", "data_type": "person",
             "keywords": ["От исполнителя"]},
        ],
    },
    "contract": {
        "name": "Договор",
        "fields": [
            {"name": "number", "label": "Номер договора", "data_type": "number", "is_required": True,
             "keywords": ["Договор"]},
            {"name": "date", "label": "Дата договора", "data_type": "date", "is_required": True, "keywords": ["г."],
             "llm_hint": "Дата заключения из шапки договора"},
            {"name": "city", "label": "Город заключения", "data_type": "string", "keywords": ["г."],
             "llm_hint": "Только название города"},
            {"name": "supplier", "label": "Поставщик (исполнитель)", "data_type": "organization", "is_required": True,
             "keywords": ["Поставщик:", "Исполнитель:"]},
            {"name": "supplier_inn", "label": "ИНН поставщика", "data_type": "inn",
             "keywords": ["Поставщик:", "Исполнитель:"]},
            {"name": "director", "label": "Руководитель поставщика", "data_type": "person",
             "keywords": ["в лице генерального директора"]},
            {"name": "amount", "label": "Цена договора", "data_type": "amount", "is_required": True,
             "keywords": ["Цена договора"]},
            {"name": "term", "label": "Срок исполнения", "data_type": "string", "keywords": ["Срок исполнения"]},
        ],
    },
    "resume": {
        "name": "Резюме",
        "fields": [
            {"name": "full_name", "label": "ФИО кандидата", "data_type": "person", "is_required": True,
             "ner_label": "PER", "llm_hint": "ФИО владельца резюме из заголовка"},
            {"name": "position", "label": "Желаемая должность", "data_type": "string",
             "keywords": ["Желаемая должность"]},
            {"name": "birth_date", "label": "Дата рождения", "data_type": "date", "keywords": ["Дата рождения"]},
            {"name": "phone", "label": "Телефон", "data_type": "phone", "keywords": ["Телефон"]},
            {"name": "email", "label": "E-mail", "data_type": "email", "keywords": ["E-mail"]},
            {"name": "city", "label": "Город", "data_type": "string", "keywords": ["Город проживания"]},
            {"name": "salary", "label": "Желаемая зарплата", "data_type": "amount", "keywords": ["Желаемая зарплата"]},
        ],
    },
}


def template_for(doc_type: str) -> Template:
    return Template.model_validate(TEMPLATES[doc_type])
