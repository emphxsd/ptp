<?php

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Template;
use App\Support\DocumentFilters;
use App\Support\DocumentFormat;
use App\Support\FileName;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Документы')] class extends Component {
    use WithFileUploads, WithPagination;

    private const PER_PAGE = 20;

    private const BYTES_IN_MB = 1024 * 1024;

    public ?int $template_id = null;

    /** @var array<int, TemporaryUploadedFile> */
    public array $files = [];

    // LLM — только по явному выбору пользователя (облачная получает фрагменты документа, локальная медленнее)
    public bool $use_llm = false;

    // фильтры истории — в адресной строке, ими же пользуется выгрузка выборки (DocumentFilters)
    #[Url(except: '')]
    public string $search = '';

    // строка, а не int: в адресной строке может оказаться что угодно — неподходящее DocumentFilters отбросит
    #[Url(except: '')]
    public string $template = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: false)]
    public bool $review = false;

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'template', 'status', 'review', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function filters(): DocumentFilters
    {
        return DocumentFilters::fromInput(Auth::user(), [
            'search' => $this->search, 'template' => $this->template, 'status' => $this->status,
            'review' => $this->review, 'from' => $this->from, 'to' => $this->to,
        ]);
    }

    /** Сколько обработанных документов попадёт в выгрузку с текущими фильтрами. */
    #[Computed]
    public function exportCount(): int
    {
        return $this->filters->apply(Auth::user()->documents()->getQuery())->where('status', DocumentStatus::Done)->count();
    }

    /** Шаблоны для фильтра — те, которыми пользователь обрабатывал документы (в том числе ставшие пустыми). */
    #[Computed]
    public function filterTemplates(): Collection
    {
        return Template::visibleTo(Auth::user())
            ->whereHas('documents', fn ($q) => $q->where('user_id', Auth::id()))
            ->orderByDesc('is_system')->orderBy('name')
            ->get(['id', 'name', 'is_system']);
    }

    /** Шаблоны, которыми можно обработать документ: системные и свои, в которых есть поля. */
    #[Computed]
    public function templates(): Collection
    {
        return Template::visibleTo(Auth::user())
            ->whereHas('fields')
            ->with('documentType')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function documents(): LengthAwarePaginator
    {
        return $this->filters->apply(Auth::user()->documents()->getQuery())
            ->with(['template:id,name', 'latestResult:id,extraction_results.document_id,status,needs_review,error_message'])
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    // пока есть документы в очереди или в обработке, список обновляется сам
    #[Computed]
    public function hasActive(): bool
    {
        return Auth::user()->documents()
            ->whereIn('status', [DocumentStatus::Uploaded, DocumentStatus::Processing])
            ->exists();
    }

    // не upload: в браузере $wire.upload — встроенная загрузка файлов Livewire
    public function process(): void
    {
        $user = Auth::user();
        $limiter = 'document-uploads:'.$user->id;
        // предел — на каждый файл: пакет, который не помещается в остаток минуты, не принимается целиком
        if (RateLimiter::attempts($limiter) + max(1, count($this->files)) > config('documents.uploads_per_minute')) {
            Flux::toast(variant: 'danger', text: 'Слишком много загрузок подряд. Подождите минуту.');

            return;
        }

        $this->validate($this->rules(), [
            'template_id.required' => 'Выберите шаблон.',
            'files.required' => 'Выберите файлы.',
            'files.max' => 'Не больше :max файлов за раз.',
            'files.*.max' => 'Файл больше '.config('documents.max_file_mb').' МБ.',
            'files.*.extensions' => 'Поддерживаются PDF, DOCX, PNG и JPG.',
        ]);
        $this->checkQuota();

        $useLlm = $this->use_llm && config('documents.llm_enabled');
        foreach ($this->files as $file) {
            $this->store($file, $useLlm);
            RateLimiter::hit($limiter);
        }

        $count = count($this->files);
        $this->reset('files');
        $this->resetPage();
        unset($this->documents, $this->hasActive);
        Flux::toast(variant: 'success', text: "Отправлено на обработку: {$count}.");
    }

    public function retry(int $id): void
    {
        $document = Auth::user()->documents()->findOrFail($id);
        $limiter = 'document-uploads:'.Auth::id();
        if (RateLimiter::tooManyAttempts($limiter, config('documents.uploads_per_minute'))) {
            Flux::toast(variant: 'danger', text: 'Слишком много попыток подряд. Подождите минуту.');

            return;
        }

        // атомарно: из «Ошибка» в «Загружен» переводит только один из параллельных запросов
        $updated = Document::whereKey($document->id)
            ->where('status', DocumentStatus::Failed)
            ->update(['status' => DocumentStatus::Uploaded]);
        if ($updated === 1) {
            RateLimiter::hit($limiter);
            ProcessDocument::dispatch($document->fresh());
        }
        unset($this->documents, $this->hasActive);
    }

    public function delete(int $id): void
    {
        // файл удаляется вместе с записью (Document::booted)
        Auth::user()->documents()->findOrFail($id)->delete();
        unset($this->documents, $this->hasActive);
        // удалили последний документ на последней странице — на предыдущую
        if ($this->getPage() > 1 && $this->documents->isEmpty()) {
            $this->previousPage();
        }
        Flux::toast(variant: 'success', text: 'Документ удалён.');
    }

    private function store(TemporaryUploadedFile $file, bool $useLlm): void
    {
        $user = Auth::user();
        $format = (string) DocumentFormat::detect($file->getRealPath(), $file->getClientOriginalName());
        $document = new Document([
            'template_id' => $this->template_id,
            'file_name' => FileName::clean($file->getClientOriginalName(), $format),
            'file_format' => $format,
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ]);

        // имя на диске не зависит от имени, которое дал пользователь
        $key = (string) $file->storeAs((string) $user->id, Str::uuid().'.'.$format, Document::DISK);
        try {
            $document->forceFill(['storage_key' => $key, 'use_llm' => $useLlm])->user()->associate($user)->save();
            AuditLog::record('document_uploaded', $user, ['document_id' => $document->id, 'use_llm' => $useLlm]);
            ProcessDocument::dispatch($document);
        } catch (Throwable $exception) {
            // без записи в БД файл никто не найдёт и не удалит
            Storage::disk(Document::DISK)->delete($key);

            throw $exception;
        } finally {
            // временная копия (livewire-tmp) больше не нужна
            $file->delete();
        }
    }

    /** Квоты пользователя: число документов и объём файлов. */
    private function checkQuota(): void
    {
        $documents = Auth::user()->documents();
        $count = (clone $documents)->count() + count($this->files);
        $bytes = (int) (clone $documents)->sum('size_bytes')
            + array_sum(array_map(fn (TemporaryUploadedFile $file) => $file->getSize(), $this->files));

        if ($count > config('documents.max_documents_per_user')) {
            throw ValidationException::withMessages([
                'files' => 'Достигнут предел: '.config('documents.max_documents_per_user').' документов. Удалите ненужные.',
            ]);
        }
        if ($bytes > config('documents.max_storage_mb_per_user') * self::BYTES_IN_MB) {
            throw ValidationException::withMessages([
                'files' => 'Достигнут предел места: '.config('documents.max_storage_mb_per_user').' МБ. Удалите ненужные документы.',
            ]);
        }
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        $templateIds = $this->templates->modelKeys();

        return [
            'template_id' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($templateIds): void {
                if (! in_array((int) $value, $templateIds, true)) {
                    $fail('Выберите шаблон из списка.');
                }
            }],
            'files' => ['required', 'array', 'max:'.config('documents.max_files_per_upload')],
            'files.*' => [
                'file',
                'max:'.(config('documents.max_file_mb') * 1024),
                'extensions:'.implode(',', config('documents.extensions')),
                function (string $attribute, mixed $file, Closure $fail): void {
                    if (! $file instanceof TemporaryUploadedFile
                        || DocumentFormat::detect($file->getRealPath(), $file->getClientOriginalName()) === null) {
                        $fail('Содержимое файла не похоже на PDF, DOCX, PNG или JPG.');
                    }
                },
            ],
        ];
    }
}; ?>

<div class="flex flex-col gap-8" @if ($this->hasActive) wire:poll.5s @endif>
    <div>
        <flux:heading size="xl">Документы</flux:heading>
        <flux:text class="mt-1">Загрузите документы и выберите шаблон: сервис найдёт в них значения полей.</flux:text>
    </div>

    <form wire:submit="process" class="flex max-w-2xl flex-col gap-5 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
        <flux:select wire:model="template_id" label="Шаблон" placeholder="Выберите шаблон">
            @foreach ($this->templates as $template)
                <flux:select.option :value="$template->id">
                    {{ $template->name }}{{ $template->is_system ? ' (системный)' : '' }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <div>
            <flux:input type="file" wire:model="files" multiple accept=".pdf,.docx,.png,.jpg,.jpeg"
                label="Файлы" :description="'PDF, DOCX, PNG или JPG, до '.config('documents.max_file_mb').' МБ, не больше '.config('documents.max_files_per_upload').' за раз'" />
            @foreach ($errors->get('files.*') as $messages)
                <flux:text class="mt-1 text-red-600 dark:text-red-400">{{ $messages[0] }}</flux:text>
            @endforeach
            <div wire:loading wire:target="files" class="mt-1 text-sm text-zinc-500">Файлы загружаются…</div>
        </div>

        @if (config('documents.llm_enabled') && config('documents.llm_external'))
            <flux:checkbox wire:model="use_llm" label="Использовать LLM (GigaChat) для полей, которые не нашли правила"
                description="Фрагменты документа будут отправлены во внешний сервис. Не включайте для документов с персональными данными без согласия на их обработку." />
        @elseif (config('documents.llm_enabled'))
            <flux:checkbox wire:model="use_llm" label="Использовать LLM для полей, которые не нашли правила"
                description="Документ обрабатывается локальной моделью на этом сервере и никуда не передаётся. Обработка займёт на несколько секунд дольше." />
        @endif

        <div>
            <flux:button variant="primary" type="submit" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="files,process">
                Обработать
            </flux:button>
        </div>
    </form>

    <div class="flex flex-col gap-3">
        <flux:heading size="lg">История</flux:heading>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Имя файла" class="lg:col-span-2" />
            <flux:select wire:model.live="template">
                <flux:select.option value="">Все шаблоны</flux:select.option>
                @foreach ($this->filterTemplates as $option)
                    <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="status">
                <flux:select.option value="">Все статусы</flux:select.option>
                @foreach (DocumentStatus::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live="from" type="date" aria-label="Загружен с" />
            <flux:input wire:model.live="to" type="date" aria-label="Загружен по" />
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:checkbox wire:model.live="review" label="Только требующие проверки" />
            <div class="flex flex-wrap items-center gap-2">
                @if ($this->exportCount > config('documents.max_export_documents'))
                    <flux:text class="text-sm">
                        Слишком много документов для выгрузки ({{ $this->exportCount }}, максимум {{ config('documents.max_export_documents') }}) — уточните фильтр.
                    </flux:text>
                @else
                <flux:text class="text-sm">Выгрузить обработанные ({{ $this->exportCount }}):</flux:text>
                @if ($this->filters->template !== null)
                    <flux:button size="sm" icon="table-cells" :href="route('documents.export-selection', ['format' => 'csv', ...$this->filters->toQuery()])">CSV (Excel)</flux:button>
                @else
                    <flux:tooltip content="Для CSV выберите шаблон: столбцы — его поля">
                        <flux:button size="sm" icon="table-cells" disabled>CSV (Excel)</flux:button>
                    </flux:tooltip>
                @endif
                <flux:button size="sm" icon="code-bracket" :href="route('documents.export-selection', ['format' => 'json', ...$this->filters->toQuery()])">JSON</flux:button>
                @endif
            </div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-50 text-left dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-2">Файл</th>
                    <th class="px-4 py-2">Шаблон</th>
                    <th class="px-4 py-2">Загружен</th>
                    <th class="px-4 py-2">Статус</th>
                    <th class="px-4 py-2 text-right">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->documents as $document)
                    <tr wire:key="document-{{ $document->id }}" class="border-t border-neutral-200 align-top dark:border-neutral-700">
                        <td class="px-4 py-2">
                            <a href="{{ route('documents.show', $document) }}" wire:navigate class="font-medium break-all hover:underline">{{ $document->file_name }}</a>
                            <div class="text-xs text-zinc-500">{{ strtoupper($document->file_format) }}, {{ App\Support\Format::fileSize($document->size_bytes) }}</div>
                        </td>
                        <td class="px-4 py-2">{{ $document->template?->name ?? 'шаблон удалён' }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $document->uploaded_at->timezone(config('app.display_timezone'))->format('d.m.Y H:i') }}</td>
                        <td class="px-4 py-2">
                            @php
                                $color = match ($document->status) {
                                    DocumentStatus::Done => 'green',
                                    DocumentStatus::Failed => 'red',
                                    default => 'zinc',
                                };
                            @endphp
                            <flux:badge size="sm" :color="$color">{{ $document->status->label() }}</flux:badge>
                            @if ($document->status === DocumentStatus::Done && $document->latestResult?->needs_review)
                                <flux:badge size="sm" color="amber">требует проверки</flux:badge>
                            @endif
                            @if ($document->status === DocumentStatus::Failed && $document->latestResult?->error_message)
                                <div class="mt-1 max-w-sm text-xs text-red-600 dark:text-red-400">{{ $document->latestResult->error_message }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-2">
                                @if ($document->status === DocumentStatus::Failed)
                                    <flux:button size="sm" icon="arrow-path" wire:click="retry({{ $document->id }})">Повторить</flux:button>
                                @endif
                                <flux:button size="sm" variant="danger" icon="trash" wire:click="delete({{ $document->id }})"
                                    wire:confirm="Удалить документ «{{ $document->file_name }}» и результаты его обработки?" aria-label="Удалить" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-zinc-500">
                            {{ $this->filters->isEmpty() ? 'Документов пока нет.' : 'Документов не найдено — измените фильтр.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $this->documents->links() }}
</div>
