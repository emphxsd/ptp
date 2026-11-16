<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Обучение на исправлениях: подпись рядом с исправленным значением добавляется в слова-якоря поля шаблона. */
class CorrectionLearningTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = "Счёт на оплату № 15 от 01.03.2025\nПоставщик: ООО «Ромашка», ИНН 7707083893\nК оплате: 15 000,00";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    /** Обработанный документ своего шаблона: значение суммы найдено неверно. */
    private function document(User $user, Template $template): Document
    {
        $document = new Document([
            'template_id' => $template->id, 'file_name' => 'счёт.pdf', 'file_format' => 'pdf', 'size_bytes' => 8,
        ]);
        $document->forceFill(['storage_key' => $user->id.'/x.pdf', 'status' => DocumentStatus::Done])->user()->associate($user)->save();
        $result = $document->results()->create(['status' => 'done', 'text' => self::TEXT, 'needs_review' => true]);
        $total = $template->fields()->where('name', 'total')->firstOrFail();
        $result->values()->create([
            'field_id' => $total->id, 'field_name' => 'total', 'value' => '1500.00', 'source' => 'rule',
            'confidence' => 0.5, 'needs_review' => true,
        ]);

        return $document;
    }

    private function ownInvoiceTemplate(User $user): Template
    {
        return Template::where('is_system', true)->where('name', 'Счёт на оплату')->firstOrFail()->copyFor($user);
    }

    private function correctTotal(User $user, Document $document, string $value): mixed
    {
        $total = $document->latestResult->values()->where('field_name', 'total')->firstOrFail();

        return Livewire::actingAs($user)->test('pages::documents.show', ['document' => $document])
            ->call('editValue', $total->id)->set('editedValue', $value)->call('saveValue')->assertHasNoErrors();
    }

    public function test_correction_adds_label_to_anchor_words_of_own_template(): void
    {
        $user = User::factory()->create();
        $template = $this->ownInvoiceTemplate($user);

        $this->correctTotal($user, $this->document($user, $template), '15 000,00')
            ->assertDispatched('toast-show', fn (string $e, array $p) => str_contains(json_encode($p, JSON_UNESCAPED_UNICODE), 'К оплате'));

        $this->assertContains('К оплате', $template->fields()->where('name', 'total')->firstOrFail()->keywords);
    }

    public function test_known_anchor_is_not_added_twice(): void
    {
        $user = User::factory()->create();
        $template = $this->ownInvoiceTemplate($user);
        $field = $template->fields()->where('name', 'total')->firstOrFail();
        $field->update(['keywords' => [...($field->keywords ?? []), 'к оплате']]);
        $before = $field->fresh()->keywords;

        $this->correctTotal($user, $this->document($user, $template), '15000.00');

        $this->assertSame($before, $field->fresh()->keywords);
    }

    public function test_system_template_is_not_changed_by_a_user_correction(): void
    {
        $user = User::factory()->create();
        $system = Template::where('is_system', true)->where('name', 'Счёт на оплату')->firstOrFail();
        $before = $system->fields()->where('name', 'total')->firstOrFail()->keywords;

        $this->correctTotal($user, $this->document($user, $system), '15000.00');

        $this->assertSame($before, $system->fields()->where('name', 'total')->firstOrFail()->keywords);
    }

    public function test_value_not_found_in_text_changes_nothing(): void
    {
        $user = User::factory()->create();
        $template = $this->ownInvoiceTemplate($user);
        $before = $template->fields()->where('name', 'total')->firstOrFail()->keywords;

        $this->correctTotal($user, $this->document($user, $template), '99 999,00');

        $this->assertSame($before, $template->fields()->where('name', 'total')->firstOrFail()->keywords);
    }
}
