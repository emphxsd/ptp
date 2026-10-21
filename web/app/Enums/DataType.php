<?php

namespace App\Enums;

/** Типы полей — те же, что понимает сервис обработки (processor/app/schemas.py, DataType). */
enum DataType: string
{
    case String = 'string';
    case Date = 'date';
    case Amount = 'amount';
    case Number = 'number';
    case Inn = 'inn';
    case Kpp = 'kpp';
    case Bik = 'bik';
    case Phone = 'phone';
    case Email = 'email';
    case Person = 'person';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::String => 'Строка',
            self::Date => 'Дата',
            self::Amount => 'Сумма',
            self::Number => 'Номер документа',
            self::Inn => 'ИНН',
            self::Kpp => 'КПП',
            self::Bik => 'БИК',
            self::Phone => 'Телефон',
            self::Email => 'E-mail',
            self::Person => 'ФИО',
            self::Organization => 'Организация',
        };
    }
}
