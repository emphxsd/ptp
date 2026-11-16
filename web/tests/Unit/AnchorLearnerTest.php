<?php

namespace Tests\Unit;

use App\Enums\DataType;
use App\Support\AnchorLearner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Подпись слева от исправленного значения — кандидат в слова-якоря поля. */
class AnchorLearnerTest extends TestCase
{
    /** @return array<string, array{string, string, DataType, string|null}> */
    public static function cases(): array
    {
        return [
            'сумма с пробелами и запятой' => ["Счёт № 15\nИтого к оплате: 15 000,00 руб.", '15000.00', DataType::Amount, 'Итого к оплате'],
            'сумма без копеек в тексте' => ['Всего к оплате — 1 250', '1250.00', DataType::Amount, 'Всего к оплате'],
            'дата в формате дд.мм.гггг' => ["Дата счёта: 01.03.2025\n", '2025-03-01', DataType::Date, 'Дата счёта'],
            'после запятой — своя подпись' => ['Поставщик: ООО «Ромашка», ИНН 7707083893', '7707083893', DataType::Inn, 'ИНН'],
            'строка' => ['Город составления: Москва', 'Москва', DataType::String, 'Город составления'],
            'не больше трёх слов' => ['Общая сумма всех работ по договору: 500,00', '500.00', DataType::Amount, 'работ по договору'],
            'слова с цифрами обрывают подпись' => ['Счёт на оплату № 15 от 01.03.2025', '2025-03-01', DataType::Date, null],
            'нет подписи слева' => ["Ромашка\n15 000,00", '15000.00', DataType::Amount, null],
            'значения нет в тексте' => ['Итого: 100,00', '15000.00', DataType::Amount, null],
            'не путает 15 000 с 115 000' => ['Аванс 115 000,00; К оплате 15 000,00', '15000.00', DataType::Amount, 'К оплате'],
        ];
    }

    #[DataProvider('cases')]
    public function test_anchor_is_the_label_left_of_the_value(string $text, string $value, DataType $type, ?string $expected): void
    {
        $this->assertSame($expected, AnchorLearner::anchorFor($text, $value, $type));
    }
}
