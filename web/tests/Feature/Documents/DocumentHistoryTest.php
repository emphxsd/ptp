<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ProcessedDocuments;
use Tests\TestCase;

class DocumentHistoryTest extends TestCase
{
    use ProcessedDocuments, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->user = User::factory()->create();
    }

    private function list(): mixed
    {
        return Livewire::actingAs($this->user)->test('pages::documents.index');
    }

    public function test_list_is_filtered_by_file_name(): void
    {
        $this->processedInvoice($this->user, attributes: ['file_name' => 'Счёт Ромашка.pdf']);
        $this->processedInvoice($this->user, attributes: ['file_name' => 'Акт Лотос.pdf']);

        // в PostgreSQL поиск без учёта регистра (ILIKE); тесты на SQLite — регистр как в имени
        $this->list()->set('search', 'Ромаш')
            ->assertSee('Счёт Ромашка.pdf')
            ->assertDontSee('Акт Лотос.pdf');
    }

    public function test_search_wildcards_are_literal(): void
    {
        $this->processedInvoice($this->user, attributes: ['file_name' => 'скидка 100%.pdf']);
        $this->processedInvoice($this->user, attributes: ['file_name' => 'обычный.pdf']);

        $this->list()->set('search', '%')
            ->assertSee('скидка 100%.pdf')
            ->assertDontSee('обычный.pdf');
    }

    public function test_list_is_filtered_by_status_review_template_and_period(): void
    {
        $this->processedInvoice($this->user, attributes: ['file_name' => 'ошибка.pdf', 'status' => DocumentStatus::Failed]);
        $this->processedInvoice($this->user, attributes: ['file_name' => 'проверить.pdf', 'needs_review' => true]);
        $this->processedInvoice($this->user, attributes: ['file_name' => 'старый.pdf', 'uploaded_at' => now()->subDays(40)]);
        $actTemplate = Template::where('is_system', true)->where('name', 'Акт выполненных работ')->firstOrFail();

        $this->list()->set('status', 'failed')->assertSee('ошибка.pdf')->assertDontSee('проверить.pdf');
        $this->list()->set('review', true)->assertSee('проверить.pdf')->assertDontSee('ошибка.pdf');
        $this->list()->set('template', $actTemplate->id)->assertDontSee('проверить.pdf')->assertSee('Документов не найдено');
        $this->list()->set('from', now()->subDays(7)->toDateString())->assertDontSee('старый.pdf')->assertSee('проверить.pdf');
        $this->list()->set('to', now()->subDays(30)->toDateString())->assertSee('старый.pdf')->assertDontSee('проверить.pdf');
    }

    public function test_invalid_filter_values_are_ignored(): void
    {
        $this->processedInvoice($this->user, attributes: ['file_name' => 'счёт.pdf']);

        $this->list()->set('status', 'hacked')->set('from', 'вчера')->set('template', 999999)
            ->assertSee('счёт.pdf');
    }

    public function test_array_values_in_query_string_do_not_break_pages(): void
    {
        $this->processedInvoice($this->user, attributes: ['file_name' => 'счёт.pdf']);

        $this->actingAs($this->user)
            ->get('/documents?search[]=x&status[]=done&review[]=1&template[]=1&from[]=2025-01-01')
            ->assertOk()
            ->assertSee('счёт.pdf');
        $this->actingAs($this->user)->get('/documents/export?format[]=csv')->assertNotFound();
        $this->actingAs($this->user)->get('/documents/export?format=json&search[]=x&template[]=1')->assertOk();
    }

    public function test_period_uses_users_timezone(): void
    {
        config(['app.display_timezone' => 'Europe/Moscow']);
        // 21:30 UTC 14 января — это уже 15 января в Москве
        $this->processedInvoice($this->user, attributes: ['file_name' => 'поздний.pdf', 'uploaded_at' => '2026-01-14 21:30:00']);

        $this->list()->set('from', '2026-01-15')->set('to', '2026-01-15')->assertSee('поздний.pdf');
        $this->list()->set('to', '2026-01-14')->assertDontSee('поздний.pdf');
    }

    public function test_reversed_period_is_swapped(): void
    {
        $this->processedInvoice($this->user, attributes: ['file_name' => 'вчерашний.pdf', 'uploaded_at' => now()->subDay()]);

        $this->list()->set('from', now()->toDateString())->set('to', now()->subDays(2)->toDateString())
            ->assertSee('вчерашний.pdf');
    }

    public function test_review_filter_uses_latest_processing_only(): void
    {
        // старая обработка требовала проверки, повторная — нет: документ не должен попасть в фильтр
        $document = $this->processedInvoice($this->user, attributes: ['file_name' => 'перепроверен.pdf', 'needs_review' => true]);
        $document->results()->create(['status' => 'done', 'needs_review' => false]);

        $this->list()->set('review', true)->assertDontSee('перепроверен.pdf');
    }

    public function test_export_buttons_warn_when_selection_is_too_large(): void
    {
        config(['documents.max_export_documents' => 1]);
        $template = $this->invoiceTemplate();
        $this->processedInvoice($this->user);
        $this->processedInvoice($this->user);

        $this->list()->set('template', $template->id)
            ->assertSee('Слишком много документов для выгрузки')
            ->assertDontSeeHtml(e(route('documents.export-selection', ['format' => 'csv', 'template' => $template->id])));
    }

    public function test_filters_are_kept_in_url_and_export_links_use_them(): void
    {
        $template = $this->invoiceTemplate();
        $this->processedInvoice($this->user);

        $this->actingAs($this->user)->get(route('documents.index', ['template' => $template->id, 'review' => 1]))
            ->assertOk()
            // в атрибуте href «&» экранирован как &amp;
            ->assertSee(e(route('documents.export-selection', ['format' => 'csv', 'template' => $template->id, 'review' => 1])), false);
    }

    public function test_result_page_shows_processing_attempts(): void
    {
        $document = $this->processedInvoice($this->user);
        $document->results()->create(['status' => 'failed', 'error_code' => 'PROCESSOR_UNAVAILABLE', 'error_message' => 'Сервис обработки временно недоступен.']);

        $this->actingAs($this->user)->get(route('documents.show', $document))
            ->assertSee('История обработки')
            ->assertSee('Сервис обработки временно недоступен.');
    }
}
