<?php

namespace App\Support;

use App\Enums\DataType;
use App\Models\Document;
use App\Models\Template;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Фильтры архива по извлечённым значениям (последний результат каждого документа). Каждое условие — отдельное
 * «есть значение поля такого типа, что …»: организация, сумма и дата могут быть в разных полях документа.
 */
final class ArchiveSearch
{
    // PostgreSQL может вычислить CAST раньше фильтра по типу поля — приводятся только строки вида «15000.00»
    private const PG_AMOUNT = "(CASE WHEN ev.value ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(ev.value AS NUMERIC) END)";

    private const SQL_AMOUNT = 'CAST(ev.value AS DECIMAL(20,2))';

    /**
     * @param  Builder<Document>  $documents  документы пользователя
     * @param  Collection<int, Template>  $templates  шаблоны, видимые пользователю (для фильтра по типу)
     * @return Builder<Document>
     */
    public static function apply(Builder $documents, ArchiveQuery $query, Collection $templates): Builder
    {
        $pgsql = $documents->getModel()->getConnection()->getDriverName() === 'pgsql';

        if ($query->type !== null) {
            $ids = $templates->filter(fn (Template $t) => str_starts_with(self::fold($t->name), $query->type))->modelKeys();
            $ids === [] ? $documents->whereRaw('1 = 0') : $documents->whereIn('documents.template_id', $ids);
        }
        if ($query->organization !== null) {
            self::hasValue($documents, [DataType::Organization], fn (QueryBuilder $q) => self::contains($q, $pgsql, $query->organization));
        }
        if ($query->inn !== null) {
            self::hasValue($documents, [DataType::Inn], fn (QueryBuilder $q) => $q->where('ev.value', $query->inn));
        }
        if ($query->dateFrom !== null) {
            self::hasValue($documents, [DataType::Date], fn (QueryBuilder $q) => $q
                ->where('ev.value', '>=', $query->dateFrom)->where('ev.value', '<=', (string) $query->dateTo));
        }
        if ($query->amountMin !== null) {
            self::hasValue($documents, [DataType::Amount], fn (QueryBuilder $q) => $pgsql
                ? $q->whereRaw(self::PG_AMOUNT.' >= ?', [$query->amountMin])
                : $q->whereRaw(self::SQL_AMOUNT.' >= ?', [$query->amountMin]));
        }
        if ($query->amountMax !== null) {
            self::hasValue($documents, [DataType::Amount], fn (QueryBuilder $q) => $pgsql
                ? $q->whereRaw(self::PG_AMOUNT.' <= ?', [$query->amountMax])
                : $q->whereRaw(self::SQL_AMOUNT.' <= ?', [$query->amountMax]));
        }
        foreach ($query->words as $word) {
            self::hasValue($documents, null, fn (QueryBuilder $q) => self::contains($q, $pgsql, $word));
        }

        return $documents;
    }

    /**
     * @param  Builder<Document>  $documents
     * @param  list<DataType>|null  $types  null — значение любого поля
     */
    private static function hasValue(Builder $documents, ?array $types, Closure $condition): void
    {
        $documents->whereExists(function (QueryBuilder $q) use ($types, $condition): void {
            $q->select(DB::raw(1))
                ->from('extracted_values as ev')
                ->join('extraction_results as er', 'er.id', '=', 'ev.result_id')
                ->leftJoin('template_fields as tf', 'tf.id', '=', 'ev.field_id')
                ->whereColumn('er.document_id', 'documents.id')
                ->whereRaw('er.id = (select max(r2.id) from extraction_results r2 where r2.document_id = documents.id)')
                ->whereNotNull('ev.value')
                ->when($types !== null, fn (QueryBuilder $q) => $q->whereIn('tf.data_type', array_map(fn (DataType $t) => $t->value, (array) $types)))
                ->where($condition);
        });
    }

    /** Подстрока без учёта регистра в PostgreSQL; «%» и «_» во вводе — обычные символы. */
    private static function contains(QueryBuilder $query, bool $pgsql, string $term): void
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $pgsql
            ? $query->whereRaw("ev.value ilike ? escape '\\'", [$pattern])
            : $query->whereRaw("ev.value like ? escape '\\'", [$pattern]);
    }

    private static function fold(string $text): string
    {
        return str_replace('ё', 'е', mb_strtolower($text));
    }
}
