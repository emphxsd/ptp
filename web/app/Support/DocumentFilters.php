<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Template;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Фильтры истории документов: одни и те же для списка на странице и для экспорта выборки.
 * Значения приходят из адресной строки — неподходящие (чужой шаблон, неизвестный статус, не дата) игнорируются.
 */
final readonly class DocumentFilters
{
    public const MAX_SEARCH_LENGTH = 100;

    public function __construct(
        public string $search = '',
        public ?int $template = null,
        public ?DocumentStatus $status = null,
        public bool $review = false,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}

    /**
     * Значения из адресной строки могут быть чем угодно (в том числе массивами ?search[]=…): берутся только строки и числа.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(User $user, array $input): self
    {
        $scalar = fn (string $key): string => is_scalar($input[$key] ?? null) ? (string) $input[$key] : '';

        $template = ctype_digit($scalar('template')) ? (int) $scalar('template') : null;
        if ($template !== null && ! Template::visibleTo($user)->whereKey($template)->exists()) {
            $template = null;
        }
        [$from, $to] = InputDate::period($input['from'] ?? null, $input['to'] ?? null);

        return new self(
            search: mb_substr(trim($scalar('search')), 0, self::MAX_SEARCH_LENGTH),
            template: $template,
            status: DocumentStatus::tryFrom($scalar('status')),
            review: in_array($scalar('review'), ['1', 'true'], true),
            from: $from,
            to: $to,
        );
    }

    public function isEmpty(): bool
    {
        return $this->toQuery() === [];
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->search !== '', fn (Builder $q) => Search::contains($q, 'file_name', $this->search))
            ->when($this->template !== null, fn (Builder $q) => $q->where('template_id', $this->template))
            ->when($this->status !== null, fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->review, fn (Builder $q) => $q->whereHas('latestResult', fn (Builder $r) => $r->where('needs_review', true)))
            ->when($this->from !== null, fn (Builder $q) => $q->where('uploaded_at', '>=', $this->from?->utc()))
            ->when($this->to !== null, fn (Builder $q) => $q->where('uploaded_at', '<=', $this->to?->utc()));
    }

    /**
     * Параметры для ссылки (экспорт выборки с теми же фильтрами).
     *
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            'search' => $this->search,
            'template' => $this->template,
            'status' => $this->status?->value,
            'review' => $this->review ? 1 : null,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
        ], fn (mixed $value) => $value !== null && $value !== '');
    }
}
