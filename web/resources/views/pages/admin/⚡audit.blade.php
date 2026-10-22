<?php

use App\Models\AuditLog;
use App\Support\AuditActions;
use App\Support\InputDate;
use App\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Журнал действий')] class extends Component {
    use WithPagination;

    private const PER_PAGE = 50;

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $user = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    // каждый запрос — только от администратора: права могли снять в другой вкладке
    public function boot(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['action', 'user', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $user = mb_substr(trim($this->user), 0, 100);
        [$from, $to] = InputDate::period($this->from, $this->to);

        return AuditLog::query()
            ->with('user:id,name,email')
            // неизвестное действие в адресной строке не фильтрует
            ->when(array_key_exists($this->action, AuditActions::LABELS), fn (Builder $q) => $q->where('action', $this->action))
            ->when($user !== '', fn (Builder $q) => $q->whereHas('user', function (Builder $u) use ($user): void {
                Search::contains($u, 'email', $user);
                $u->orWhere(fn (Builder $name) => Search::contains($name, 'name', $user));
            }))
            ->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', $from?->utc()))
            ->when($to !== null, fn (Builder $q) => $q->where('created_at', '<=', $to?->utc()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }
}; ?>

<div class="flex flex-col gap-6">
    @include('partials.admin-heading', ['current' => 'audit'])

    <flux:text>Вход и выход, загрузка и выгрузка документов, исправления, изменения системных шаблонов и прав. Записи хранятся {{ config('audit.retention_days') }} дн.</flux:text>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:select wire:model.live="action" label="Действие">
            <flux:select.option value="">Все</flux:select.option>
            @foreach (AuditActions::LABELS as $code => $label)
                <flux:select.option :value="$code">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.400ms="user" label="Пользователь" placeholder="ФИО или e-mail" />
        <flux:input wire:model.live="from" type="date" label="С" />
        <flux:input wire:model.live="to" type="date" label="По" />
    </div>

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-50 text-left dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-2">Время</th>
                    <th class="px-4 py-2">Пользователь</th>
                    <th class="px-4 py-2">Действие</th>
                    <th class="px-4 py-2">Подробности</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->entries as $entry)
                    <tr wire:key="entry-{{ $entry->id }}" class="border-t border-neutral-200 align-top dark:border-neutral-700">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $entry->created_at->timezone(config('app.display_timezone'))->format('d.m.Y H:i:s') }}</td>
                        <td class="px-4 py-2">
                            @if ($entry->user)
                                <div>{{ $entry->user->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $entry->user->email }}</div>
                            @else
                                <span class="text-zinc-500">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ AuditActions::label($entry->action) }}</td>
                        <td class="px-4 py-2 font-mono text-xs break-all text-zinc-600 dark:text-zinc-400">{{ AuditActions::details($entry->details) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-zinc-500">Записей не найдено.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $this->entries->links() }}
</div>
