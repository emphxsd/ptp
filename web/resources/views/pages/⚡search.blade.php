<?php

use App\Enums\DataType;
use App\Enums\DocumentStatus;
use App\Models\ExtractedValue;
use App\Models\Template;
use App\Support\ArchiveQuery;
use App\Support\ArchiveSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Поиск')] class extends Component {
    use WithPagination;

    private const PER_PAGE = 20;

    private const MAX_QUERY = 300;

    // поля, которые показываются в найденном документе
    private const SHOWN_TYPES = [DataType::Number, DataType::Date, DataType::Organization, DataType::Amount];

    #[Url(except: '')]
    public string $q = '';

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function query(): ArchiveQuery
    {
        return ArchiveQuery::parse(mb_substr($this->q, 0, self::MAX_QUERY));
    }

    #[Computed]
    public function results(): ?LengthAwarePaginator
    {
        if ($this->query->isEmpty()) {
            return null;
        }
        $documents = Auth::user()->documents()->getQuery()->where('documents.status', DocumentStatus::Done);

        return ArchiveSearch::apply($documents, $this->query, Template::visibleTo(Auth::user())->get(['id', 'name']))
            ->with(['template:id,name', 'latestResult.values.field:id,label,data_type'])
            ->orderByDesc('uploaded_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    /** Значение в привычном виде: 15 000,00 ₽, 01.03.2025. */
    public function shown(ExtractedValue $value): ?string
    {
        $type = $value->field?->data_type;
        if ($value->value === null || ! in_array($type, self::SHOWN_TYPES, true)) {
            return null;
        }

        return ($value->field->label ?? $value->field_name).': '.match ($type) {
            DataType::Amount => number_format((float) $value->value, 2, ',', ' ').' ₽',
            DataType::Date => implode('.', array_reverse(explode('-', $value->value))),
            default => $value->value,
        };
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">Поиск по архиву</flux:heading>
        <flux:text class="mt-1">Ищет по значениям, извлечённым из ваших документов. Пишите обычными словами.</flux:text>
    </div>

    <div class="max-w-3xl">
        <flux:input wire:model.live.debounce.400ms="q" icon="magnifying-glass" maxlength="300"
            placeholder="Например: все договоры с ООО Ромашка за 2025 год дороже 100 тыс." />
        @if ($this->results !== null)
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($this->query->describe() as $chip)
                    <flux:badge size="sm" color="sky">{{ $chip }}</flux:badge>
                @endforeach
            </div>
        @else
            <flux:text class="mt-3">
                Понимает тип документа (счета, акты, договоры, резюме), организацию («ООО Ромашка»), ИНН, период («за 2025 год»,
                «с 01.03.2025 по 31.03.2025»), сумму («дороже 100 тыс.», «до 1 млн») и любые слова из значений полей.
            </flux:text>
        @endif
    </div>

    @if ($this->results !== null)
        <div class="flex flex-col gap-2">
            <flux:text>Найдено: {{ $this->results->total() }}</flux:text>
            @forelse ($this->results as $document)
                <a wire:key="found-{{ $document->id }}" href="{{ route('documents.show', $document) }}" wire:navigate
                    class="flex flex-col gap-1 rounded-xl border border-neutral-200 px-4 py-3 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-zinc-900">
                    <span class="flex flex-wrap items-center justify-between gap-2">
                        <span class="break-all font-medium">{{ $document->file_name }}</span>
                        <span class="text-sm text-zinc-500">
                            {{ $document->template?->name ?? 'шаблон удалён' }} ·
                            {{ $document->uploaded_at->timezone(config('app.display_timezone'))->format('d.m.Y') }}
                        </span>
                    </span>
                    <span class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ collect($document->latestResult?->values ?? [])->map(fn ($value) => $this->shown($value))->filter()->implode(' · ') }}
                    </span>
                </a>
            @empty
                <flux:text>Ничего не найдено. Попробуйте убрать часть условий.</flux:text>
            @endforelse
            {{ $this->results->links() }}
        </div>
    @endif
</div>
