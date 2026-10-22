<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/** Дата ГГГГ-ММ-ДД из адресной строки или формы — в часовом поясе пользователя (в БД время в UTC). */
final class InputDate
{
    /** null — не строка, не дата или несуществующая дата (2025-02-31 PHP превратил бы в 3 марта). */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.display_timezone'));
        } catch (Throwable) {
            return null;
        }

        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * Границы периода: начало первого дня и конец последнего; перепутанные границы меняются местами.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function period(mixed $from, mixed $to): array
    {
        $start = self::parse($from);
        $end = self::parse($to);
        if ($start !== null && $end !== null && $start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start?->startOfDay(), $end?->endOfDay()];
    }
}
