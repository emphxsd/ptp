<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Системные шаблоны для четырёх типов документов. Данные — database/data/system_templates.json;
 * это те же шаблоны, на которых измерено качество извлечения (processor/evaluation/templates.py,
 * совпадение проверяет тест processor/tests/test_web_system_templates.py).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->templates() as $item) {
                $now = now();
                $templateId = DB::table('templates')->insertGetId([
                    'user_id' => null,
                    'document_type_id' => DB::table('document_types')->where('name', $item['document_type'])->value('id'),
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('template_fields')->insert(array_map(fn (array $field, int $index) => [
                    'template_id' => $templateId,
                    'name' => $field['name'],
                    'label' => $field['label'],
                    'data_type' => $field['data_type'],
                    'is_required' => $field['is_required'] ?? false,
                    'keywords' => isset($field['keywords']) ? json_encode($field['keywords'], JSON_UNESCAPED_UNICODE) : null,
                    'regex_pattern' => $field['regex_pattern'] ?? null,
                    'ner_label' => $field['ner_label'] ?? null,
                    'llm_hint' => $field['llm_hint'] ?? null,
                    'validation' => isset($field['validation']) ? json_encode($field['validation'], JSON_UNESCAPED_UNICODE) : null,
                    'sort_order' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $item['fields'], array_keys($item['fields'])));
            }
        });
    }

    public function down(): void
    {
        DB::table('templates')
            ->where('is_system', true)
            ->whereIn('name', array_column($this->templates(), 'name'))
            ->delete();
    }

    /** @return list<array{key: string, document_type: string, name: string, description: string|null, fields: list<array<string, mixed>>}> */
    private function templates(): array
    {
        return json_decode((string) file_get_contents(database_path('data/system_templates.json')), true, flags: JSON_THROW_ON_ERROR);
    }
};
