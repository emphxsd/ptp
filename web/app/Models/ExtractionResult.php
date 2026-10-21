<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $document_id
 * @property string $status
 * @property bool $llm_used
 * @property int|null $processing_ms
 * @property string|null $error_code
 * @property string|null $error_message
 * @property bool $needs_review
 * @property list<string>|null $warnings
 * @property int $ocr_pages
 * @property float|null $ocr_confidence
 * @property string|null $text
 */
#[Fillable([
    'document_id', 'status', 'llm_used', 'processing_ms', 'error_code', 'error_message', 'needs_review', 'warnings',
    'ocr_pages', 'ocr_confidence', 'text',
])]
class ExtractionResult extends Model
{
    protected function casts(): array
    {
        return [
            'llm_used' => 'boolean',
            'needs_review' => 'boolean',
            'warnings' => 'array',
            'processing_ms' => 'integer',
            'ocr_pages' => 'integer',
            'ocr_confidence' => 'float',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasMany<ExtractedValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ExtractedValue::class, 'result_id');
    }

    /** @return HasMany<Export, $this> */
    public function exports(): HasMany
    {
        return $this->hasMany(Export::class, 'result_id');
    }
}
