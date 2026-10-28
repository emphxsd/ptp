<?php

namespace App\Livewire\Forms;

use App\Models\Template;
use Livewire\Form;

/**
 * Название и описание шаблона (страницы создания и редактирования). Тип документа не спрашивается:
 * шаблон подходит для любых документов; у системных шаблонов тип остаётся прежним.
 */
class TemplateForm extends Form
{
    public const MAX_DESCRIPTION = 2000;

    public string $name = '';

    public string $description = '';

    public function fillFrom(Template $template): void
    {
        $this->name = $template->name;
        $this->description = (string) $template->description;
    }

    /** @return array{name: string, description: string|null} */
    public function validated(): array
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:150', 'not_regex:/[\x00-\x1f\x7f]/'],
            'description' => ['nullable', 'string', 'max:'.self::MAX_DESCRIPTION],
        ], ValidationMessages::all());

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?: null,
        ];
    }
}
