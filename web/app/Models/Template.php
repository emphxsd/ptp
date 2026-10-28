<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $document_type_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 */
// владелец и признак системного шаблона задаются явно (через связь или forceFill), а не из формы
#[Fillable(['document_type_id', 'name', 'description'])]
class Template extends Model
{
    // те же ограничения, что в схеме шаблона сервиса обработки (processor/app/schemas.py)
    public const MAX_FIELDS = 100;

    public const MAX_REGEX_FIELDS = 20;

    public const MAX_PER_USER = 100;

    // все поля всех шаблонов пользователя: ограничивает объём, который один пользователь может записать в БД
    public const MAX_FIELDS_PER_USER = 2000;

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'is_system' => 'boolean'];
    }

    /** Сервис обработки принимает шаблон только с полями (Template.fields: min_length=1). */
    public function isUsable(): bool
    {
        return $this->fields()->exists();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DocumentType, $this> */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /** @return HasMany<TemplateField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Системные шаблоны и собственные шаблоны пользователя (чужие личные шаблоны не видны никому, включая администратора).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('is_system', true)->orWhere('user_id', $user->id));
    }

    /**
     * Шаблон в формате сервиса обработки (поле template запроса /process).
     *
     * @return array{name: string, fields: list<array<string, mixed>>}
     */
    public function toProcessorPayload(): array
    {
        return [
            'name' => $this->name,
            'fields' => array_values($this->fields->map(fn (TemplateField $field) => $field->toProcessorPayload())->all()),
        ];
    }

    /** Личная копия шаблона (например, системного) — её пользователь может менять под свои документы. */
    public function copyFor(User $user): self
    {
        return DB::transaction(function () use ($user): self {
            $copy = new self([
                'document_type_id' => $this->document_type_id,
                'name' => Str::limit($this->name.' (копия)', 150, ''),
                'description' => $this->description,
            ]);
            $copy->user()->associate($user)->save();

            foreach ($this->fields as $field) {
                $copy->fields()->create($field->only([
                    'name', 'label', 'data_type', 'is_required', 'keywords', 'regex_pattern', 'ner_label',
                    'llm_hint', 'validation', 'sort_order',
                ]));
            }

            return $copy;
        });
    }
}
