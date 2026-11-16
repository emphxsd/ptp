<?php

namespace Tests\Unit;

use App\Support\ArchiveQuery;
use PHPUnit\Framework\TestCase;

/** Запрос к архиву на естественном языке → фильтры по извлечённым полям. */
class ArchiveQueryTest extends TestCase
{
    public function test_example_from_the_task(): void
    {
        $query = ArchiveQuery::parse('все договоры с ООО Ромашка за 2025 год дороже 100 тыс.');

        $this->assertSame('договор', $query->type);
        $this->assertSame('Ромашка', $query->organization);
        $this->assertSame(['2025-01-01', '2025-12-31'], [$query->dateFrom, $query->dateTo]);
        $this->assertSame('100000', $query->amountMin);
        $this->assertNull($query->amountMax);
        $this->assertSame([], $query->words);
    }

    public function test_inn_upper_amount_and_quoted_organization(): void
    {
        $query = ArchiveQuery::parse('счета от АО «Лотос» ИНН 7707083893 до 50 000 руб');

        $this->assertSame('счет', $query->type);
        $this->assertSame('Лотос', $query->organization);
        $this->assertSame('7707083893', $query->inn);
        $this->assertSame('50000', $query->amountMax);
    }

    public function test_date_range_and_millions(): void
    {
        $query = ArchiveQuery::parse('акты с 01.03.2025 по 31.03.2025 свыше 1,5 млн');

        $this->assertSame('акт', $query->type);
        $this->assertSame(['2025-03-01', '2025-03-31'], [$query->dateFrom, $query->dateTo]);
        $this->assertSame('1500000', $query->amountMin);
    }

    public function test_remaining_words_search_all_values(): void
    {
        $query = ArchiveQuery::parse('резюме Иванов Москва');

        $this->assertSame('резюме', $query->type);
        $this->assertSame(['Иванов', 'Москва'], $query->words);
    }

    public function test_description_lists_understood_filters(): void
    {
        $chips = ArchiveQuery::parse('договоры с ООО Ромашка за 2025 год дороже 100 тыс.')->describe();

        $this->assertSame(['Тип: договор', 'Организация: Ромашка', 'Дата: 01.01.2025 — 31.12.2025', 'Сумма от 100 000'], $chips);
    }

    public function test_empty_query(): void
    {
        $this->assertTrue(ArchiveQuery::parse('  ')->isEmpty());
    }
}
