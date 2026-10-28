<?php

use App\Models\Template;
use App\Models\TemplateField;
use App\Models\User;
use App\Support\TemplateChangeGuard;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Шаблоны')] class extends Component {
    /** @return Collection<int, Template> */
    #[Computed]
    public function templates(): Collection
    {
        return Template::visibleTo(Auth::user())
            ->with('documentType')
            ->withCount('fields')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function copy(int $id): void
    {
        if (! TemplateChangeGuard::allowWrite()) {
            return;
        }
        $user = Auth::user();
        // чужой личный шаблон — 404, как несуществующий: нельзя узнать, какие id заняты
        $source = Template::visibleTo($user)->with('fields')->findOrFail($id);

        $copy = DB::transaction(function () use ($user, $source): ?Template {
            // блокировка строки пользователя: параллельные запросы не обойдут пределы
            User::whereKey($user->id)->lockForUpdate()->first();
            $fields = TemplateField::whereIn('template_id', $user->templates()->select('id'))->count();
            if ($user->templates()->count() >= Template::MAX_PER_USER
                || $fields + $source->fields->count() > Template::MAX_FIELDS_PER_USER) {
                return null;
            }

            return $source->copyFor($user);
        });

        if ($copy === null) {
            Flux::toast(variant: 'danger', text: 'Достигнут предел числа шаблонов или полей. Удалите ненужные.');

            return;
        }
        $this->redirectRoute('templates.edit', $copy, navigate: true);
    }

    public function delete(int $id): void
    {
        if (! TemplateChangeGuard::allowWrite()) {
            return;
        }
        $template = Template::visibleTo(Auth::user())->findOrFail($id);
        $this->authorize('delete', $template);

        TemplateChangeGuard::auditSystem($template, 'deleted');
        $template->delete();
        unset($this->templates);
        Flux::toast(variant: 'success', text: 'Шаблон удалён.');
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Шаблоны</flux:heading>
            <flux:text class="mt-1">Какие поля извлекать из документа. Системный шаблон можно скопировать и настроить под себя.</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('templates.create')" wire:navigate>
            Новый шаблон
        </flux:button>
    </div>

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-50 text-left dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-2">Название</th>
                    <th class="px-4 py-2">Тип документа</th>
                    <th class="px-4 py-2">Полей</th>
                    <th class="px-4 py-2">Вид</th>
                    <th class="px-4 py-2 text-right">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->templates as $template)
                    <tr wire:key="template-{{ $template->id }}" class="border-t border-neutral-200 dark:border-neutral-700">
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ $template->name }}</div>
                            @if ($template->description)
                                <div class="text-zinc-500 dark:text-zinc-400">{{ $template->description }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $template->documentType?->name ?? 'Любой' }}</td>
                        <td class="px-4 py-2">
                            @if ($template->fields_count > 0)
                                {{ $template->fields_count }}
                            @else
                                <flux:badge size="sm" color="amber">нет полей</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if ($template->is_system)
                                <flux:badge size="sm" color="blue">Системный</flux:badge>
                            @else
                                <flux:badge size="sm">Мой</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-2">
                                @can('update', $template)
                                    <flux:button size="sm" icon="pencil-square" :href="route('templates.edit', $template)" wire:navigate>
                                        Изменить
                                    </flux:button>
                                @endcan
                                <flux:button size="sm" icon="document-duplicate" wire:click="copy({{ $template->id }})">
                                    Копировать
                                </flux:button>
                                @can('delete', $template)
                                    <flux:button size="sm" variant="danger" icon="trash" wire:click="delete({{ $template->id }})"
                                        wire:confirm="Удалить шаблон «{{ $template->name }}»? Обработанные документы останутся в истории.">
                                        Удалить
                                    </flux:button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-zinc-500">Шаблонов пока нет.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
