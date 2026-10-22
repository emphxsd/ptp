<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Models\AuditLog;
use App\Models\Export;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ProcessedDocuments;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use ProcessedDocuments, RefreshDatabase;

    private const BOM = "\xEF\xBB\xBF";

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->user = User::factory()->create();
    }

    /** @return list<list<string>> строки CSV без BOM */
    private function csv(TestResponse $response): array
    {
        $content = $response->streamedContent();
        $this->assertStringStartsWith(self::BOM, $content, 'BOM нужен, чтобы Excel открыл UTF-8');
        $lines = preg_split('/\r?\n/', trim(substr($content, strlen(self::BOM))));

        return array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), $lines);
    }

    public function test_document_is_exported_to_csv_with_one_row_per_field(): void
    {
        $document = $this->processedInvoice($this->user, ['total' => '15000.00', 'vat' => null], ['needs_review' => true]);

        $response = $this->actingAs($this->user)->get(route('documents.export', [$document, 'csv']))->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $rows = $this->csv($response);
        $this->assertSame(['Поле', 'Значение', 'Требует проверки', 'Исправлено вручную', 'Источник', 'Уверенность'], $rows[0]);
        $byLabel = collect(array_slice($rows, 1))->keyBy(0);
        $this->assertSame(['Сумма к оплате', '15000.00', 'да', 'нет', 'правила', '0.90'], $byLabel['Сумма к оплате']);
        $this->assertSame('', $byLabel['Сумма НДС'][1]);
        $this->assertSame(['Номер счёта', 'Дата счёта'], array_slice(array_column(array_slice($rows, 1), 0), 0, 2));
    }

    public function test_csv_cells_cannot_inject_spreadsheet_formulas(): void
    {
        $document = $this->processedInvoice($this->user, ['supplier' => '=HYPERLINK("http://evil","x")', 'buyer' => '+1-1', 'number' => '-5']);

        $rows = $this->csv($this->actingAs($this->user)->get(route('documents.export', [$document, 'csv'])));
        $byLabel = collect(array_slice($rows, 1))->keyBy(0);

        $this->assertSame("'=HYPERLINK(\"http://evil\",\"x\")", $byLabel['Поставщик'][1]);
        $this->assertSame("'+1-1", $byLabel['Покупатель'][1]);
        // простое число формулой не является: остаётся числом в Excel
        $this->assertSame('-5', $byLabel['Номер счёта'][1]);
    }

    public function test_exports_are_rate_limited(): void
    {
        $document = $this->processedInvoice($this->user);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->user)->get(route('documents.export', [$document, 'json']))->assertOk();
        }
        $this->actingAs($this->user)->get(route('documents.export', [$document, 'json']))->assertStatus(429);
    }

    public function test_selection_export_does_not_query_per_document(): void
    {
        $template = $this->invoiceTemplate();
        for ($i = 0; $i < 5; $i++) {
            $this->processedInvoice($this->user, ['number' => (string) $i]);
        }

        DB::enableQueryLog();
        $this->actingAs($this->user)->get(route('documents.export-selection', ['format' => 'json', 'template' => $template->id]))->streamedContent();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // постоянное число запросов, а не по запросу на каждый документ
        $this->assertLessThan(25, $queries);
    }

    public function test_document_is_exported_to_json(): void
    {
        $document = $this->processedInvoice($this->user, ['total' => '15000.00']);

        $json = $this->actingAs($this->user)->get(route('documents.export', [$document, 'json']))->assertOk()->streamedContent();
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('счёт.pdf', $data['document']['file_name']);
        $this->assertSame('Счёт на оплату', $data['document']['template']);
        $this->assertFalse($data['document']['needs_review']);
        $total = collect($data['fields'])->firstWhere('name', 'total');
        $this->assertSame(['name' => 'total', 'label' => 'Сумма к оплате', 'value' => '15000.00', 'needs_review' => false,
            'is_corrected' => false, 'original_value' => null, 'source' => 'rule', 'confidence' => 0.9, 'issues' => []], $total);
        // кириллица без \u-экранирования — файл читается глазами
        $this->assertStringContainsString('Сумма к оплате', $json);
    }

    public function test_export_is_recorded_and_audited(): void
    {
        $document = $this->processedInvoice($this->user);

        $this->actingAs($this->user)->get(route('documents.export', [$document, 'csv']))->streamedContent();

        $export = Export::firstOrFail();
        $this->assertSame($document->latestResult->id, $export->result_id);
        $this->assertSame('csv', $export->format);
        $this->assertSame('download', $export->destination);
        $this->assertTrue(AuditLog::where('action', 'document_exported')->where('user_id', $this->user->id)->exists());
    }

    public function test_other_users_and_unfinished_documents_cannot_be_exported(): void
    {
        $foreign = $this->processedInvoice(User::factory()->create());
        $processing = $this->processedInvoice($this->user, attributes: ['status' => DocumentStatus::Processing]);

        $this->actingAs($this->user)->get(route('documents.export', [$foreign, 'csv']))->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->get(route('documents.export', [$foreign, 'csv']))->assertNotFound();
        $this->actingAs($this->user)->get(route('documents.export', [$processing, 'csv']))->assertNotFound();
        $this->actingAs($this->user)->get(route('documents.export', [$processing, 'xml']))->assertNotFound();
        $this->assertSame(0, Export::count());
    }

    public function test_selection_is_exported_to_csv_with_one_row_per_document(): void
    {
        $template = $this->invoiceTemplate();
        $this->processedInvoice($this->user, ['number' => '1', 'total' => '100.00'], ['file_name' => 'первый.pdf', 'uploaded_at' => now()->subDay()]);
        $this->processedInvoice($this->user, ['number' => '2', 'total' => '200.00'], ['file_name' => 'второй.pdf', 'needs_review' => true]);
        $this->processedInvoice($this->user, attributes: ['file_name' => 'в работе.pdf', 'status' => DocumentStatus::Processing]);
        $this->processedInvoice(User::factory()->create(), attributes: ['file_name' => 'чужой.pdf']);

        $response = $this->actingAs($this->user)
            ->get(route('documents.export-selection', ['format' => 'csv', 'template' => $template->id]))
            ->assertOk();
        $rows = $this->csv($response);

        $this->assertSame(
            ['Файл', 'Загружен', 'Требует проверки', ...$template->fields->pluck('label')->all()],
            $rows[0],
        );
        // только свои обработанные документы этого шаблона, новые сверху
        $this->assertSame(['второй.pdf', 'первый.pdf'], array_column(array_slice($rows, 1), 0));
        $numberColumn = array_search('Номер счёта', $rows[0], true);
        $this->assertSame(['2', '1'], array_column(array_slice($rows, 1), $numberColumn));
        $this->assertSame('да', $rows[1][2]);
        $this->assertSame(2, Export::count());
    }

    public function test_selection_respects_list_filters(): void
    {
        $template = $this->invoiceTemplate();
        $this->processedInvoice($this->user, attributes: ['file_name' => 'проверить.pdf', 'needs_review' => true]);
        $this->processedInvoice($this->user, attributes: ['file_name' => 'готово.pdf']);

        $rows = $this->csv($this->actingAs($this->user)->get(route('documents.export-selection', [
            'format' => 'csv', 'template' => $template->id, 'review' => 1,
        ])));

        $this->assertSame(['проверить.pdf'], array_column(array_slice($rows, 1), 0));
    }

    public function test_selection_is_exported_to_json_without_template_filter(): void
    {
        $this->processedInvoice($this->user, ['number' => '1'], ['file_name' => 'а.pdf']);
        $this->processedInvoice($this->user, ['number' => '2'], ['file_name' => 'б.pdf']);

        $data = json_decode($this->actingAs($this->user)
            ->get(route('documents.export-selection', ['format' => 'json']))->streamedContent(), true);

        $this->assertCount(2, $data);
        $this->assertEqualsCanonicalizing(['а.pdf', 'б.pdf'], array_column(array_column($data, 'document'), 'file_name'));
    }

    public function test_selection_csv_requires_template_and_is_limited(): void
    {
        $this->actingAs($this->user)->get(route('documents.export-selection', ['format' => 'csv']))
            ->assertStatus(422);

        config(['documents.max_export_documents' => 1]);
        $template = $this->invoiceTemplate();
        $this->processedInvoice($this->user);
        $this->processedInvoice($this->user);

        $this->actingAs($this->user)->get(route('documents.export-selection', ['format' => 'csv', 'template' => $template->id]))
            ->assertStatus(422);
    }
}
