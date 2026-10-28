<?php

namespace App\Support;

/**
 * Подписи кодов сервиса обработки: причины «требует проверки» у поля (FieldResult.issues)
 * и предупреждения о неполном результате (ExtractionResult.warnings) — processor/README.md.
 * Неизвестный код показывается как есть: сервис мог добавить новый.
 */
final class ReviewCodes
{
    private const ISSUES = [
        'REQUIRED_MISSING' => 'обязательное поле не найдено',
        'LOW_CONFIDENCE' => 'низкая уверенность',
        'NOT_CONFIRMED' => 'значение от LLM не найдено в тексте',
        'OUT_OF_RANGE' => 'вне допустимого диапазона',
        'TOO_LONG' => 'длиннее допустимого',
        'NOT_ALLOWED' => 'нет в списке допустимых значений',
        'INVALID_FORMAT' => 'не соответствует формату',
        'LOW_OCR_QUALITY' => 'скан распознан с низким качеством',
        'INCOMPLETE_OCR' => 'распознаны не все страницы скана',
    ];

    private const WARNINGS = [
        'LLM_UNAVAILABLE' => 'LLM была недоступна — пропущенные поля искали только правила и NER',
        'LLM_BUSY' => 'LLM была занята — пропущенные поля искали только правила и NER',
        'LLM_QUOTA_EXCEEDED' => 'исчерпан лимит запросов к LLM',
        'LLM_AUTH_FAILED' => 'ошибка доступа к LLM (ключ)',
        'LLM_REQUEST_REJECTED' => 'LLM отклонила запрос',
        'LLM_BAD_RESPONSE' => 'LLM вернула ответ не по формату',
        'LLM_REFUSED' => 'LLM отказалась отвечать',
        'LLM_ERROR' => 'ошибка при обращении к LLM',
        'LLM_FIELDS_TRUNCATED' => 'в LLM отправлены не все пропущенные поля',
        'LLM_SKIPPED_TIME_BUDGET' => 'LLM не вызывалась: не хватило времени на обработку',
        'LLM_SKIPPED_NOT_LOCAL' => 'LLM не вызывалась: сервис обработки настроен на облачную модель, а документ должен оставаться на сервере',
        'OCR_UNAVAILABLE' => 'OCR недоступен — страницы без текста не распознаны',
        'LOW_OCR_QUALITY' => 'скан распознан с низким качеством',
        'INCOMPLETE_OCR' => 'распознаны не все страницы скана',
    ];

    public static function issue(string $code): string
    {
        return self::ISSUES[$code] ?? $code;
    }

    public static function warning(string $code): string
    {
        return self::WARNINGS[$code] ?? $code;
    }
}
