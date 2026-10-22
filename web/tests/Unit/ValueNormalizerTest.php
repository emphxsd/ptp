<?php

namespace Tests\Unit;

use App\Enums\DataType;
use App\Support\ValueNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Ручное исправление приводится к тому же виду, что значения сервиса (processor/app/fields/normalizers.py). */
class ValueNormalizerTest extends TestCase
{
    /** @return array<string, array{DataType, string, string}> */
    public static function valid(): array
    {
        return [
            'дата ISO' => [DataType::Date, '2025-03-01', '2025-03-01'],
            'дата через точку' => [DataType::Date, '1.3.2025', '2025-03-01'],
            'дата с коротким годом' => [DataType::Date, '01.03.25', '2025-03-01'],
            'дата словами' => [DataType::Date, '1 марта 2025 г.', '2025-03-01'],
            'сумма с пробелами и запятой' => [DataType::Amount, '1 000,5', '1000.50'],
            'сумма с неразрывным пробелом' => [DataType::Amount, "15\u{00A0}000", '15000.00'],
            'сумма с копейками через дефис' => [DataType::Amount, '15 000-50', '15000.50'],
            'ИНН юрлица' => [DataType::Inn, ' 7707083893 ', '7707083893'],
            'ИНН физлица' => [DataType::Inn, '500100732259', '500100732259'],
            'КПП' => [DataType::Kpp, '7707 01 001', '770701001'],
            'БИК' => [DataType::Bik, '044525225', '044525225'],
            'телефон с восьмёркой' => [DataType::Phone, '8 (495) 123-45-67', '+74951234567'],
            'телефон без кода страны' => [DataType::Phone, '4951234567', '+74951234567'],
            'e-mail' => [DataType::Email, ' Ivan@Example.RU ', 'ivan@example.ru'],
            'ФИО с инициалами' => [DataType::Person, 'ИВАНОВ И.И.', 'Иванов И. И.'],
            'ФИО полностью' => [DataType::Person, 'Иванов  Иван   Иванович', 'Иванов Иван Иванович'],
            'организация полностью' => [DataType::Organization, 'общество с ограниченной ответственностью "Ромашка"', 'ООО "Ромашка"'],
            'строка с лишними пробелами' => [DataType::String, "  Москва \n ", 'Москва'],
            'строка без невидимых символов' => [DataType::String, "Мос\u{200B}ква\u{202E}", 'Москва'],
            'сумма с ведущими нулями' => [DataType::Amount, '007', '7.00'],
            'сумма меньше рубля' => [DataType::Amount, '000.5', '0.50'],
            'дата с именительным падежом' => [DataType::Date, '1 май 2025', '2025-05-01'],
            'ФИО: инициалы перед фамилией' => [DataType::Person, 'И.И. Иванов', 'Иванов И. И.'],
            'ИП' => [DataType::Organization, 'индивидуальный предприниматель ПЕТРОВ П.П.', 'ИП Петров П. П.'],
            'организация с пробелами в кавычках' => [DataType::Organization, 'ООО  " Ромашка "', 'ООО "Ромашка"'],
            'номер документа' => [DataType::Number, ' В-5 ', 'В-5'],
        ];
    }

    #[DataProvider('valid')]
    public function test_value_is_normalized(DataType $type, string $input, string $expected): void
    {
        $this->assertSame($expected, ValueNormalizer::normalize($type, $input));
    }

    /** @return array<string, array{DataType, string}> */
    public static function invalid(): array
    {
        return [
            'несуществующая дата' => [DataType::Date, '31.02.2025'],
            'дата не в формате' => [DataType::Date, 'вчера'],
            'год вне диапазона' => [DataType::Date, '01.01.1800'],
            'сумма с буквами' => [DataType::Amount, '15 тыс.'],
            'сумма с тремя знаками' => [DataType::Amount, '10.005'],
            'ИНН с неверной контрольной суммой' => [DataType::Inn, '7707083894'],
            'ИНН неверной длины' => [DataType::Inn, '77070838'],
            'КПП неверной длины' => [DataType::Kpp, '77070100'],
            'БИК не банка РФ' => [DataType::Bik, '123456789'],
            'телефон короткий' => [DataType::Phone, '12-34'],
            'e-mail без домена' => [DataType::Email, 'ivan@'],
            'пустая строка' => [DataType::String, '   '],
            'месяц не словарный' => [DataType::Date, '1 марте 2025'],
            'месяц обрезан' => [DataType::Date, '1 ма 2025'],
            'ИНН с кодом региона 00' => [DataType::Inn, '0000000000'],
            'ИНН-12 с неверной контрольной цифрой' => [DataType::Inn, '500100732258'],
            'слишком длинное значение' => [DataType::String, str_repeat('а', ValueNormalizer::MAX_LENGTH + 1)],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_value_is_rejected(DataType $type, string $input): void
    {
        $this->assertNull(ValueNormalizer::normalize($type, $input));
    }

    public function test_surname_glued_to_initials_is_not_reformatted(): void
    {
        // как в сервисе: между фамилией и инициалами нужен пробел; иначе значение остаётся как введено
        $this->assertSame('ИвановИ.И.', ValueNormalizer::normalize(DataType::Person, 'ИвановИ.И.'));
    }

    public function test_hint_describes_expected_format(): void
    {
        $this->assertStringContainsString('ДД.ММ.ГГГГ', ValueNormalizer::hint(DataType::Date));
        $this->assertStringContainsString('10 или 12', ValueNormalizer::hint(DataType::Inn));
    }
}
