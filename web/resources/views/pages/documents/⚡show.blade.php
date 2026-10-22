<?php

use App\Enums\DataType;
use App\Enums\DocumentStatus;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\ExtractedValue;
use App\Models\ExtractionResult;
use App\Models\TemplateField;
use App\Support\ReviewCodes;
use App\Support\TextHighlighter;
use App\Support\ValueNormalizer;
use App\Support\ValueRules;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Результат обработки')] class extends Component {
    private const WRITES_PER_MINUTE = 120;

    public Document $document;

    // какое значение открыто в форме исправления; задаётся только на сервере (editValue)
    #[Locked]
    public ?int $editingId = null;

    public string $editedValue = '';

    public function mount(Document $document): void
    {
        // чужой документ — 404, как несуществующий (администратору тоже)
        abort_unless($document->user_id === Auth::id(), 404);
        $this->document = $document;
    }

    /** Последний результат обработки; правится только он. */
    #[Computed]
    public function result(): ?ExtractionResult
    {
        return $this->document->latestResult()->first();
    }

    /** @return Collection<int, ExtractedValue> */
    #[Computed]
    public function values(): Collection
    {
        return $this->result?->values()->orderBy('id')->get() ?? new Collection;
    }

    /** Поля шаблона по id — название, тип и правила; поле могли удалить из шаблона, тогда — системное имя. */
    #[Computed]
    public function fields(): Collection
    {
        return TemplateField::whereIn('id', $this->values->pluck('field_id')->filter())->get()->keyBy('id');
    }

    /** @return array{html: string, truncated: bool} */
    #[Computed]
    public function highlighted(): array
    {
        return TextHighlighter::render((string) $this->result?->text, $this->values, fn (ExtractedValue $value) => $this->label($value));
    }

    public function editValue(int $id): void
    {
        $value = $this->findValue($id);
        $this->editingId = $value->id;
        $this->editedValue = (string) $value->value;
        $this->resetErrorBag();
        Flux::modal('value-form')->show();
    }

    public function saveValue(): void
    {
        abort_if($this->editingId === null, 404);
        $value = $this->findValue($this->editingId);
        if (! $this->allowWrite()) {
            return;
        }
        $type = $this->dataType($value);

        if (trim($this->editedValue) === '') {
            $this->addError('editedValue', 'Введите значение. Если его нет в документе, нажмите «Значения нет».');

            return;
        }
        $normalized = ValueNormalizer::normalize($type, $this->editedValue);
        if ($normalized === null) {
            $this->addError('editedValue', 'Значение не подходит к типу поля «'.$type->label().'»: '.ValueNormalizer::hint($type).'.');

            return;
        }
        $violation = ValueRules::violation($this->fields->get($value->field_id)?->validation, $normalized);
        if ($violation !== null) {
            $this->addError('editedValue', 'Значение не проходит проверку поля: '.$violation.'.');

            return;
        }

        $this->correct($value, $normalized);
        $this->editingId = null;
        Flux::modal('value-form')->close();
        Flux::toast(variant: 'success', text: 'Значение сохранено.');
    }

    /** Значение верное — снять пометку «требует проверки» без изменения. */
    public function confirmValue(int $id): void
    {
        $value = $this->findValue($id);
        if (! $value->needs_review || $value->value === null || ! $this->allowWrite()) {
            return;
        }
        $this->write($value, 'value_confirmed', fn (ExtractedValue $locked) => ['needs_review' => false]);
    }

    /** Вернуть значение, найденное сервисом, вместе с его пометкой «требует проверки». */
    public function revertValue(int $id): void
    {
        $value = $this->findValue($id);
        if (! $value->is_corrected || ! $this->allowWrite()) {
            return;
        }
        $this->write($value, 'value_reverted', fn (ExtractedValue $locked) => [
            'value' => $locked->original_value,
            'original_value' => null,
            'is_corrected' => false,
            // причины, по которым сервис пометил значение, сохранены — пометка возвращается вместе с ним
            'needs_review' => ! empty($locked->issues),
        ]);
    }

    /** Значения в документе нет (например, «Без НДС»), а сервис что-то нашёл. */
    public function clearValue(int $id): void
    {
        $value = $this->findValue($id);
        if ($value->value === null || ! $this->allowWrite()) {
            return;
        }
        $this->correct($value, null);
    }

    /** Выбрать другой найденный вариант (сервис нашёл несколько кандидатов). */
    public function applyAlternative(int $id, int $index): void
    {
        $value = $this->findValue($id);
        $alternative = ($value->alternatives ?? [])[$index] ?? null;
        abort_if(! is_string($alternative), 404);
        if (! $this->allowWrite()) {
            return;
        }
        $violation = ValueRules::violation($this->fields->get($value->field_id)?->validation, $alternative);
        if ($violation !== null) {
            Flux::toast(variant: 'danger', text: 'Вариант не проходит проверку поля: '.$violation.'.');

            return;
        }

        $this->correct($value, $alternative);
    }

    protected function label(ExtractedValue $value): string
    {
        return $this->fields->get($value->field_id)?->label ?? $value->field_name;
    }

    protected function dataType(ExtractedValue $value): DataType
    {
        return $this->fields->get($value->field_id)?->data_type ?? DataType::String;
    }

    /** Значение (или исходное значение сервиса) в привычном виде: 15 000,00 ₽, 01.03.2025. */
    protected function display(ExtractedValue $value, bool $original = false): string
    {
        return $this->format($this->dataType($value), (string) ($original ? $value->original_value : $value->value));
    }

    protected function format(DataType $type, string $raw): string
    {
        return match ($type) {
            // из строки, а не через float: 18 знаков целой части float хранит неточно
            DataType::Amount => preg_match('/^(-?)(\d+)\.(\d{2})$/D', $raw, $m)
                ? $m[1].strrev(implode(' ', str_split(strrev($m[2]), 3))).','.$m[3].' ₽'
                : $raw,
            DataType::Date => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $raw, $m) ? "{$m[3]}.{$m[2]}.{$m[1]}" : $raw,
            default => $raw,
        };
    }

    private function correct(ExtractedValue $value, ?string $newValue): void
    {
        $this->write($value, 'value_corrected', function (ExtractedValue $locked) use ($newValue): array {
            // то же значение, что нашёл сервис, — это подтверждение, а не исправление
            if (! $locked->is_corrected && $locked->value === $newValue) {
                return ['needs_review' => false];
            }

            // исходное значение сервиса сохраняется при первом исправлении и не затирается следующими
            return [
                'original_value' => $locked->is_corrected ? $locked->original_value : $locked->value,
                'value' => $newValue,
                'is_corrected' => true,
                'needs_review' => false,
            ];
        });
    }

    /**
     * Изменение значения: строка перечитывается под блокировкой (две вкладки не затрут исходное значение),
     * флаг «требует проверки» документа пересчитывается, в журнал — только идентификаторы.
     *
     * @param  callable(ExtractedValue): array<string, mixed>  $changes
     */
    private function write(ExtractedValue $value, string $action, callable $changes): void
    {
        DB::transaction(function () use ($value, $action, $changes): void {
            $locked = ExtractedValue::whereKey($value->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill($changes($locked))->save();

            $result = $this->result;
            $result?->forceFill(['needs_review' => $result->values()->where('needs_review', true)->exists()])->save();

            AuditLog::record($action, Auth::user(), [
                'document_id' => $this->document->id,
                'value_id' => $locked->id,
                'field_name' => $locked->field_name,
            ]);
        });
        unset($this->values, $this->highlighted);
    }

    // значение ищется только среди значений последнего результата этого документа, и только обработанного
    private function findValue(int $id): ExtractedValue
    {
        abort_if($this->document->fresh()?->status !== DocumentStatus::Done || $this->result === null, 404);

        return $this->result->values()->findOrFail($id);
    }

    private function allowWrite(): bool
    {
        $key = 'value-writes:'.Auth::id();
        if (RateLimiter::tooManyAttempts($key, self::WRITES_PER_MINUTE)) {
            Flux::toast(variant: 'danger', text: 'Слишком много изменений подряд. Подождите минуту.');

            return false;
        }
        RateLimiter::hit($key);

        return true;
    }
}; ?>

@php
    $active = in_array($document->status, [DocumentStatus::Uploaded, DocumentStatus::Processing], true);
    $result = $this->result;
@endphp

<div class="flex flex-col gap-6" @if ($active) wire:poll.5s @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <flux:heading size="xl" class="break-all">{{ $document->file_name }}</flux:heading>
            <flux:text class="mt-1">
                {{ $document->template?->name ?? 'шаблон удалён' }} ·
                загружен {{ $document->uploaded_at->timezone(config('app.display_timezone'))->format('d.m.Y H:i') }}
                @if ($result?->processing_ms !== null)
                    · обработка {{ Number::format($result->processing_ms / 1000, 1, locale: 'ru') }} с
                @endif
                @if ($result?->llm_used)
                    · с LLM
                @endif
                @if ($result?->ocr_pages)
                    · OCR: {{ $result->ocr_pages }} стр.
                @endif
            </flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button icon="arrow-down-tray" :href="route('documents.file', $document)">Исходный файл</flux:button>
            <flux:button icon="arrow-left" :href="route('documents.index')" wire:navigate>К документам</flux:button>
        </div>
    </div>

    @if ($document->status !== DocumentStatus::Done)
        <flux:callout :variant="$document->status === DocumentStatus::Failed ? 'danger' : 'secondary'" icon="information-circle">
            <flux:callout.heading>{{ $document->status->label() }}</flux:callout.heading>
            <flux:callout.text>
                @if ($document->status === DocumentStatus::Failed)
                    {{ $result?->error_message ?? 'Документ не удалось обработать.' }}
                @else
                    Документ обрабатывается, страница обновится сама.
                @endif
            </flux:callout.text>
        </flux:callout>
    @else
        @if ($result?->warnings)
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>Результат может быть неполным</flux:callout.heading>
                <flux:callout.text>
                    <ul class="list-disc ps-5">
                        @foreach ($result->warnings as $warning)
                            <li>{{ ReviewCodes::warning($warning) }}</li>
                        @endforeach
                    </ul>
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="flex flex-col gap-3">
                <flux:heading size="lg">
                    Поля
                    @if ($result?->needs_review)
                        <flux:badge size="sm" color="amber" class="ms-2">требует проверки</flux:badge>
                    @endif
                </flux:heading>

                @foreach ($this->values as $value)
                    <div wire:key="value-{{ $value->id }}" @class([
                        'rounded-xl border p-4',
                        'border-amber-400 bg-amber-50 dark:border-amber-600 dark:bg-amber-950/30' => $value->needs_review,
                        'border-neutral-200 dark:border-neutral-700' => ! $value->needs_review,
                    ])>
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <button type="button" class="text-start text-sm font-medium text-zinc-600 hover:underline dark:text-zinc-300"
                                    @if ($value->start_offset !== null)
                                        x-on:click="document.getElementById('mark-{{ $value->id }}')?.scrollIntoView({ behavior: 'smooth', block: 'center' })"
                                    @endif>
                                    {{ $this->label($value) }}
                                </button>
                                <div class="mt-1 break-words text-base font-semibold">
                                    @if ($value->value === null)
                                        <span class="font-normal text-zinc-500">не найдено</span>
                                    @else
                                        {{ $this->display($value) }}
                                    @endif
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-1">
                                @if ($value->is_corrected)
                                    <flux:badge size="sm" color="blue">исправлено вручную</flux:badge>
                                @elseif ($value->source)
                                    <flux:badge size="sm">{{ ['rule' => 'правила', 'ner' => 'NER', 'llm' => 'LLM'][$value->source] ?? $value->source }}</flux:badge>
                                @endif
                                @if ($value->value !== null && ! $value->is_corrected)
                                    <flux:badge size="sm">{{ Number::percentage($value->confidence * 100) }}</flux:badge>
                                @endif
                            </div>
                        </div>

                        @if ($value->needs_review)
                            <div class="mt-2 text-sm text-amber-800 dark:text-amber-300">
                                Требует проверки: {{ collect($value->issues ?? [])->map(fn ($code) => ReviewCodes::issue($code))->join(', ') ?: 'проверьте значение' }}
                            </div>
                        @endif
                        @if ($value->is_corrected)
                            <div class="mt-2 text-sm text-zinc-500">Найдено сервисом: {{ $value->original_value === null ? 'ничего' : $this->display($value, original: true) }}</div>
                        @endif
                        @if ($value->alternatives && ! $value->is_corrected)
                            <div class="mt-2 flex flex-wrap items-center gap-1 text-sm">
                                <span class="text-zinc-500">Другие варианты:</span>
                                @foreach ($value->alternatives as $index => $alternative)
                                    <flux:button size="xs" wire:click="applyAlternative({{ $value->id }}, {{ $index }})">{{ $this->format($this->dataType($value), $alternative) }}</flux:button>
                                @endforeach
                            </div>
                        @endif

                        <div class="mt-3 flex flex-wrap gap-2">
                            <flux:button size="sm" icon="pencil-square" wire:click="editValue({{ $value->id }})">
                                {{ $value->value === null ? 'Ввести' : 'Исправить' }}
                            </flux:button>
                            @if ($value->needs_review && $value->value !== null)
                                <flux:button size="sm" icon="check" wire:click="confirmValue({{ $value->id }})">Верно</flux:button>
                            @endif
                            @if ($value->value !== null)
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearValue({{ $value->id }})">Значения нет</flux:button>
                            @endif
                            @if ($value->is_corrected)
                                <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="revertValue({{ $value->id }})">Вернуть найденное</flux:button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-col gap-3">
                <flux:heading size="lg">Текст документа</flux:heading>
                <flux:text class="text-sm">Найденные значения подсвечены: зелёным — уверенные, жёлтым — требующие проверки. Щелчок по названию поля прокручивает текст к значению.</flux:text>
                @if ($this->highlighted['html'] === '')
                    <flux:text>Текст документа не сохранён.</flux:text>
                @else
                    {{-- весь текст экранирован в TextHighlighter::render; без пробелов внутри блока: whitespace-pre-wrap показал бы их --}}
                    <div class="max-h-[70vh] overflow-auto rounded-xl border border-neutral-200 p-4 font-mono text-sm leading-relaxed whitespace-pre-wrap break-words dark:border-neutral-700">{!! $this->highlighted['html'] !!}</div>
                    @if ($this->highlighted['truncated'])
                        <flux:text class="text-sm">Показано начало текста: документ слишком большой для показа целиком.</flux:text>
                    @endif
                @endif
            </div>
        </div>
    @endif

    <flux:modal name="value-form" class="w-full md:max-w-lg">
        <form wire:submit="saveValue" class="flex flex-col gap-4">
            @php
                $editing = $editingId === null ? null : $this->values->firstWhere('id', $editingId);
            @endphp
            <flux:heading size="lg">{{ $editing ? $this->label($editing) : 'Значение' }}</flux:heading>
            <flux:input wire:model="editedValue" label="Значение"
                :description="$editing ? ValueNormalizer::hint($this->dataType($editing)) : null" autofocus />
            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button>Отмена</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">Сохранить</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
