<x-layouts::app :title="__('Администрирование')">
    <div class="flex flex-col gap-6">
        <flux:heading size="xl">{{ __('Администрирование') }}</flux:heading>

        <flux:heading size="lg">{{ __('Пользователи') }}</flux:heading>
        <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-2">{{ __('ФИО') }}</th>
                        <th class="px-4 py-2">E-mail</th>
                        <th class="px-4 py-2">{{ __('Роль') }}</th>
                        <th class="px-4 py-2">{{ __('Статус') }}</th>
                        <th class="px-4 py-2">{{ __('Зарегистрирован') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr class="border-t border-neutral-200 dark:border-neutral-700">
                            <td class="px-4 py-2">{{ $user->name }}</td>
                            <td class="px-4 py-2">{{ $user->email }}</td>
                            <td class="px-4 py-2">{{ $user->role->name->label() }}</td>
                            <td class="px-4 py-2">{{ $user->is_active ? __('Активен') : __('Заблокирован') }}</td>
                            <td class="px-4 py-2">{{ $user->created_at?->timezone(config('app.display_timezone'))->format('d.m.Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $users->links() }}
    </div>
</x-layouts::app>
