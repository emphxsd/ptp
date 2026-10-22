<?php

use App\Enums\DocumentStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use App\Support\Search;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Пользователи')] class extends Component {
    use WithPagination;

    private const PER_PAGE = 25;

    // состояние уже такое (повторный щелчок): ничего не меняется и не пишется в журнал
    private const UNCHANGED = '';

    #[Url(except: '')]
    public string $search = '';

    // каждый запрос (и открытие, и действие) — только от администратора: права могли снять в другой вкладке
    public function boot(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $search = mb_substr(trim($this->search), 0, 100);

        return User::query()
            ->with('role')
            ->withCount('documents')
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($search): void {
                Search::contains($inner, 'name', $search);
                $inner->orWhere(fn (Builder $email) => Search::contains($email, 'email', $search));
            }))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);
    }

    /** Сводка для раздела: пользователи и документы по статусам — без содержимого документов. */
    #[Computed]
    public function stats(): array
    {
        $byStatus = Document::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        return [
            'users' => User::count(),
            'blocked' => User::where('is_active', false)->count(),
            'admins' => User::where('role_id', Role::idFor(RoleName::Admin))->count(),
            'done' => (int) ($byStatus[DocumentStatus::Done->value] ?? 0),
            'failed' => (int) ($byStatus[DocumentStatus::Failed->value] ?? 0),
            'queued' => (int) ($byStatus[DocumentStatus::Uploaded->value] ?? 0) + (int) ($byStatus[DocumentStatus::Processing->value] ?? 0),
            'week' => Document::where('uploaded_at', '>=', now()->subDays(7))->count(),
        ];
    }

    public function block(int $id): void
    {
        $this->change($id, 'user_blocked', function (User $user): ?string {
            if (! $user->is_active) {
                return self::UNCHANGED;
            }
            if ($user->isAdmin() && ! $this->otherActiveAdminExists($user)) {
                return 'Нельзя заблокировать последнего активного администратора.';
            }
            $user->forceFill(['is_active' => false])->save();
            // заблокированный выходит со всех устройств сразу, а не при следующем запросе
            DB::table('sessions')->where('user_id', $user->id)->delete();

            return null;
        });
    }

    public function unblock(int $id): void
    {
        $this->change($id, 'user_unblocked', function (User $user): ?string {
            if ($user->is_active) {
                return self::UNCHANGED;
            }
            $user->forceFill(['is_active' => true])->save();

            return null;
        });
    }

    public function grantAdmin(int $id): void
    {
        $this->change($id, 'admin_granted', function (User $user): ?string {
            if ($user->isAdmin()) {
                return self::UNCHANGED;
            }
            // иначе разблокировка вернула бы «спящего» администратора
            if (! $user->is_active) {
                return 'Сначала разблокируйте пользователя.';
            }
            $user->forceFill(['role_id' => Role::idFor(RoleName::Admin)])->save();

            return null;
        });
    }

    public function revokeAdmin(int $id): void
    {
        $this->change($id, 'admin_revoked', function (User $user): ?string {
            if (! $user->isAdmin()) {
                return self::UNCHANGED;
            }
            if ($user->is_active && ! $this->otherActiveAdminExists($user)) {
                return 'Нельзя снять права с последнего активного администратора.';
            }
            $user->forceFill(['role_id' => Role::idFor(RoleName::User)])->save();

            return null;
        });
    }

    /**
     * Изменение пользователя администратором: не над собой, под блокировкой строк администраторов
     * (два администратора не снимут права друг с друга одновременно), с записью в журнал.
     *
     * @param  callable(User): ?string  $apply  null — успех, строка — причина отказа
     */
    private function change(int $id, string $action, callable $apply): void
    {
        $user = User::findOrFail($id);
        if ($user->id === Auth::id()) {
            Flux::toast(variant: 'danger', text: 'Свою учётную запись менять здесь нельзя.');

            return;
        }

        // строки блокируются в одном порядке (по id) — параллельные действия не встанут в тупик; при нём — повтор
        $error = DB::transaction(function () use ($user, $action, $apply): ?string {
            $locked = User::query()
                ->where(fn ($q) => $q->where('role_id', Role::idFor(RoleName::Admin))->orWhereIn('id', [$user->id, Auth::id()]))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            // права действующего проверяются ещё раз под блокировкой: их могли снять после boot()
            $actor = $locked->get(Auth::id());
            abort_unless($actor?->isAdmin() && $actor->is_active, 403);

            $target = $locked->get($user->id);
            $error = $apply($target);
            if ($error === null) {
                AuditLog::record($action, Auth::user(), ['target_user_id' => $target->id]);
            }

            return $error;
        }, attempts: 3);

        unset($this->users, $this->stats);
        if ($error !== self::UNCHANGED) {
            Flux::toast(variant: $error === null ? 'success' : 'danger', text: $error ?? 'Готово.');
        }
    }

    private function otherActiveAdminExists(User $user): bool
    {
        return User::where('role_id', Role::idFor(RoleName::Admin))
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->exists();
    }
}; ?>

<div class="flex flex-col gap-6">
    @include('partials.admin-heading', ['current' => 'users'])

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Пользователей', $this->stats['users'], 'заблокировано: '.$this->stats['blocked'].', администраторов: '.$this->stats['admins']],
            ['Документов обработано', $this->stats['done'], 'с ошибкой: '.$this->stats['failed']],
            ['В очереди', $this->stats['queued'], 'загружены или обрабатываются'],
            ['За 7 дней', $this->stats['week'], 'загружено документов'],
        ] as [$title, $number, $note])
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:text>{{ $title }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">{{ $number }}</div>
                <flux:text class="text-xs">{{ $note }}</flux:text>
            </div>
        @endforeach
    </div>

    <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="ФИО или e-mail" class="max-w-sm" />

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-50 text-left dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-2">ФИО</th>
                    <th class="px-4 py-2">E-mail</th>
                    <th class="px-4 py-2">Документов</th>
                    <th class="px-4 py-2">Роль</th>
                    <th class="px-4 py-2">Статус</th>
                    <th class="px-4 py-2">Зарегистрирован</th>
                    <th class="px-4 py-2 text-right">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->users as $user)
                    <tr wire:key="user-{{ $user->id }}" class="border-t border-neutral-200 dark:border-neutral-700">
                        <td class="px-4 py-2">{{ $user->name }}</td>
                        <td class="px-4 py-2">{{ $user->email }}</td>
                        <td class="px-4 py-2">{{ $user->documents_count }}</td>
                        <td class="px-4 py-2">{{ $user->role->name->label() }}</td>
                        <td class="px-4 py-2">
                            @if ($user->is_active)
                                <flux:badge size="sm" color="green">Активен</flux:badge>
                            @else
                                <flux:badge size="sm" color="red">Заблокирован</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $user->created_at?->timezone(config('app.display_timezone'))->format('d.m.Y H:i') }}</td>
                        <td class="px-4 py-2">
                            @if ($user->id !== auth()->id())
                                <div class="flex justify-end gap-2">
                                    @if ($user->is_active)
                                        <flux:button size="sm" variant="danger" wire:click="block({{ $user->id }})"
                                            wire:confirm="Заблокировать {{ $user->email }}? Пользователь сразу выйдет со всех устройств.">Заблокировать</flux:button>
                                    @else
                                        <flux:button size="sm" wire:click="unblock({{ $user->id }})">Разблокировать</flux:button>
                                    @endif
                                    @if ($user->isAdmin())
                                        <flux:button size="sm" variant="ghost" wire:click="revokeAdmin({{ $user->id }})"
                                            wire:confirm="Снять права администратора с {{ $user->email }}?">Снять права</flux:button>
                                    @else
                                        <flux:button size="sm" variant="ghost" wire:click="grantAdmin({{ $user->id }})"
                                            wire:confirm="Сделать {{ $user->email }} администратором?">Сделать администратором</flux:button>
                                    @endif
                                </div>
                            @else
                                <div class="text-right text-xs text-zinc-500">это вы</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-zinc-500">Пользователи не найдены.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $this->users->links() }}
</div>
