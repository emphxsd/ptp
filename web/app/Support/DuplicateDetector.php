<?php

namespace App\Support;

use App\Enums\DataType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Возможные дубликаты среди документов пользователя — защита от повторной оплаты.
 * Тот же файл (по SHA-256) — при загрузке; тот же счёт (номер и сумма совпадают, ИНН не противоречит) —
 * после обработки и после исправления значений. Оригиналом считается документ, загруженный раньше.
 */
final class DuplicateDetector
{
    public const SAME_FILE = 'file';

    public const SAME_CONTENT = 'content';

    // сравниваются значения этих типов полей, в каком бы шаблоне они ни были
    private const KEY_TYPES = [DataType::Number, DataType::Amount, DataType::Inn];

    private const MAX_CANDIDATES = 20;

    /** Ранее загруженный тот же файл — при загрузке, до сохранения документа. */
    public function sameFile(int $userId, ?string $sha256): ?Document
    {
        if ($sha256 === null) {
            return null;
        }

        return Document::where('user_id', $userId)->where('sha256', $sha256)->orderBy('id')->first();
    }

    /** Пересчитать пометку «тот же счёт»; пометку «тот же файл» не трогает — она сильнее. */
    public function refreshContent(Document $document): void
    {
        if ($document->duplicate_reason === self::SAME_FILE) {
            return;
        }
        $original = $this->sameContent($document);
        $document->forceFill([
            'duplicate_of_id' => $original?->id,
            'duplicate_reason' => $original === null ? null : self::SAME_CONTENT,
        ])->save();
    }

    private function sameContent(Document $document): ?Document
    {
        $keys = $this->keyValues([$document->id])[$document->id] ?? [];
        $numbers = $keys[DataType::Number->value] ?? [];
        if ($numbers === [] || ($keys[DataType::Amount->value] ?? []) === []) {
            return null; // без номера и суммы счета не сравнить
        }

        $candidates = array_values($this->latestValues()
            ->join('documents', 'documents.id', '=', 'extraction_results.document_id')
            ->where('documents.user_id', $document->user_id)
            ->where('documents.status', DocumentStatus::Done->value)
            ->where('documents.id', '<', $document->id)
            ->where('template_fields.data_type', DataType::Number->value)
            ->whereIn(DB::raw('lower(extracted_values.value)'), $numbers)
            ->orderBy('documents.id')
            ->limit(self::MAX_CANDIDATES)
            ->distinct()
            ->pluck('documents.id')
            ->map(fn (mixed $id) => (int) $id)
            ->all());

        $candidateKeys = $this->keyValues($candidates);
        foreach ($candidates as $id) {
            if ($this->sameInvoice($keys, $candidateKeys[$id] ?? [])) {
                return Document::find($id);
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $a
     * @param  array<string, list<string>>  $b
     */
    private function sameInvoice(array $a, array $b): bool
    {
        $shared = fn (DataType $type) => array_intersect($a[$type->value] ?? [], $b[$type->value] ?? []) !== [];
        $innA = $a[DataType::Inn->value] ?? [];
        $innB = $b[DataType::Inn->value] ?? [];

        // ИНН не найден в одном из документов — не повод считать счета разными
        return $shared(DataType::Number) && $shared(DataType::Amount) && ($innA === [] || $innB === [] || $shared(DataType::Inn));
    }

    /**
     * Ключевые значения последних результатов документов: [document_id => [тип => [значения]]].
     *
     * @param  list<int>  $documentIds
     * @return array<int, array<string, list<string>>>
     */
    private function keyValues(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }
        $rows = $this->latestValues()
            ->whereIn('extraction_results.document_id', $documentIds)
            ->whereIn('template_fields.data_type', array_map(fn (DataType $t) => $t->value, self::KEY_TYPES))
            ->get(['extraction_results.document_id', 'template_fields.data_type', 'extracted_values.value']);

        $keys = [];
        foreach ($rows as $row) {
            $keys[(int) $row->document_id][(string) $row->data_type][] = mb_strtolower(trim((string) $row->value));
        }

        return $keys;
    }

    /** Непустые значения только последних результатов (повторные обработки не смешиваются). */
    private function latestValues(): Builder
    {
        return DB::table('extracted_values')
            ->join('extraction_results', 'extraction_results.id', '=', 'extracted_values.result_id')
            ->join('template_fields', 'template_fields.id', '=', 'extracted_values.field_id')
            ->whereNotNull('extracted_values.value')
            ->whereRaw('extraction_results.id = (select max(r.id) from extraction_results r where r.document_id = extraction_results.document_id)');
    }
}
