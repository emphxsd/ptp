<?php

namespace App\Models;

use App\Enums\DataType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $template_id
 * @property string $name
 * @property string $label
 * @property DataType $data_type
 * @property bool $is_required
 * @property list<string>|null $keywords
 * @property string|null $regex_pattern
 * @property string|null $ner_label
 * @property string|null $llm_hint
 * @property array<string, mixed>|null $validation
 * @property int $sort_order
 */
#[Fillable([
    'template_id', 'name', 'label', 'data_type', 'is_required', 'keywords', 'regex_pattern', 'ner_label',
    'llm_hint', 'validation', 'sort_order',
])]
class TemplateField extends Model
{
    public const NER_LABELS = ['PER', 'ORG', 'LOC'];

    // порядок ключей FieldValidation в сервисе; jsonb в PostgreSQL хранит ключи в своём порядке
    private const VALIDATION_KEYS = ['min_value', 'max_value', 'min_date', 'max_date', 'max_length', 'allowed_values', 'pattern'];

    protected function casts(): array
    {
        return [
            'data_type' => DataType::class,
            'is_required' => 'boolean',
            'keywords' => 'array',
            'validation' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Поле в формате сервиса обработки: пустые и значения по умолчанию не передаются.
     *
     * @return array<string, mixed>
     */
    public function toProcessorPayload(): array
    {
        return array_filter([
            'name' => $this->name,
            'label' => $this->label,
            'data_type' => $this->data_type->value,
            'is_required' => $this->is_required ?: null,
            'keywords' => $this->keywords ?: null,
            'regex_pattern' => $this->regex_pattern,
            'ner_label' => $this->ner_label,
            'llm_hint' => $this->llm_hint,
            'validation' => $this->validation ? $this->orderedValidation($this->validation) : null,
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    private function orderedValidation(array $validation): array
    {
        $known = array_intersect_key(array_flip(self::VALIDATION_KEYS), $validation);

        return array_replace($known, $validation);
    }
}
