<?php

namespace App\Livewire\Forms;

use App\Models\Template;
use Livewire\Form;

/** Название, тип документа и описание шаблона (страницы создания и редактирования). */
class TemplateForm extends Form
{
    public const MAX_DESCRIPTION = 2000;

    public string $name = '';

    public ?int $document_type_id = null;

    public string $description = '';

    public function fillFrom(Template $template): void
    {
        $this->name = $template->name;
        $this->document_type_id = $template->document_type_id;
        $this->description = (string) $template->description;
    }

    /** @return array{name: string, document_type_id: int, description: string|null} */
    public function validated(): array
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:150', 'not_regex:/[\x00-\x1f\x7f]/'],
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'description' => ['nullable', 'string', 'max:'.self::MAX_DESCRIPTION],
        ], ValidationMessages::all());

        return [
            'name' => $data['name'],
            'document_type_id' => (int) $data['document_type_id'],
            'description' => $data['description'] ?: null,
        ];
    }
}
