<?php

namespace App\Support;

use App\Enums\DataType;

/**
 * Ручное исправление значения приводится к тому же виду, что выдаёт сервис обработки
 * (processor/app/fields/normalizers.py): дата — ГГГГ-ММ-ДД, сумма — 15000.00, телефон — +7XXXXXXXXXX.
 * null — значение не подходит к типу поля.
 */
final class ValueNormalizer
{
    public const MAX_LENGTH = 1000;

    private const MIN_YEAR = 1900;

    private const MAX_YEAR = 2100;

    private const TWO_DIGIT_YEAR_BASE = 2000;

    // те же формы, что MONTHS в сервисе: родительный и именительный падеж
    private const MONTHS = [
        'января' => 1, 'январь' => 1, 'февраля' => 2, 'февраль' => 2, 'марта' => 3, 'март' => 3,
        'апреля' => 4, 'апрель' => 4, 'мая' => 5, 'май' => 5, 'июня' => 6, 'июнь' => 6,
        'июля' => 7, 'июль' => 7, 'августа' => 8, 'август' => 8, 'сентября' => 9, 'сентябрь' => 9,
        'октября' => 10, 'октябрь' => 10, 'ноября' => 11, 'ноябрь' => 11, 'декабря' => 12, 'декабрь' => 12,
    ];

    // невидимые и меняющие направление текста символы — как в FileName::UNSAFE
    private const INVISIBLE = '/[\x{00AD}\x{061C}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';

    // полные организационно-правовые формы → сокращения (длинные раньше: «публичное акционерное» до «акционерное»)
    private const LEGAL_FORMS = [
        'общество с ограниченной ответственностью' => 'ООО',
        'публичное акционерное общество' => 'ПАО',
        'непубличное акционерное общество' => 'НАО',
        'закрытое акционерное общество' => 'ЗАО',
        'открытое акционерное общество' => 'ОАО',
        'акционерное общество' => 'АО',
        'автономная некоммерческая организация' => 'АНО',
        'индивидуальный предприниматель' => 'ИП',
    ];

    private const INN10_WEIGHTS = [2, 4, 10, 3, 5, 9, 4, 6, 8];

    private const INN12_WEIGHTS_11 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];

    private const INN12_WEIGHTS_12 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];

    private const SURNAME = '(?:[А-ЯЁ][а-яё]+|[А-ЯЁ]{2,})(?:-(?:[А-ЯЁ][а-яё]+|[А-ЯЁ]{2,}))?';

    public static function normalize(DataType $type, string $raw): ?string
    {
        // предел длины — до разбора: регулярные выражения не гоняются по вводу любого размера
        if (strlen($raw) > self::MAX_LENGTH * 4 || ! mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }
        $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', (string) preg_replace(self::INVISIBLE, '', $raw)));
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $text)) {
            return null;
        }

        return match ($type) {
            DataType::Date => self::date($text),
            DataType::Amount => self::amount($text),
            DataType::Inn => self::inn($text),
            DataType::Kpp => self::matching('/^\d{4}[\dA-Z]{2}\d{3}$/D', str_replace(' ', '', mb_strtoupper($text))),
            DataType::Bik => self::matching('/^04\d{7}$/D', str_replace(' ', '', $text)),
            DataType::Phone => self::phone($text),
            DataType::Email => self::email(mb_strtolower($text)),
            DataType::Person => self::person($text) ?? $text,
            DataType::Organization => self::organization($text),
            DataType::String, DataType::Number => $text,
        };
    }

    /** Подсказка к полю ввода: в каком виде ожидается значение. */
    public static function hint(DataType $type): string
    {
        return match ($type) {
            DataType::Date => 'ДД.ММ.ГГГГ или ГГГГ-ММ-ДД',
            DataType::Amount => 'Число, копейки через запятую или точку: 15 000,50',
            DataType::Inn => '10 или 12 цифр, проверяется контрольная сумма',
            DataType::Kpp => '9 символов',
            DataType::Bik => '9 цифр, начинается с 04',
            DataType::Phone => 'Российский номер: 10 или 11 цифр',
            DataType::Email => 'Адрес электронной почты',
            DataType::Person => 'Фамилия И. О. или полностью',
            DataType::Organization => 'Например, ООО "Ромашка"',
            DataType::String, DataType::Number => 'Текст без переводов строки',
        };
    }

    private static function matching(string $pattern, string $value): ?string
    {
        return preg_match($pattern, $value) ? $value : null;
    }

    private static function email(string $value): ?string
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    private static function date(string $text): ?string
    {
        $text = mb_strtolower(str_replace('"', '', $text));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $text, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4}|\d{2})$/D', $text, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $year += strlen($m[3]) === 2 ? self::TWO_DIGIT_YEAR_BASE : 0;
        } elseif (preg_match('/^(\d{1,2}) ([а-яё]+) (\d{4})(?: ?(?:г\.?|года))?$/uD', $text, $m)) {
            [$day, $month, $year] = [(int) $m[1], self::MONTHS[$m[2]] ?? null, (int) $m[3]];
        } else {
            return null;
        }

        if ($month === null || $year < self::MIN_YEAR || $year > self::MAX_YEAR || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function amount(string $text): ?string
    {
        $text = str_replace([' ', ','], ['', '.'], $text);
        // «15 000-50»: рубли и копейки через дефис
        $text = (string) preg_replace('/^(\d+)-(\d{2})$/D', '$1.$2', $text);
        if (! preg_match('/^(\d{1,18})(?:\.(\d{1,2}))?$/D', $text, $m)) {
            return null;
        }
        $rubles = ltrim($m[1], '0');

        return ($rubles === '' ? '0' : $rubles).'.'.str_pad($m[2] ?? '', 2, '0');
    }

    private static function inn(string $text): ?string
    {
        $inn = str_replace(' ', '', $text);
        // код региона «00» не существует; строка из нулей формально проходит контрольную сумму
        if (! preg_match('/^\d{10}(?:\d{2})?$/D', $inn) || str_starts_with($inn, '00')) {
            return null;
        }
        $digits = array_map(intval(...), str_split($inn));
        $valid = strlen($inn) === 10
            ? self::controlDigit($digits, self::INN10_WEIGHTS) === $digits[9]
            : self::controlDigit($digits, self::INN12_WEIGHTS_11) === $digits[10]
                && self::controlDigit($digits, self::INN12_WEIGHTS_12) === $digits[11];

        return $valid ? $inn : null;
    }

    /**
     * Контрольная цифра ИНН по алгоритму ФНС.
     *
     * @param  list<int>  $digits
     * @param  list<int>  $weights
     */
    private static function controlDigit(array $digits, array $weights): int
    {
        $sum = 0;
        foreach ($weights as $i => $weight) {
            $sum += $weight * $digits[$i];
        }

        return $sum % 11 % 10;
    }

    private static function phone(string $text): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $text);
        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            return '+7'.substr($digits, 1);
        }

        return strlen($digits) === 10 ? '+7'.$digits : null;
    }

    private static function person(string $text): ?string
    {
        $surname = self::SURNAME;
        // как в сервисе: между фамилией и инициалами пробел обязателен, между инициалами — нет
        if (preg_match("/^({$surname}) ([А-ЯЁ])\\. ?([А-ЯЁ])\\.$/uD", $text, $m)) {
            return self::title($m[1])." {$m[2]}. {$m[3]}.";
        }
        if (preg_match("/^([А-ЯЁ])\\. ?([А-ЯЁ])\\. ?({$surname})$/uD", $text, $m)) {
            return self::title($m[3])." {$m[1]}. {$m[2]}.";
        }

        return null;
    }

    /** ИВАНОВ → Иванов, САЛТЫКОВ-ЩЕДРИН → Салтыков-Щедрин. */
    private static function title(string $surname): string
    {
        return implode('-', array_map(
            fn (string $part) => mb_substr($part, 0, 1).mb_strtolower(mb_substr($part, 1)),
            explode('-', $surname),
        ));
    }

    private static function organization(string $text): string
    {
        $lowered = mb_strtolower($text);
        foreach (self::LEGAL_FORMS as $full => $short) {
            if (str_starts_with($lowered, $full)) {
                $text = $short.mb_substr($text, mb_strlen($full));
                break;
            }
        }
        if (str_starts_with($text, 'ИП ')) {
            $person = self::person(mb_substr($text, 3));

            return $person === null ? $text : 'ИП '.$person;
        }
        // «ФОРМА "Название"»: всё между первой и последней кавычкой — название (как в сервисе)
        if (str_ends_with($text, '"') && ($quote = mb_strpos($text, '"')) !== false) {
            $form = trim(mb_substr($text, 0, $quote));
            $name = trim(mb_substr($text, $quote + 1, -1));
            if ($form !== '' && ! preg_match('/\s/u', $form) && $name !== '') {
                return "{$form} \"{$name}\"";
            }
        }

        return $text;
    }
}
