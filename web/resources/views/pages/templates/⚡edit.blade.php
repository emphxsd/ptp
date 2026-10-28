<?php

use App\Enums\DataType;
use App\Livewire\Forms\TemplateFieldForm;
use App\Livewire\Forms\TemplateForm;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\User;
use App\Support\TemplateChangeGuard;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Шаблон')] class extends Component {
    public Template $template;

    public TemplateForm $form;

    public TemplateFieldForm $field;

    public function mount(Template $template): void
    {
        // чужой личный шаблон — 404, как несуществующий; системный без прав администратора — 403
        abort_unless(Gate::allows('view', $template), 404);
        $this->authorize('update', $template);
        $this->template = $template;
        $this->form->fillFrom($template);
    }

    /** @return Collection<int, TemplateField> */
    #[Computed]
    public function fields(): Collection
    {
        return $this->template->fields()->get();
    }

    public function saveTemplate(): void
    {
        $this->authorize('update', $this->template);
        if (! TemplateChangeGuard::allowWrite()) {
            return;
        }
        $this->template->update($this->form->validated());
        TemplateChangeGuard::auditSystem($this->template, 'updated');

        Flux::toast(variant: 'success', text: 'Шаблон сохранён.');
    }

    public function newField(): void
    {
        $this->authorize('update', $this->template);
        $this->field->reset();
        $this->field->resetErrorBag();
        Flux::modal('field-form')->show();
    }

    public function editField(int $id): void
    {
        $this->authorize('update', $this->template);
        $this->field->resetErrorBag();
        $this->field->fillFrom($this->template->fields()->findOrFail($id));
        Flux::modal('field-form')->show();
    }

    public function saveField(): void
    {
        $this->authorize('update', $this->template);
        if (! TemplateChangeGuard::allowWrite()) {
            return;
        }
        // проверка формы и обращение к сервису — до транзакции, чтобы не держать блокировку во время HTTP-запроса
        $attributes = $this->field->validatedFor($this->template);

        try {
            DB::transaction(function () use ($attributes): void {
                // блокировки шаблона и владельца: параллельные запросы не обойдут пределы числа полей
                Template::whereKey($this->template->id)->lockForUpdate()->first();
                if ($this->template->user_id !== null) {
                    User::whereKey($this->template->user_id)->lockForUpdate()->first();
                }
                $this->field->assertWithinLimits($this->template, $attributes['regex_pattern'] !== null);

                if ($this->field->id === null) {
                    $this->template->fields()->create($attributes + [
                        'sort_order' => (int) $this->template->fields()->max('sort_order') + 1,
                    ]);
                } else {
                    $this->template->fields()->findOrFail($this->field->id)->update($attributes);
                }
                $this->template->touch();
            });
        } catch (UniqueConstraintViolationException) {
            // имя заняли параллельным запросом после проверки формы; составленное из названия — составится заново
            $this->field->name === ''
                ? $this->addError('field.label', 'Не удалось подобрать системное имя — нажмите «Сохранить поле» ещё раз.')
                : $this->addError('field.name', 'Такое имя уже есть в шаблоне.');

            return;
        }

        TemplateChangeGuard::auditSystem($this->template, 'field_saved');
        unset($this->fields);
        Flux::modal('field-form')->close();
        Flux::toast(variant: 'success', text: 'Поле сохранено.');
    }

    public function deleteField(int $id): void
    {
        $this->authorize('update', $this->template);
        if (! TemplateChangeGuard::allowWrite()) {
            return;
        }
        $this->template->fields()->findOrFail($id)->delete();

        $this->template->touch();
        TemplateChangeGuard::auditSystem($this->template, 'field_deleted');
        unset($this->fields);
    }

    /** Сдвигает поле на одну позицию вверх (-1) или вниз (+1); порядок полей — порядок в результате. */
    public function moveField(int $id, int $direction): void
    {
        $this->authorize('update', $this->template);
        if (! TemplateChangeGuard::allowWrite()) {
            return;
        }

        DB::transaction(function () use ($id, $direction): void {
            $ids = $this->template->fields()->lockForUpdate()->pluck('id')->all();
            $from = array_search($id, $ids, true);
            abort_if($from === false, 404);
            $to = $from + ($direction < 0 ? -1 : 1);
            if ($to < 0 || $to >= count($ids)) {
                return;
            }
            [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

            foreach ($ids as $position => $fieldId) {
                TemplateField::whereKey($fieldId)->update(['sort_order' => $position + 1]);
            }
            $this->template->touch();
        });

        TemplateChangeGuard::auditSystem($this->template, 'fields_reordered');
        unset($this->fields);
    }
}; ?>

<div class="flex flex-col gap-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $template->name }}</flux:heading>
            @if ($template->is_system)
                <flux:text class="mt-1">Системный шаблон: изменения увидят все пользователи.</flux:text>
            @endif
        </div>
        <flux:button icon="arrow-left" :href="route('templates.index')" wire:navigate>К списку шаблонов</flux:button>
    </div>

    <form wire:submit="saveTemplate" class="flex max-w-2xl flex-col gap-4">
        <flux:input wire:model="form.name" label="Название" required />
        <flux:textarea wire:model="form.description" label="Описание" rows="2" />
        <div>
            <flux:button type="submit">Сохранить</flux:button>
        </div>
    </form>

    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <flux:heading size="lg">Поля ({{ $this->fields->count() }})</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="newField">Добавить поле</flux:button>
        </div>

        <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-2">Поле</th>
                        <th class="px-4 py-2">Тип</th>
                        <th class="px-4 py-2">Слова-якоря</th>
                        <th class="px-4 py-2">Обязательное</th>
                        <th class="px-4 py-2 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->fields as $item)
                        <tr wire:key="field-{{ $item->id }}" class="border-t border-neutral-200 dark:border-neutral-700">
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ $item->label }}</div>
                                <div class="font-mono text-xs text-zinc-500">{{ $item->name }}</div>
                            </td>
                            <td class="px-4 py-2">{{ $item->data_type->label() }}</td>
                            <td class="px-4 py-2">{{ implode(', ', $item->keywords ?? []) ?: '—' }}</td>
                            <td class="px-4 py-2">{{ $item->is_required ? 'да' : 'нет' }}</td>
                            <td class="px-4 py-2">
                                <div class="flex justify-end gap-1">
                                    <flux:button size="sm" variant="ghost" icon="arrow-up" wire:click="moveField({{ $item->id }}, -1)" :disabled="$loop->first" aria-label="Выше" />
                                    <flux:button size="sm" variant="ghost" icon="arrow-down" wire:click="moveField({{ $item->id }}, 1)" :disabled="$loop->last" aria-label="Ниже" />
                                    <flux:button size="sm" icon="pencil-square" wire:click="editField({{ $item->id }})">Изменить</flux:button>
                                    <flux:button size="sm" variant="danger" icon="trash" wire:click="deleteField({{ $item->id }})"
                                        wire:confirm="Удалить поле «{{ $item->label }}»?" aria-label="Удалить" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-zinc-500">Полей пока нет. Шаблон без полей нельзя использовать для обработки — добавьте первое поле.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <flux:modal name="field-form" class="w-full md:max-w-3xl">
        <form wire:submit="saveField" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ $field->id ? 'Изменить поле' : 'Новое поле' }}</flux:heading>

            <flux:input wire:model="field.label" label="Что нужно найти" placeholder="Например: Сумма к оплате, ИНН поставщика, Дата договора" required
                description="Название поля — по нему сервис поймёт, что искать" />

            <div class="grid gap-4 md:grid-cols-2">
                <flux:select wire:model.live="field.data_type" label="Что это за значение">
                    <flux:select.option value="{{ TemplateFieldForm::AUTO_TYPE }}">Определить по названию</flux:select.option>
                    @foreach (DataType::cases() as $type)
                        <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="flex items-end pb-2">
                    <flux:checkbox wire:model="field.is_required" label="Обязательное поле" />
                </div>
            </div>

            <flux:textarea wire:model="field.keywords" label="Слова рядом со значением (необязательно)" rows="3"
                description="Подписи из документа, рядом с которыми стоит значение, по одной на строку: «Итого к оплате». «*» в конце — любое окончание: поставщик*" />

            <flux:input wire:model="field.llm_hint" label="Подсказка для LLM (необязательно)" description="Если правила не нашли значение, модель получит эту подсказку" />

            {{-- раздел свёрнут: ошибки его полей показываются и здесь, иначе пользователь их не увидит --}}
            @foreach (['field.name' => 'Системное имя', 'field.regex_pattern' => 'Регулярное выражение', 'field.ner_label' => 'Сущность NER'] as $key => $title)
                @error($key)
                    <flux:text class="text-red-600 dark:text-red-400">{{ $title }}: {{ $message }} Исправьте в разделе «Для опытных пользователей».</flux:text>
                @enderror
            @endforeach
            <details wire:ignore.self class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                <summary class="cursor-pointer font-medium">Для опытных пользователей: системное имя, регулярное выражение, NER</summary>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="field.name" label="Системное имя" description="Столбец в выгрузке. Если пусто — составится из названия" class:input="font-mono" />
                    <flux:input wire:model="field.regex_pattern" label="Регулярное выражение" description="RE2; группа 1 — само значение. \w и \b — только латиница; для кириллицы: [А-Яа-яЁё]" class:input="font-mono" />
                    <flux:select wire:model="field.ner_label" label="Сущность NER">
                        <flux:select.option value="">Не использовать</flux:select.option>
                        <flux:select.option value="PER">Персона (PER)</flux:select.option>
                        <flux:select.option value="ORG">Организация (ORG)</flux:select.option>
                        <flux:select.option value="LOC">Место (LOC)</flux:select.option>
                    </flux:select>
                </div>
            </details>

            <details wire:ignore.self class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                <summary class="cursor-pointer font-medium">Проверка значения</summary>
                <flux:text class="mt-2">Если значение не проходит проверку, оно не удаляется, а поле помечается «требует проверки».</flux:text>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    @if ($field->data_type === DataType::Amount->value)
                        <flux:input wire:model="field.min_value" label="Минимальная сумма" inputmode="decimal" />
                        <flux:input wire:model="field.max_value" label="Максимальная сумма" inputmode="decimal" />
                    @endif
                    @if ($field->data_type === DataType::Date->value)
                        <flux:input wire:model="field.min_date" type="date" label="Не раньше" />
                        <flux:input wire:model="field.max_date" type="date" label="Не позже" />
                    @endif
                    <flux:input wire:model="field.max_length" type="number" min="1" label="Максимальная длина" />
                    <flux:input wire:model="field.pattern" label="Формат значения (RE2)" description="Значение должно совпасть целиком" class:input="font-mono" />
                    <flux:textarea wire:model="field.allowed_values" label="Допустимые значения" rows="3" description="По одному на строку" class="md:col-span-2" />
                </div>
            </details>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button>Отмена</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">Сохранить поле</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
