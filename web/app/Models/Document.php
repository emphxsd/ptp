<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
 * @property bool $use_llm
 * @property int|null $pages
 * @property DocumentStatus $status
 * @property CarbonInterface $uploaded_at
 */
// владелец, статус и место хранения задаёт код загрузки и обработки, а не данные формы
#[Fillable(['template_id', 'file_name', 'file_format', 'size_bytes', 'sha256', 'has_text_layer'])]
class Document extends Model
{
    // не MassPrunable: при удалении по сроку нужны события модели — они удаляют файлы
    use Prunable;

    // диск с загруженными файлами (config/filesystems.php)
    public const DISK = 'documents';

    // фрагменты документа уходят во внешнюю LLM только с явного согласия пользователя
    protected $attributes = ['status' => 'uploaded', 'use_llm' => false];

    protected static function booted(): void
    {
        // значение по умолчанию в БД модель увидела бы только после перечитывания записи
        static::creating(function (self $document): void {
            $document->uploaded_at ??= now();
        });

        // файл удаляется вместе с записью — после фиксации транзакции, чтобы откат не оставил запись без файла;
        // при удалении пользователя — вся его папка (User::booted)
        static::deleted(function (self $document): void {
            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($document->storage_key));
        });
    }

    /**
     * Документы с персональными данными не хранятся бессрочно: model:prune по расписанию удаляет старые.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('uploaded_at', '<', now()->subDays(config('documents.retention_days')));
    }

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'has_text_layer' => 'boolean',
            'use_llm' => 'boolean',
            'pages' => 'integer',
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
