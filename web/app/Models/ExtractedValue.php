<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Значение поля: найденное сервисом и, если пользователь исправил, — исправленное (original_value хранит исходное).
 *
 * @property int $id
 * @property int $result_id
 * @property int|null $field_id
 * @property string $field_name
 * @property string|null $value
 * @property string|null $original_value
 * @property string|null $source
 * @property float $confidence
 * @property string|null $raw
 * @property int|null $start_offset
 * @property int|null $end_offset
 * @property bool $needs_review
 * @property bool $is_corrected
 * @property list<string>|null $issues
 * @property list<string>|null $alternatives
 */
#[Fillable([
    'result_id', 'field_id', 'field_name', 'value', 'original_value', 'source', 'confidence', 'raw', 'start_offset',
    'end_offset', 'needs_review', 'is_corrected', 'issues', 'alternatives',
])]
class ExtractedValue extends Model
{
    protected static function booted(): void
    {
        // имя поля сохраняется в самом значении: результат не зависит от последующих правок шаблона
        static::creating(function (self $value): void {
            if (blank($value->field_name) && $value->field_id !== null) {
                $value->field_name = TemplateField::whereKey($value->field_id)->valueOrFail('name');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'needs_review' => 'boolean',
            'is_corrected' => 'boolean',
            'issues' => 'array',
            'alternatives' => 'array',
            'start_offset' => 'integer',
            'end_offset' => 'integer',
        ];
    }

    /** @return BelongsTo<ExtractionResult, $this> */
    public function result(): BelongsTo
    {
        return $this->belongsTo(ExtractionResult::class, 'result_id');
    }

    /** @return BelongsTo<TemplateField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(TemplateField::class, 'field_id');
    }
}
