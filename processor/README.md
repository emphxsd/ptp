# Сервис обработки документов (processor)

Python-сервис на FastAPI: принимает документ и шаблон полей, извлекает текст и (на следующих шагах) значения полей гибридным методом «правила + NER → LLM». Вызывается веб-приложением на Laravel.

## Запуск (Windows, PowerShell)

```powershell
cd processor
python -m venv .venv
.venv\Scripts\python -m pip install -r requirements-dev.txt
.venv\Scripts\python -m uvicorn app.main:app --reload --port 8000
```

Интерактивная документация API: http://127.0.0.1:8000/docs

## Тесты

```powershell
.venv\Scripts\python -m pytest --cov
```

Тестовые документы генерируются в `tests/conftest.py`, реальные документы в репозиторий не кладутся.

## API

Все ответы в едином формате: `{"success": true, "data": {...}, "error": null}` или `{"success": false, "data": null, "error": {"code": "...", "message": "..."}}`.

### `POST /extract` (multipart/form-data)

| Поле | Тип | Описание |
|---|---|---|
| `file` | файл | PDF, DOCX, PNG или JPG (формат определяется по содержимому) |
| `template` | строка JSON | шаблон полей, см. ниже |
| `include_text` | bool | вернуть извлечённый текст (по умолчанию `false`) |

Шаблон:

```json
{
  "name": "Счёт на оплату",
  "fields": [
    {"name": "inn", "label": "ИНН поставщика", "data_type": "inn", "is_required": true, "keywords": ["ИНН"]},
    {"name": "total", "label": "Сумма", "data_type": "amount", "keywords": ["Итого"]}
  ]
}
```

`data_type`: `string`, `date`, `amount`, `number`, `inn`, `kpp`, `bik`, `phone`, `email`, `person`, `organization`.
Необязательные поля: `regex_pattern`, `ner_label`, `llm_hint`.

Ответ `data`: `document` (формат, страницы, страницы без текста, наличие текстового слоя, число символов), `fields` (для каждого поля — `value`, `source` = `rule` | `ner` | `llm`, `confidence`, `needs_review`), `llm_used`, `processing_ms`, `text`.

### Коды ошибок

| Код | HTTP | Когда |
|---|---|---|
| `EMPTY_FILE` | 400 | пустой файл |
| `UNAUTHORIZED` | 401 | задан `PROCESSOR_API_TOKEN`, а токен не передан или неверный |
| `NOT_FOUND`, `METHOD_NOT_ALLOWED` | 404, 405 | неизвестный адрес или метод |
| `FILE_TOO_LARGE` | 413 | файл больше `PROCESSOR_MAX_FILE_MB` (проверяется до разбора запроса) |
| `UNSUPPORTED_FORMAT` | 415 | не PDF/DOCX/PNG/JPG |
| `DOCUMENT_READ_ERROR` | 422 | файл повреждён |
| `PASSWORD_PROTECTED` | 422 | PDF защищён паролем |
| `UNSAFE_DOCUMENT` | 422 | DOCX похож на zip-бомбу или слишком большие таблицы |
| `TOO_MANY_PAGES` | 422 | страниц больше `PROCESSOR_MAX_PAGES` |
| `TEXT_TOO_LARGE` | 422 | текста больше `PROCESSOR_MAX_TEXT_CHARS` |
| `NO_TEXT_LAYER` | 422 | скан или изображение без текстового слоя (OCR ещё не подключён) |
| `EMPTY_DOCUMENT` | 422 | в DOCX нет текста |
| `INVALID_TEMPLATE` | 422 | некорректный шаблон |
| `VALIDATION_ERROR` | 422 | не хватает полей запроса |
| `INTERNAL_ERROR` | 500 | непредвиденная ошибка (подробности только в логе, без данных документа) |

## Безопасность

- Формат определяется по содержимому, расширение имени файла игнорируется; полиглоты (PNG/ZIP с маркером `%PDF-`) не принимаются за PDF.
- Размер тела запроса ограничивается middleware до разбора multipart (в том числе при chunked-передаче).
- DOCX проверяется на zip-бомбу (число файлов, объём после распаковки, коэффициент сжатия), таблицы разбираются по XML с лимитом строк.
- PDF: лимиты страниц и объёма текста; доступ к PyMuPDF сериализован (библиотека не потокобезопасна).
- Межсервисная аутентификация: `Authorization: Bearer <PROCESSOR_API_TOKEN>`, сравнение через `hmac.compare_digest`.
- Ответы `/extract` с `Cache-Control: no-store`; в логи пишутся только коды ошибок и тип исключения — без текста документов.
- Зависимости проверяются: `.venv\Scripts\python -m pip_audit -r requirements.txt`.

Запланировано: защита от ReDoS для пользовательских `regex_pattern` (RE2) — на шаге правил; изоляция разбора в отдельных процессах с таймаутом и лимитами памяти, internal-сеть Docker — на шаге контейнеризации.

## Статус

- [x] Шаг 1. Каркас, определение формата, извлечение текста PDF/DOCX, нормализация
- [ ] Шаг 2. Правила (regex) по типам полей и ключевым словам
- [ ] Шаг 3. NER (Natasha): ФИО, организации
- [ ] Шаг 4. LLM для ненайденных полей (GigaChat / Ollama)
- [ ] Шаг 5. Валидация и «требует проверки»
- [ ] Шаг 6. OCR сканов (Tesseract)
- [ ] Шаг 7. Оценка качества: precision / recall / F1, время
