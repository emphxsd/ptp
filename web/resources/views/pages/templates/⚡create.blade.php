<?php

use App\Livewire\Forms\TemplateForm;
use App\Models\DocumentType;
use App\Models\Template;
use App\Models\User;
use App\Support\TemplateChangeGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Новый шаблон')] class extends Component {
    public TemplateForm $form;

    // учитывается только для администратора
    public bool $is_system = false;

    /** @return Collection<int, DocumentType> */
    #[Computed]
    public function documentTypes(): Collection
    {
        return DocumentType::orderBy('id')->get();
    }

    public function save(): void
    {
        if (! TemplateChangeGuard::allowWrite()) {
            return;
        }
        $user = Auth::user();
        $system = $this->is_system && Gate::allows('createSystem', Template::class);
        $data = $this->form->validated();

        $template = DB::transaction(function () use ($user, $system, $data): Template {
            // блокировка строки пользователя: параллельные запросы не обойдут предел
            User::whereKey($user->id)->lockForUpdate()->first();
            if (! $system && $user->templates()->count() >= Template::MAX_PER_USER) {
                throw ValidationException::withMessages([
                    'form.name' => 'Достигнут предел: '.Template::MAX_PER_USER.' шаблонов. Удалите ненужные.',
                ]);
            }

            $template = new Template($data);
            if ($system) {
                $template->is_system = true;
            } else {
                $template->user()->associate($user);
            }
            $template->save();

            return $template;
        });
        TemplateChangeGuard::auditSystem($template, 'created');

        $this->redirectRoute('templates.edit', $template, navigate: true);
    }
}; ?>

<div class="flex max-w-2xl flex-col gap-6">
    <flux:heading size="xl">Новый шаблон</flux:heading>

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input wire:model="form.name" label="Название" placeholder="Например, «Счета поставщиков»" required autofocus />

        <flux:select wire:model="form.document_type_id" label="Тип документа" placeholder="Выберите тип" required>
            @foreach ($this->documentTypes as $type)
                <flux:select.option :value="$type->id">{{ $type->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:textarea wire:model="form.description" label="Описание" rows="2" />

        @can('createSystem', App\Models\Template::class)
            <flux:checkbox wire:model="is_system" label="Системный шаблон (доступен всем пользователям)" />
        @endcan

        <div class="flex gap-3">
            <flux:button variant="primary" type="submit">Создать и добавить поля</flux:button>
            <flux:button :href="route('templates.index')" wire:navigate>Отмена</flux:button>
        </div>
    </form>
</div>
