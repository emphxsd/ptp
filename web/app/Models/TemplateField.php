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
}
