<?php

namespace Tests\Feature;

use App\Enums\DataType;
use App\Enums\DocumentStatus;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\Export;
use App\Models\ExtractedValue;
use App\Models\ExtractionResult;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_tables_from_the_schema_exist(): void
    {
        foreach (['roles', 'users', 'audit_log', 'document_types', 'templates', 'template_fields', 'documents',
            'extraction_results', 'extracted_values', 'exports'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "нет таблицы {$table}");
        }
    }

    public function test_document_types_are_seeded(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Счёт на оплату', 'Акт выполненных работ', 'Договор', 'Резюме'],
            DocumentType::pluck('name')->all(),
        );
    }

    public function test_template_with_fields_keeps_json_settings(): void
    {
        $template = $this->template();

        $field = $template->fields()->create([
            'name' => 'total',
            'label' => 'Сумма',
            'data_type' => DataType::Amount,
            'is_required' => true,
            'keywords' => ['Итого', 'Всего к оплате'],
            'validation' => ['min_value' => '1'],
            'sort_order' => 1,
        ]);

        $fresh = TemplateField::findOrFail($field->id);
        $this->assertSame(DataType::Amount, $fresh->data_type);
        $this->assertSame(['Итого', 'Всего к оплате'], $fresh->keywords);
        $this->assertSame(['min_value' => '1'], $fresh->validation);
    }

    public function test_full_processing_chain_and_cascade_delete(): void
    {
        $template = $this->template();
        $field = $template->fields()->create(['name' => 'inn', 'label' => 'ИНН', 'data_type' => DataType::Inn]);
        $document = $template->user->documents()->make([
            'template_id' => $template->id,
            'file_name' => 'счёт.pdf',
            'file_format' => 'pdf',
        ]);
        $document->forceFill(['storage_key' => 'documents/abc.pdf', 'status' => DocumentStatus::Done])->save();
        $result = $document->results()->create(['status' => 'success', 'llm_used' => false, 'processing_ms' => 120]);
        $result->values()->create([
            'field_id' => $field->id, 'value' => '7707083893', 'source' => 'rule', 'confidence' => 0.95,
            'issues' => [], 'alternatives' => [],
        ]);
        $result->exports()->create(['format' => 'json', 'destination' => 'exports/1.json']);

        $this->assertSame('7707083893', $document->latestResult->values->first()->value);
        $this->assertSame(DocumentStatus::Done, $document->fresh()->status);

        $document->delete();

        $this->assertSame(0, ExtractionResult::count());
        $this->assertSame(0, ExtractedValue::count());
        $this->assertSame(0, Export::count());
    }

    public function test_deleting_user_removes_own_templates_and_documents_but_keeps_audit(): void
    {
        $template = $this->template();
        AuditLog::record('login', $template->user, ['ip' => '127.0.0.1']);

        $template->user->delete();

        $this->assertSame(0, Template::count());
        $this->assertSame(1, AuditLog::count());
    }

    public function test_system_template_has_no_owner(): void
    {
        $template = Template::forceCreate([
            'document_type_id' => DocumentType::firstOrFail()->id,
            'name' => 'Счёт (системный)',
            'is_system' => true,
        ]);

        $this->assertNull($template->user_id);
        $this->assertTrue($template->is_system);
    }

    private function template(): Template
    {
        return User::factory()->create()->templates()->create([
            'document_type_id' => DocumentType::firstOrFail()->id,
            'name' => 'Мой счёт',
        ]);
    }
}
