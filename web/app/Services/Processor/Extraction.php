<?php

namespace App\Services\Processor;

/**
 * Результат POST /extract (processor/app/schemas.py: ExtractionResult) после проверки формы ответа.
 * Ответ сервиса — внешние данные: всё, что не по формату, отклоняется (ProcessorUnavailableException).
 */
final readonly class Extraction
{
    private const SOURCES = ['rule', 'ner', 'llm'];

    // коды предупреждений и причин «требует проверки» — короткие машинные строки
    private const CODE = '/^[A-Z][A-Z0-9_]{0,49}$/D';

    private const MAX_ALTERNATIVES = 10;

    // пределы длины внешних данных: текст — как PROCESSOR_MAX_TEXT_CHARS, значение поля — с запасом
    private const MAX_TEXT_CHARS = 2_000_000;

    private const MAX_VALUE_CHARS = 10_000;

    /**
     * @param  list<ExtractedField>  $fields
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $fields,
        public bool $llmUsed,
        public ?int $processingMs,
        public ?string $text,
        public array $warnings,
        public bool $needsReview,
        public ?int $pages,
        public ?bool $hasTextLayer,
        public int $ocrPages,
        public ?float $ocrConfidence,
    ) {}

    /** @throws ProcessorUnavailableException */
    public static function fromResponse(mixed $data): self
    {
        if (! is_array($data) || ! is_array($data['fields'] ?? null) || ! array_is_list($data['fields'])
            || ! is_array($data['document'] ?? null)) {
            throw new ProcessorUnavailableException('Сервис обработки вернул некорректный ответ.');
        }
        $document = $data['document'];

        return new self(
            fields: array_map(self::field(...), $data['fields']),
            llmUsed: ($data['llm_used'] ?? false) === true,
            processingMs: self::nonNegativeInt($data['processing_ms'] ?? null),
            text: is_string($data['text'] ?? null) ? mb_substr($data['text'], 0, self::MAX_TEXT_CHARS) : null,
            warnings: self::codes($data['warnings'] ?? []),
            needsReview: ($data['needs_review'] ?? false) === true,
            pages: self::nonNegativeInt($document['pages'] ?? null),
            hasTextLayer: is_bool($document['has_text_layer'] ?? null) ? $document['has_text_layer'] : null,
            ocrPages: self::nonNegativeInt($document['ocr_pages'] ?? null) ?? 0,
            ocrConfidence: is_numeric($document['ocr_confidence'] ?? null) ? (float) $document['ocr_confidence'] : null,
        );
    }

    private static function field(mixed $field): ExtractedField
    {
        if (! is_array($field) || ! is_string($field['name'] ?? null)) {
            throw new ProcessorUnavailableException('Сервис обработки вернул некорректный ответ.');
        }
        $value = $field['value'] ?? null;
        $start = self::nonNegativeInt($field['start'] ?? null);
        $end = self::nonNegativeInt($field['end'] ?? null);
        $valid = $start !== null && $end !== null && $start <= $end;
        $alternatives = is_array($field['alternatives'] ?? null) ? $field['alternatives'] : [];

        return new ExtractedField(
            name: $field['name'],
            value: is_scalar($value) ? mb_substr((string) $value, 0, self::MAX_VALUE_CHARS) : null,
            source: in_array($field['source'] ?? null, self::SOURCES, true) ? $field['source'] : null,
            raw: is_string($field['raw'] ?? null) ? mb_substr($field['raw'], 0, self::MAX_VALUE_CHARS) : null,
            start: $valid ? $start : null,
            end: $valid ? $end : null,
            confidence: is_numeric($field['confidence'] ?? null) ? max(0.0, min(1.0, (float) $field['confidence'])) : 0.0,
            needsReview: ($field['needs_review'] ?? false) === true,
            alternatives: array_map(
                fn (string $alternative) => mb_substr($alternative, 0, self::MAX_VALUE_CHARS),
                array_slice(array_values(array_filter($alternatives, is_string(...))), 0, self::MAX_ALTERNATIVES),
            ),
            issues: self::codes($field['issues'] ?? []),
        );
    }

    /** @return list<string> */
    private static function codes(mixed $codes): array
    {
        if (! is_array($codes)) {
            return [];
        }

        return array_values(array_filter($codes, fn (mixed $code) => is_string($code) && preg_match(self::CODE, $code)));
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
