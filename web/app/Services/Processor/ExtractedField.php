<?php

namespace App\Services\Processor;

/** Значение одного поля шаблона из ответа сервиса (processor/app/schemas.py: FieldResult). */
final readonly class ExtractedField
{
    /**
     * @param  list<string>  $alternatives
     * @param  list<string>  $issues
     */
    public function __construct(
        public string $name,
        public ?string $value,
        public ?string $source,
        public ?string $raw,
        public ?int $start,
        public ?int $end,
        public float $confidence,
        public bool $needsReview,
        public array $alternatives,
        public array $issues,
    ) {}
}
