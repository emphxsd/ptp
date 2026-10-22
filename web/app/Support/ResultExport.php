<?php

namespace App\Support;

use App\Models\Document;
use App\Models\ExtractedValue;
use App\Models\ExtractionResult;
use App\Models\Template;
use App\Models\TemplateField;
use RuntimeException;

/**
 * Результат обработки в CSV (для Excel) и JSON (для других систем).
 * CSV: UTF-8 с BOM и разделитель «;» — так его без настройки открывает Excel с русской локалью;
 * ячейки, которые Excel принял бы за формулу (=, +, -, @), экранируются апострофом.
 */
final class ResultExport
{
    public const FORMATS = ['csv', 'json'];

    public const BOM = "\xEF\xBB\xBF";

    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    // простое число (-5, +7, 15000.00) формулой не является — остаётся числом в Excel
    private const PLAIN_NUMBER = '/^[+-]?\d+(?:[.,]\d+)?$/D';

    private const SOURCES = ['rule' => 'правила', 'ner' => 'NER', 'llm' => 'LLM'];

    /** @return array{document: array<string, mixed>, fields: list<array<string, mixed>>} */
    public static function documentData(Document $document, ExtractionResult $result): array
    {

        return [
            'document' => [
                'id' => $document->id,
                'file_name' => $document->file_name,
                'template' => $document->template?->name,
                'uploaded_at' => $document->uploaded_at->toIso8601String(),
                'needs_review' => $result->needs_review,
            ],
            'fields' => array_values($result->values->map(fn (ExtractedValue $value) => [
                'name' => $value->field_name,
                'label' => self::label($value),
                'value' => $value->value,
                'needs_review' => $value->needs_review,
                'is_corrected' => $value->is_corrected,
                'original_value' => $value->original_value,
                'source' => $value->source,
                'confidence' => round($value->confidence, 2),
                'issues' => $value->issues ?? [],
            ])->all()),
        ];
    }

    /** @return list<list<string>> строка на поле: название, значение, проверка, исправление, источник, уверенность */
    public static function documentRows(ExtractionResult $result): array
    {
        $rows = [['Поле', 'Значение', 'Требует проверки', 'Исправлено вручную', 'Источник', 'Уверенность']];
        foreach ($result->values as $value) {
            $rows[] = [
                self::label($value),
                (string) $value->value,
                $value->needs_review ? 'да' : 'нет',
                $value->is_corrected ? 'да' : 'нет',
                $value->is_corrected ? 'вручную' : (self::SOURCES[$value->source] ?? ''),
                $value->value === null ? '' : number_format($value->confidence, 2, '.', ''),
            ];
        }

        return $rows;
    }

    /**
     * Заголовок выборки: файл, дата загрузки, проверка и по столбцу на поле шаблона.
     *
     * @return list<string>
     */
    public static function selectionHeader(Template $template): array
    {
        $labels = $template->fields->map(fn (TemplateField $field): string => $field->label)->all();

        return ['Файл', 'Загружен', 'Требует проверки', ...array_values($labels)];
    }

    /** @return list<string> строка выборки для одного документа: значения в порядке полей шаблона */
    public static function selectionRow(Template $template, Document $document, ExtractionResult $result): array
    {
        $byField = $result->values->keyBy('field_id');
        $byName = $result->values->keyBy('field_name');

        return [
            $document->file_name,
            $document->uploaded_at->timezone(config('app.display_timezone'))->format('d.m.Y H:i'),
            $result->needs_review ? 'да' : 'нет',
            ...$template->fields->map(fn (TemplateField $field) => (string) ($byField->get($field->id) ?? $byName->get($field->name))?->value)->all(),
        ];
    }

    /**
     * Строки CSV в ответ (php://output): BOM, затем строки с «;» и экранированием формул.
     *
     * @param  iterable<list<string>>  $rows
     */
    public static function outputCsv(iterable $rows): void
    {
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Не удалось открыть поток ответа для CSV.');
        }
        fwrite($output, self::BOM);
        foreach ($rows as $row) {
            fputcsv($output, array_map(self::cell(...), $row), ';', '"', '');
        }
        fclose($output);
    }

    /**
     * JSON-массив в ответ по одному элементу — без сборки всей выборки в памяти.
     *
     * @param  iterable<mixed>  $items
     */
    public static function outputJsonList(iterable $items): void
    {
        echo '[';
        $separator = "\n";
        foreach ($items as $item) {
            echo $separator, self::json($item);
            $separator = ",\n";
        }
        echo "\n]";
    }

    public static function json(mixed $data): string
    {
        // заголовки ответа уже отправлены, поэтому битый UTF-8 заменяется, а не обрывает выгрузку
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], self::FORMULA_START, true) && ! preg_match(self::PLAIN_NUMBER, $value)
            ? "'".$value
            : $value;
    }

    // поле шаблона загружено заранее (values.field) — без запроса на каждое значение; поле могли удалить
    private static function label(ExtractedValue $value): string
    {
        return $value->field->label ?? $value->field_name;
    }
}
