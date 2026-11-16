"""Определение типа документа: какой из шаблонов пользователя подходит к тексту (по подписям и словам-якорям)."""
import json

from app.classify import classify
from app.schemas import ClassifyCandidate
from tests.conftest import make_docx

INVOICE = ClassifyCandidate(
    id=1, name="Счёт на оплату", phrases=("счёт", "поставщик*", "покупатель", "итого к оплате", "ИНН", "КПП", "БИК")
)
ACT = ClassifyCandidate(
    id=2, name="Акт выполненных работ", phrases=("акт", "исполнитель", "заказчик", "работы выполнены", "претензий")
)
RESUME = ClassifyCandidate(id=3, name="Резюме", phrases=("опыт работы", "образование", "навыки", "желаемая должность"))

INVOICE_TEXT = (
    "Счёт на оплату № 15 от 01.03.2025\nПоставщик: ООО «Ромашка», ИНН 7707083893, КПП 770701001\n"
    "Покупатель: АО «Лотос»\nБИК 044525225\nИтого к оплате: 15 000,00"
)
ACT_TEXT = (
    "Акт № 7 от 05.03.2025\nИсполнитель: ООО «Ромашка»\nЗаказчик: АО «Лотос»\n"
    "Работы выполнены полностью и в срок. Заказчик претензий не имеет."
)


def test_invoice_text_is_classified_as_invoice():
    result = classify(INVOICE_TEXT, (INVOICE, ACT, RESUME))

    assert result.template_id == 1
    assert result.confidence > 0.5
    assert [s.id for s in result.scores][0] == 1


def test_act_text_is_classified_as_act():
    assert classify(ACT_TEXT, (INVOICE, ACT, RESUME)).template_id == 2


def test_unrelated_text_gets_no_suggestion():
    result = classify("Протокол собрания жильцов дома", (INVOICE, ACT, RESUME))

    assert result.template_id is None


def test_ambiguous_text_gets_no_suggestion():
    # одинаково похоже на два шаблона — лучше не угадывать
    twin = ClassifyCandidate(id=4, name="Счёт поставщика", phrases=INVOICE.phrases)

    assert classify(INVOICE_TEXT, (INVOICE, twin)).template_id is None


def test_template_name_in_text_is_a_strong_signal():
    # у шаблона нет слов-якорей, но его название — в заголовке документа
    named = ClassifyCandidate(id=5, name="Доверенность", phrases=())
    text = "ДОВЕРЕННОСТЬ № 3\nНастоящей доверенностью уполномочиваю Иванова И. И."

    assert classify(text, (named, RESUME)).template_id == 5


def test_document_title_decides_between_overlapping_templates():
    # акт ссылается на договор и делит с ним слова «исполнитель», «заказчик» — решает заголовок «АКТ»
    contract = ClassifyCandidate(
        id=6, name="Договор", phrases=("договор*", "исполнитель", "заказчик", "предмет договора", "стороны")
    )
    text = (
        "АКТ № 12 сдачи-приёмки выполненных работ\nОснование: договор подряда № 5 от 01.02.2025\n"
        "Исполнитель: ООО «Ромашка»\nЗаказчик: АО «Лотос»\nРаботы выполнены в полном объёме, претензий нет."
    )

    assert classify(text, (contract, ACT)).template_id == 2


def test_empty_text_gets_no_suggestion():
    assert classify("", (INVOICE, ACT)).template_id is None


def templates_json(*candidates: ClassifyCandidate) -> str:
    return json.dumps([c.model_dump() for c in candidates], ensure_ascii=False)


def test_classify_endpoint_suggests_template(client):
    docx = make_docx(INVOICE_TEXT.split("\n"))

    response = client.post(
        "/classify",
        files={"file": ("doc.docx", docx, "application/octet-stream")},
        data={"templates": templates_json(INVOICE, ACT, RESUME)},
    )

    assert response.status_code == 200
    data = response.json()["data"]
    assert data["template_id"] == 1
    assert {s["id"] for s in data["scores"]} == {1, 2, 3}


def test_classify_endpoint_rejects_bad_templates(client):
    docx = make_docx(["Счёт"])

    for bad in ("не json", "[]", json.dumps([{"id": 0, "name": "x", "phrases": []}])):
        response = client.post("/classify", files={"file": ("d.docx", docx, "application/octet-stream")}, data={"templates": bad})
        assert response.status_code in (400, 422), bad


def test_classify_endpoint_requires_token_when_configured():
    from fastapi.testclient import TestClient

    from app.config import Settings
    from app.main import create_app

    with TestClient(create_app(Settings(ocr_enabled=False, ner_enabled=False, api_token="secret"))) as client:
        response = client.post(
            "/classify",
            files={"file": ("d.docx", make_docx(["Счёт"]), "application/octet-stream")},
            data={"templates": templates_json(INVOICE)},
        )

    assert response.status_code == 401
