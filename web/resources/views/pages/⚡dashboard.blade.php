<?php

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Template;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Главная')] class extends Component {
    private const RECENT = 5;

    /** Сводка по своим документам и шаблонам. */
    #[Computed]
    public function stats(): array
    {
        // одним запросом — число документов по статусам
        $byStatus = Auth::user()->documents()->toBase()
            ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $count = fn (DocumentStatus $status): int => (int) ($byStatus[$status->value] ?? 0);

        return [
            'done' => $count(DocumentStatus::Done),
            'review' => Auth::user()->documents()->where('status', DocumentStatus::Done)
                ->whereHas('latestResult', fn ($q) => $q->where('needs_review', true))->count(),
            'active' => $count(DocumentStatus::Uploaded) + $count(DocumentStatus::Processing),
            'failed' => $count(DocumentStatus::Failed),
            'templates' => Template::visibleTo(Auth::user())->count(),
        ];
    }

    /** @return Collection<int, Document> */
    #[Computed]
    public function recent(): Collection
    {
        return Auth::user()->documents()
            ->with(['template:id,name', 'latestResult:id,extraction_results.document_id,needs_review'])
            ->orderByDesc('uploaded_at')->orderByDesc('id')
            ->limit(self::RECENT)
            ->get();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">Главная</flux:heading>
        <flux:text class="mt-1">Здравствуйте, {{ auth()->user()->name }}.</flux:text>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('documents.index', ['status' => 'done']) }}" wire:navigate class="rounded-xl border border-neutral-200 p-4 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-zinc-900">
            <flux:text>Документов обработано</flux:text>
            <div class="mt-1 text-2xl font-semibold">{{ $this->stats['done'] }}</div>
        </a>
        <a href="{{ route('documents.index', ['review' => 1]) }}" wire:navigate class="rounded-xl border border-neutral-200 p-4 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-zinc-900">
            <flux:text>Требуют проверки</flux:text>
            <div @class(['mt-1 text-2xl font-semibold', 'text-amber-600 dark:text-amber-400' => $this->stats['review'] > 0])>{{ $this->stats['review'] }}</div>
        </a>
        <a href="{{ route('documents.index') }}" wire:navigate class="rounded-xl border border-neutral-200 p-4 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-zinc-900">
            <flux:text>В очереди и с ошибкой</flux:text>
            <div class="mt-1 text-2xl font-semibold">{{ $this->stats['active'] }} / {{ $this->stats['failed'] }}</div>
        </a>
        <a href="{{ route('templates.index') }}" wire:navigate class="rounded-xl border border-neutral-200 p-4 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-zinc-900">
            <flux:text>Шаблонов доступно</flux:text>
            <div class="mt-1 text-2xl font-semibold">{{ $this->stats['templates'] }}</div>
        </a>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:button variant="primary" icon="arrow-up-tray" :href="route('documents.index')" wire:navigate>Загрузить документы</flux:button>
        <flux:button icon="plus" :href="route('templates.create')" wire:navigate>Новый шаблон</flux:button>
    </div>

    <div class="flex flex-col gap-3">
        <flux:heading size="lg">Последние документы</flux:heading>
        @forelse ($this->recent as $document)
            <a wire:key="recent-{{ $document->id }}" href="{{ route('documents.show', $document) }}" wire:navigate
                class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-neutral-200 px-4 py-3 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-zinc-900">
                <span class="min-w-0 break-all font-medium">{{ $document->file_name }}</span>
                <span class="flex items-center gap-2 text-sm text-zinc-500">
                    {{ $document->template?->name ?? 'шаблон удалён' }} ·
                    {{ $document->uploaded_at->timezone(config('app.display_timezone'))->format('d.m.Y H:i') }}
                    <flux:badge size="sm" :color="$document->status === DocumentStatus::Failed ? 'red' : ($document->status === DocumentStatus::Done ? 'green' : 'zinc')">{{ $document->status->label() }}</flux:badge>
                    @if ($document->status === DocumentStatus::Done && $document->latestResult?->needs_review)
                        <flux:badge size="sm" color="amber">требует проверки</flux:badge>
                    @endif
                </span>
            </a>
        @empty
            <flux:text>Документов пока нет — загрузите первый.</flux:text>
        @endforelse
    </div>
</div>
