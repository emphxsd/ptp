<div class="flex flex-col gap-3">
    <flux:heading size="xl">Администрирование</flux:heading>
    <flux:navbar class="-mb-px">
        <flux:navbar.item :href="route('admin.index')" :current="$current === 'users'" wire:navigate>Пользователи</flux:navbar.item>
        <flux:navbar.item :href="route('admin.audit')" :current="$current === 'audit'" wire:navigate>Журнал действий</flux:navbar.item>
    </flux:navbar>
</div>
