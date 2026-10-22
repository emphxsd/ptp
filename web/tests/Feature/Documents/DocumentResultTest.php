<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\ExtractedValue;
use App\Models\ExtractionResult;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentResultTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = "Счёт на оплату № 15 от 01.03.2025\nПоставщик: ООО «Ромашка», ИНН 7707083893\nИтого к оплате: 15 000,00 <script>alert(1)</script>";

    private User $user;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->user = User::factory()->create();
        $this->template = Template::where('is_system', true)->where('name', 'Счёт на оплату')->with('fields')->firstOrFail();
    }

    private function document(?User $owner = null, DocumentStatus $status = DocumentStatus::Done): Document
    {
        $owner ??= $this->user;
        $document = new Document([
            'template_id' => $this->template->id, 'file_name' => 'счёт-15.pdf', 'file_format' => 'pdf',
            'size_bytes' => 8, 'sha256' => hash('sha256', '%PDF-1.4'),
        ]);
        $document->forceFill(['storage_key' => $owner->id.'/doc.pdf', 'status' => $status])->user()->associate($owner)->save();
        Storage::disk('documents')->put($document->storage_key, '%PDF-1.4');

        return $document;
    }

    /** @param  array<string, mixed>  $attributes */
    private function value(ExtractionResult $result, string $field, array $attributes = []): ExtractedValue
    {
        return $result->values()->create($attributes + [
            'field_id' => $this->template->fields->firstWhere('name', $field)?->id,
            'field_name' => $field,
            'confidence' => 0.9,
        ]);
    }

    /** Обработанный документ: ИНН найден уверенно, сумма — «требует проверки», НДС не найден. */
    private function processed(?User $owner = null): Document
    {
        $document = $this->document($owner);
        $result = $document->results()->create([
            'status' => 'done', 'needs_review' => true, 'text' => self::TEXT, 'processing_ms' => 412,
            'warnings' => ['LLM_UNAVAILABLE'],
        ]);
        $innStart = mb_strpos(self::TEXT, '7707083893');
        $totalStart = mb_strpos(self::TEXT, '15 000,00');
        $this->value($result, 'supplier_inn', [
            'value' => '7707083893', 'raw' => '7707083893', 'source' => 'rule', 'confidence' => 0.95,
            'start_offset' => $innStart, 'end_offset' => $innStart + 10,
        ]);
        $this->value($result, 'total', [
            'value' => '15000.00', 'raw' => '15 000,00', 'source' => 'rule', 'confidence' => 0.55,
            'start_offset' => $totalStart, 'end_offset' => $totalStart + 9, 'needs_review' => true,
            'issues' => ['LOW_CONFIDENCE'], 'alternatives' => ['1500.00'],
        ]);
        $this->value($result, 'vat', ['value' => null, 'confidence' => 0.0]);

        return $document;
    }

    private function page(Document $document): mixed
    {
        return Livewire::actingAs($this->user)->test('pages::documents.show', ['document' => $document]);
    }

    private function valueOf(Document $document, string $field): ExtractedValue
    {
        return $document->latestResult()->firstOrFail()->values()->where('field_name', $field)->firstOrFail();
    }

    public function test_guest_is_redirected_and_other_users_get_not_found(): void
    {
        $document = $this->processed();

        $this->get(route('documents.show', $document))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('documents.show', $document))->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->get(route('documents.show', $document))->assertNotFound();
    }

    public function test_page_shows_values_review_reasons_and_warnings(): void
    {
        $document = $this->processed();

        $this->actingAs($this->user)->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('счёт-15.pdf')
            ->assertSee('ИНН поставщика')
            ->assertSee('7707083893')
            ->assertSee('Сумма к оплате')
            ->assertSee('15 000,00 ₽')
            ->assertSee('требует проверки')
            ->assertSee('низкая уверенность')
            ->assertSee('не найдено')
            ->assertSee('LLM была недоступна');
    }

    public function test_found_values_are_highlighted_in_escaped_document_text(): void
    {
        $document = $this->processed();
        $inn = $this->valueOf($document, 'supplier_inn');

        $this->actingAs($this->user)->get(route('documents.show', $document))
            ->assertSee('id="mark-'.$inn->id.'"', false)
            ->assertSee('>7707083893</mark>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_broken_or_overlapping_offsets_do_not_break_highlighting(): void
    {
        $document = $this->processed();
        $result = $document->latestResult;
        $this->value($result, 'buyer', ['value' => 'x', 'start_offset' => 5000, 'end_offset' => 5010]);
        $this->value($result, 'bik', ['value' => 'y', 'start_offset' => 70, 'end_offset' => 80]);

        $this->actingAs($this->user)->get(route('documents.show', $document))->assertOk()->assertSee('Итого к оплате');
    }

    public function test_value_is_corrected_normalized_and_audited(): void
    {
        $document = $this->processed();
        $total = $this->valueOf($document, 'total');

        $this->page($document)
            ->call('editValue', $total->id)
            ->assertSet('editedValue', '15000.00')
            ->set('editedValue', '1 500,5')
            ->call('saveValue')
            ->assertHasNoErrors();

        $total->refresh();
        $this->assertSame('1500.50', $total->value);
        $this->assertSame('15000.00', $total->original_value);
        $this->assertTrue($total->is_corrected);
        $this->assertFalse($total->needs_review);
        // в документе не осталось полей «требует проверки»
        $this->assertFalse($document->latestResult()->first()->needs_review);

        $entry = AuditLog::where('action', 'value_corrected')->firstOrFail();
        $this->assertSame($this->user->id, $entry->user_id);
        // в журнале — только идентификаторы, без значений (персональные данные)
        $this->assertStringNotContainsString('1500', json_encode($entry->details));
    }

    public function test_invalid_correction_is_rejected(): void
    {
        $document = $this->processed();
        $inn = $this->valueOf($document, 'supplier_inn');

        $this->page($document)
            ->call('editValue', $inn->id)
            ->set('editedValue', '7707083894')
            ->call('saveValue')
            ->assertHasErrors('editedValue');

        $this->assertSame('7707083893', $inn->fresh()->value);
        $this->assertFalse($inn->fresh()->is_corrected);
    }

    public function test_missing_value_can_be_entered_manually(): void
    {
        $document = $this->processed();
        $vat = $this->valueOf($document, 'vat');

        $this->page($document)
            ->call('editValue', $vat->id)
            ->set('editedValue', '2500')
            ->call('saveValue')
            ->assertHasNoErrors();

        $this->assertSame('2500.00', $vat->fresh()->value);
        $this->assertNull($vat->fresh()->original_value);
        $this->assertTrue($vat->fresh()->is_corrected);
    }

    public function test_repeated_correction_keeps_first_original_and_can_be_reverted(): void
    {
        $document = $this->processed();
        $total = $this->valueOf($document, 'total');
        $page = $this->page($document);

        $page->call('editValue', $total->id)->set('editedValue', '100')->call('saveValue');
        $page->call('editValue', $total->id)->set('editedValue', '200')->call('saveValue');
        $this->assertSame('15000.00', $total->fresh()->original_value);

        $page->call('revertValue', $total->id);

        $total->refresh();
        $this->assertSame('15000.00', $total->value);
        $this->assertNull($total->original_value);
        $this->assertFalse($total->is_corrected);
    }

    public function test_revert_restores_review_flag_of_service_value(): void
    {
        $document = $this->processed();
        $total = $this->valueOf($document, 'total');
        $page = $this->page($document);

        $page->call('editValue', $total->id)->set('editedValue', '100')->call('saveValue');
        $page->call('revertValue', $total->id);

        // вернули значение сервиса — вернулась и его пометка «требует проверки» (низкая уверенность)
        $this->assertTrue($total->fresh()->needs_review);
        $this->assertTrue($document->latestResult()->first()->needs_review);
    }

    public function test_document_needs_review_until_all_flagged_values_are_checked(): void
    {
        $document = $this->processed();
        $inn = $this->valueOf($document, 'supplier_inn');
        $inn->forceFill(['needs_review' => true, 'issues' => ['NOT_CONFIRMED']])->save();
        $page = $this->page($document);

        $page->call('confirmValue', $this->valueOf($document, 'total')->id);
        $this->assertTrue($document->latestResult()->first()->needs_review);

        $page->call('confirmValue', $inn->id);
        $this->assertFalse($document->latestResult()->first()->needs_review);
    }

    public function test_correction_must_satisfy_template_field_rules(): void
    {
        $this->template->fields->firstWhere('name', 'total')->update(['validation' => ['max_value' => '100000']]);
        $document = $this->processed();
        $total = $this->valueOf($document, 'total');

        $this->page($document)
            ->call('editValue', $total->id)
            ->set('editedValue', '200 000')
            ->call('saveValue')
            ->assertHasErrors('editedValue');

        $this->assertSame('15000.00', $total->fresh()->value);
    }

    public function test_saving_unchanged_value_confirms_it_without_marking_correction(): void
    {
        $document = $this->processed();
        $total = $this->valueOf($document, 'total');

        $this->page($document)->call('editValue', $total->id)->set('editedValue', '15 000,00')->call('saveValue');

        $total->refresh();
        $this->assertFalse($total->needs_review);
        $this->assertFalse($total->is_corrected);
        $this->assertNull($total->original_value);
    }

    public function test_values_of_unfinished_document_cannot_be_changed(): void
    {
        $document = $this->processed();
        $total = $this->valueOf($document, 'total');
        $document->forceFill(['status' => DocumentStatus::Processing])->save();

        $this->page($document)->call('confirmValue', $total->id)->assertNotFound();

        $this->assertTrue($total->fresh()->needs_review);
    }

    public function test_value_changes_are_rate_limited(): void
    {
        $document = $this->processed();
        $total = $this->valueOf($document, 'total');
        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit('value-writes:'.$this->user->id);
        }

        $this->page($document)->call('confirmValue', $total->id);

        $this->assertTrue($total->fresh()->needs_review);
    }

    public function test_alternatives_are_shown_in_readable_format(): void
    {
        $this->actingAs($this->user)->get(route('documents.show', $this->processed()))->assertSee('1 500,00 ₽');
    }

    public function test_value_can_be_confirmed_without_change(): void
    {
        $document = $this->processed();
        $total = $this->valueOf($document, 'total');

        $this->page($document)->call('confirmValue', $total->id);

        $total->refresh();
        $this->assertFalse($total->needs_review);
        $this->assertSame('15000.00', $total->value);
        $this->assertFalse($total->is_corrected);
        $this->assertTrue(AuditLog::where('action', 'value_confirmed')->exists());
    }

    public function test_wrongly_found_value_can_be_cleared(): void
    {
        // «Без НДС»: правила нашли случайное число, верный ответ — значения нет
        $document = $this->processed();
        $total = $this->valueOf($document, 'total');

        $this->page($document)->call('clearValue', $total->id);

        $total->refresh();
        $this->assertNull($total->value);
        $this->assertSame('15000.00', $total->original_value);
        $this->assertTrue($total->is_corrected);
        $this->assertFalse($total->needs_review);

        $this->page($document)->call('revertValue', $total->id);
        $this->assertSame('15000.00', $total->fresh()->value);
    }

    public function test_alternative_can_be_applied(): void
    {
        $document = $this->processed();
        $total = $this->valueOf($document, 'total');

        $this->page($document)->call('applyAlternative', $total->id, 0);

        $total->refresh();
        $this->assertSame('1500.00', $total->value);
        $this->assertSame('15000.00', $total->original_value);
        $this->assertTrue($total->is_corrected);
    }

    public function test_values_of_other_documents_cannot_be_changed(): void
    {
        $document = $this->processed();
        $foreign = $this->processed(User::factory()->create());
        $foreignTotal = $this->valueOf($foreign, 'total');

        foreach (['editValue', 'confirmValue', 'revertValue', 'clearValue'] as $method) {
            $this->page($document)->call($method, $foreignTotal->id)->assertNotFound();
        }
        $this->page($document)->call('applyAlternative', $foreignTotal->id, 0)->assertNotFound();

        $this->assertSame('15000.00', $foreignTotal->fresh()->value);
        $this->assertTrue($foreignTotal->fresh()->needs_review);
    }

    public function test_display_helpers_cannot_be_called_from_browser(): void
    {
        // Livewire подставляет модель по id в параметр метода: публичный display(ExtractedValue) отдал бы чужое значение
        $document = $this->processed();
        $foreignTotal = $this->valueOf($this->processed(User::factory()->create()), 'total');

        foreach (['label', 'dataType', 'display'] as $method) {
            try {
                $this->page($document)->call($method, $foreignTotal->id);
                $this->fail("метод {$method} не должен вызываться из браузера");
            } catch (MethodNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_edited_value_id_cannot_be_tampered(): void
    {
        $document = $this->processed();
        $foreignTotal = $this->valueOf($this->processed(User::factory()->create()), 'total');

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->page($document)->set('editingId', $foreignTotal->id);
    }

    public function test_failed_document_shows_reason_and_processing_document_refreshes(): void
    {
        $failed = $this->document(status: DocumentStatus::Failed);
        $failed->results()->create(['status' => 'failed', 'error_code' => 'PASSWORD_PROTECTED', 'error_message' => 'PDF защищён паролем']);
        $processing = $this->document(status: DocumentStatus::Processing);

        $this->page($failed)->assertSee('PDF защищён паролем')->assertDontSeeHtml('wire:poll');
        $this->page($processing)->assertSee(DocumentStatus::Processing->label())->assertSeeHtml('wire:poll');
    }

    public function test_original_file_is_downloaded_only_by_owner(): void
    {
        $document = $this->processed();

        $response = $this->actingAs($this->user)->get(route('documents.file', $document))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload();
        // русское имя — в filename* (RFC 6266), в filename — его латинская замена для старых браузеров
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('счёт-15.pdf'), $response->headers->get('Content-Disposition'));

        $this->actingAs(User::factory()->create())->get(route('documents.file', $document))->assertNotFound();
        // скачивание файла с персональными данными — в журнале действий
        $this->assertSame(1, AuditLog::where('action', 'document_downloaded')->where('user_id', $this->user->id)->count());
    }

    public function test_document_list_links_to_result_page(): void
    {
        $document = $this->processed();

        $this->actingAs($this->user)->get(route('documents.index'))
            ->assertSee(route('documents.show', $document), false);
    }
}
