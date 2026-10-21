<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $template_id
 * @property string $file_name
 * @property string $file_format
 * @property int $size_bytes
 * @property string|null $sha256
 * @property string $storage_key
 * @property bool|null $has_text_layer
 * @property DocumentStatus $status
 * @property Carbon $uploaded_at
 */
// владелец, статус и место хранения задаёт код загрузки и обработки, а не данные формы
#[Fillable(['template_id', 'file_name', 'file_format', 'size_bytes', 'sha256', 'has_text_layer'])]
class Document extends Model
{
    protected $attributes = ['status' => 'uploaded'];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'has_text_layer' => 'boolean',
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return HasMany<ExtractionResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(ExtractionResult::class);
    }

    /** @return HasOne<ExtractionResult, $this> */
    public function latestResult(): HasOne
    {
        return $this->hasOne(ExtractionResult::class)->latestOfMany();
    }
}
