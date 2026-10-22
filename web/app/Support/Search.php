<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Поиск подстроки: «%» и «_» во вводе — обычные символы, а не шаблоны LIKE. */
final class Search
{
    /**
     * В PostgreSQL — без учёта регистра (ILIKE, в том числе для кириллицы); в SQLite (тесты) LIKE
     * не различает регистр только для латиницы.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $column  имя столбца из кода, не из ввода
     * @return Builder<TModel>
     */
    public static function contains(Builder $query, string $column, string $term): Builder
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->whereRaw("{$column} {$operator} ? escape '\\'", [$pattern]);
    }
}
