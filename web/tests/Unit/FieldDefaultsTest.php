<?php

namespace Tests\Unit;

use App\Enums\DataType;
use App\Support\FieldDefaults;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Системное имя и тип поля по его названию — пользователю не нужно знать ни того, ни другого. */
class FieldDefaultsTest extends TestCase
{
    /** @return array<string, array{string, list<string>, string}> */
    public static function names(): array
    {
        return [
            'транслитерация' => ['Наименование выполненных работ', [], 'naimenovanie_vypolnennyh_rabot'],
            'знаки препинания' => ['Сумма к оплате, руб.', [], 'summa_k_oplate_rub'],
            'занятое имя' => ['ИНН', ['inn'], 'inn_2'],
            'занято несколько' => ['ИНН', ['inn', 'inn_2'], 'inn_3'],
            'начинается с цифры' => ['123 поле', [], 'field_123_pole'],
            'без букв и цифр' => ['!!!', [], 'field'],
            'занято и запасное' => ['!!!', ['field'], 'field_2'],
        ];
    }

    /** @param  list<string>  $taken */
    #[DataProvider('names')]
    public function test_system_name_is_generated_from_label(string $label, array $taken, string $expected): void
    {
        $this->assertSame($expected, FieldDefaults::systemName($label, $taken));
    }

    public function test_long_label_gives_valid_name_within_limit(): void
    {
        $name = FieldDefaults::systemName(str_repeat('Очень длинное название поля ', 10), []);

        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/D', $name);
        $this->assertStringEndsNotWith('_', $name);
    }

    public function test_suffix_keeps_long_name_within_limit(): void
    {
        $base = FieldDefaults::systemName(str_repeat('поле ', 30), []);

        $name = FieldDefaults::systemName(str_repeat('поле ', 30), [$base]);

        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/D', $name);
        $this->assertStringEndsWith('_2', $name);
    }

    /** @return array<string, array{string, DataType}> */
    public static function types(): array
    {
        return [
            'ИНН' => ['ИНН поставщика', DataType::Inn],
            'КПП' => ['КПП покупателя', DataType::Kpp],
            'БИК' => ['БИК банка', DataType::Bik],
            'e-mail' => ['E-mail кандидата', DataType::Email],
            'электронная почта' => ['Электронная почта', DataType::Email],
            'телефон' => ['Номер телефона', DataType::Phone],
            'дата' => ['Дата рождения кандидата', DataType::Date],
            'дата договора' => ['Дата договора', DataType::Date],
            'сумма' => ['Сумма к оплате', DataType::Amount],
            'стоимость' => ['Стоимость работ', DataType::Amount],
            'НДС' => ['НДС', DataType::Amount],
            'итого' => ['Итого', DataType::Amount],
            'номер документа' => ['Номер договора', DataType::Number],
            'знак номера' => ['№ счёта', DataType::Number],
            'ФИО' => ['ФИО кандидата', DataType::Person],
            'подписант' => ['Подписант со стороны заказчика', DataType::Person],
            'директор' => ['Генеральный директор', DataType::Person],
            'организация' => ['Поставщик', DataType::Organization],
            'заказчик' => ['Заказчик', DataType::Organization],
            'работодатель' => ['Последний работодатель', DataType::Organization],
            'строка' => ['Наименование выполненных работ', DataType::String],
            'адрес' => ['Адрес доставки', DataType::String],
            'условия оплаты — не сумма' => ['Условия оплаты', DataType::String],
            'регистр и ё' => ['ИТОГО К ОПЛАТЕ', DataType::Amount],
            'всего к оплате' => ['Всего к оплате', DataType::Amount],
        ];
    }

    #[DataProvider('types')]
    public function test_data_type_is_guessed_from_label(string $label, DataType $expected): void
    {
        $this->assertSame($expected, FieldDefaults::guessType($label));
    }

    /** @return array<string, array{string, DataType}> */
    public static function values(): array
    {
        return [
            'ИНН 10 цифр' => ['7707083893', DataType::Inn],
            'ИНН 12 цифр' => ['500100732259', DataType::Inn],
            'дата' => ['01.03.2025', DataType::Date],
            'сумма с копейками' => ['15 000,00', DataType::Amount],
            'сумма с рублями' => ['1 250 руб.', DataType::Amount],
            'телефон' => ['+7 (999) 123-45-67', DataType::Phone],
            'e-mail' => ['info@romashka.ru', DataType::Email],
            'организация' => ['ООО «Ромашка»', DataType::Organization],
            'просто текст' => ['Москва', DataType::String],
            'дата с названием месяца' => ['12 мая 2024 года', DataType::Date],
            'дата в кавычках' => ['«07» января 2025 г.', DataType::Date],
            'дата без «года»' => ['1 марта 2025', DataType::Date],
            'сумма с копейками через дефис' => ['563 415-00', DataType::Amount],
            'номер с дефисом — не сумма' => ['2024-65', DataType::String],
            'месяц без года — не дата' => ['12 мая', DataType::String],
        ];
    }

    #[DataProvider('values')]
    public function test_data_type_is_guessed_from_value(string $value, DataType $expected): void
    {
        $this->assertSame($expected, FieldDefaults::guessTypeFromValue($value));
    }
}
