<?php

use App\Enums\DataType;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\User;
use App\Services\Processor\ProcessorClient;
use App\Services\Processor\ProcessorRejectedException;
use App\Services\Processor\ProcessorUnavailableException;
use App\Support\AnchorLearner;
use App\Support\DocumentFormat;
use App\Support\FieldDefaults;
use App\Support\TemplateChangeGuard;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Шаблон по примеру: пользователь загружает один документ, выделяет в его тексте значения — поле, слово-якорь
 * и тип создаются сами (подпись слева от значения — название и якорь, вид значения — тип). Документ не сохраняется.
 */
new #[Title('Шаблон по примеру')] class extends Component {
    use WithFileUploads;

    private const MAX_TEXT_CHARS = 50_000;

    private const MAX_SELECTION_CHARS = 200;

    private const TEXT_REQUESTS_PER_MINUTE = 10;

    // строгие типы по виду значения надёжнее, чем по названию («Налоговый номер поставщика» — ИНН, а не организация)
    private const VALUE_TYPES = [DataType::Inn, DataType::Date, DataType::Amount, DataType::Phone, DataType::Email];

    public ?TemporaryUploadedFile $file = null;

    // текст примера и выбранные поля задаёт только сервер
    #[Locked]
    public string $text = '';

    /** @var list<array{label: string, data_type: string, keywords: list<string>, example: string}> */
    #[Locked]
    public array $fields = [];

    public string $name = '';

    public function updatedFile(): void
    {
        $this->resetErrorBag('file');
        $this->validate(['file' => [
            'required', 'file', 'max:'.(config('documents.max_file_mb') * 1024), 'extensions:'.implode(',', config('documents.extensions')),
        ]], ['file.extensions' => 'Поддерживаются PDF, DOCX, PNG и JPG.']);

        $key = 'example-text:'.Auth::id();
        if (RateLimiter::tooManyAttempts($key, self::TEXT_REQUESTS_PER_MINUTE)) {
            $this->addError('file', 'Слишком много загрузок подряд. Подождите минуту.');

            return;
        }
        RateLimiter::hit($key);

        $file = $this->file;
        try {
            $format = DocumentFormat::detect($file->getRealPath(), $file->getClientOriginalName());
            if ($format === null) {
                $this->addError('file', 'Содержимое файла не похоже на PDF, DOCX, PNG или JPG.');

                return;
            }
            $text = app(ProcessorClient::class)->text(Utils::streamFor(fopen($file->getRealPath(), 'rb')), 'document.'.$format);
        } catch (ProcessorRejectedException $exception) {
            $this->addError('file', $exception->getMessage());

            return;
        } catch (ProcessorUnavailableException) {
            $this->addError('file', 'Сервис обработки недоступен. Попробуйте позже.');

            return;
        } finally {
            // пример нужен только на время настройки — копия файла не хранится
            $file->delete();
            $this->file = null;
        }

        if (trim($text) === '') {
            $this->addError('file', 'В документе не найден текст.');

            return;
        }
        $this->text = mb_substr($text, 0, self::MAX_TEXT_CHARS);
        $this->fields = [];
    }

    /** Выделенный мышью фрагмент (и его позиция в тексте, в символах) — новое поле шаблона. */
    public function addField(string $selected, int $offset, string $label): void
    {
        $this->resetErrorBag('selection');
        $selected = trim($selected);
        if ($this->text === '' || $selected === '' || mb_strlen($selected) > self::MAX_SELECTION_CHARS) {
            $this->addError('selection', 'Выделите в тексте значение — до '.self::MAX_SELECTION_CHARS.' символов.');

            return;
        }
        if (count($this->fields) >= Template::MAX_FIELDS) {
            $this->addError('selection', 'В шаблоне не может быть больше '.Template::MAX_FIELDS.' полей.');

            return;
        }
        // позиция из браузера проверяется: фрагмент должен стоять в тексте именно там, иначе — первое вхождение
        $position = mb_substr($this->text, max(0, $offset), mb_strlen($selected)) === $selected
            ? max(0, $offset)
            : mb_strpos($this->text, $selected);
        if ($position === false) {
            $this->addError('selection', 'Выделенный фрагмент не найден в тексте документа.');

            return;
        }

        $anchor = AnchorLearner::labelAt($this->text, $position);
        $label = mb_substr(trim($label) !== '' ? trim($label) : (string) $anchor, 0, 150);
        if ($label === '') {
            $this->addError('selection', 'Рядом со значением нет подписи — введите название поля.');

            return;
        }

        $byValue = FieldDefaults::guessTypeFromValue($selected);
        $type = in_array($byValue, self::VALUE_TYPES, true) ? $byValue : FieldDefaults::guessType($label);
        $this->fields[] = [
            'label' => $label,
            'data_type' => $type->value,
            'keywords' => $anchor === null ? [] : [$anchor],
            'example' => mb_substr($selected, 0, 100),
        ];
    }

    public function removeField(int $index): void
    {
        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
    }

    public function save(): void
    {
        if (! TemplateChangeGuard::allowWrite()) {
            return;
        }
        $this->name = trim($this->name);
        $this->validate([
            'name' => ['required', 'string', 'max:150', 'not_regex:/[\x00-\x1f\x7f]/'],
            'fields' => ['required', 'array', 'min:1'],
        ], ['fields.required' => 'Выделите в документе хотя бы одно значение и добавьте поле.']);

        $user = Auth::user();
        $template = DB::transaction(function () use ($user): Template {
            // блокировка строки пользователя: параллельные запросы не обойдут пределы
            User::whereKey($user->id)->lockForUpdate()->first();
            if ($user->templates()->count() >= Template::MAX_PER_USER) {
                throw ValidationException::withMessages(['name' => 'Достигнут предел: '.Template::MAX_PER_USER.' шаблонов. Удалите ненужные.']);
            }
            $userFields = TemplateField::whereIn('template_id', $user->templates()->select('id'))->count();
            if ($userFields + count($this->fields) > Template::MAX_FIELDS_PER_USER) {
                throw ValidationException::withMessages(['fields' => 'Во всех ваших шаблонах не может быть больше '.Template::MAX_FIELDS_PER_USER.' полей.']);
            }

            $template = new Template(['name' => $this->name, 'description' => 'Создан по примеру документа']);
            $template->user()->associate($user)->save();
            $taken = [];
            foreach ($this->fields as $position => $field) {
                $taken[] = $name = FieldDefaults::systemName($field['label'], $taken);
                $template->fields()->create([
                    'name' => $name,
                    'label' => $field['label'],
                    'data_type' => DataType::from($field['data_type']),
                    'keywords' => $field['keywords'] ?: null,
                    'sort_order' => $position + 1,
                ]);
            }

            return $template;
        });

        $this->redirectRoute('templates.edit', $template, navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">Шаблон по примеру</flux:heading>
            <flux:text class="mt-1">Загрузите один документ и выделите мышью значения, которые нужно извлекать: название поля, слово-якорь и тип определятся сами.</flux:text>
        </div>
        <flux:button icon="arrow-left" :href="route('templates.index')" wire:navigate>К списку шаблонов</flux:button>
    </div>

    <div class="max-w-2xl">
        <flux:input type="file" wire:model="file" accept=".pdf,.docx,.png,.jpg,.jpeg" label="Документ-пример"
            :description="'PDF, DOCX, PNG или JPG, до '.config('documents.max_file_mb').' МБ. Файл не сохраняется — нужен только его текст.'" />
        <div wire:loading wire:target="file" class="mt-1 text-sm text-zinc-500">Получаю текст документа…</div>
    </div>

    @if ($text !== '')
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="flex flex-col gap-3" x-data="{
                selected: '', offset: 0, label: '',
                pick() {
                    const selection = window.getSelection();
                    if (!selection.rangeCount) return;
                    const range = selection.getRangeAt(0);
                    if (!this.$refs.doc.contains(range.startContainer)) return;
                    const before = document.createRange();
                    before.setStart(this.$refs.doc, 0);
                    before.setEnd(range.startContainer, range.startOffset);
                    // позиция в символах (как mb_substr на сервере), а не в единицах UTF-16
                    this.offset = [...before.toString()].length;
                    this.selected = selection.toString();
                },
            }">
                <flux:heading size="lg">Текст документа</flux:heading>
                <flux:text>Выделите значение мышью, при необходимости введите название и нажмите «Добавить поле».</flux:text>
                <pre x-ref="doc" @mouseup="pick()" class="max-h-[32rem] overflow-auto whitespace-pre-wrap break-words rounded-xl border border-neutral-200 p-4 font-sans text-sm leading-relaxed dark:border-neutral-700">{{ $text }}</pre>

                <div class="flex flex-col gap-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:text>
                        Выделено: <span class="font-medium" x-text="selected ? '«' + selected.trim() + '»' : 'ничего'"></span>
                    </flux:text>
                    <flux:input x-model="label" placeholder="Название поля (необязательно — возьмётся подпись слева)" />
                    <div>
                        <flux:button variant="primary" icon="plus" x-bind:disabled="!selected.trim()"
                            x-on:click="$wire.addField(selected, offset, label); selected = ''; label = ''">Добавить поле</flux:button>
                    </div>
                    @error('selection')
                        <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                    @enderror
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <flux:heading size="lg">Поля ({{ count($fields) }})</flux:heading>
                <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
                    <table class="min-w-full text-sm">
                        <thead class="bg-zinc-50 text-left dark:bg-zinc-900">
                            <tr>
                                <th class="px-3 py-2">Поле</th>
                                <th class="px-3 py-2">Тип</th>
                                <th class="px-3 py-2">Слово-якорь</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($fields as $index => $field)
                                <tr wire:key="example-field-{{ $index }}" class="border-t border-neutral-200 dark:border-neutral-700">
                                    <td class="px-3 py-2">
                                        <div class="font-medium">{{ $field['label'] }}</div>
                                        <div class="text-xs text-zinc-500">пример: {{ $field['example'] }}</div>
                                    </td>
                                    <td class="px-3 py-2">{{ DataType::from($field['data_type'])->label() }}</td>
                                    <td class="px-3 py-2">{{ $field['keywords'][0] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeField({{ $index }})" aria-label="Убрать поле" />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-6 text-center text-zinc-500">Выделите в тексте первое значение.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @error('fields')
                    <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                <form wire:submit="save" class="flex flex-col gap-3">
                    <flux:input wire:model="name" label="Название шаблона" placeholder="Например, «Счета поставщиков»" required />
                    <div>
                        <flux:button type="submit" variant="primary">Создать шаблон</flux:button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
