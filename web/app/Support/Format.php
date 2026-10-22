<?php

namespace App\Support;

/** Числа для интерфейса на русском: десятичная запятая и русские единицы. */
final class Format
{
    private const UNITS = ['Б', 'КБ', 'МБ', 'ГБ'];

    private const BYTES_STEP = 1024;

    // число и единица не разрываются переносом строки
    private const NBSP = "\u{00A0}";

    /** 395776 → «386,5 КБ»; 1048575 → «1,0 МБ» (после округления до десятых это уже мегабайт). */
    public static function fileSize(int $bytes): string
    {
        $size = (float) max(0, $bytes);
        $unit = 0;
        while ($unit < count(self::UNITS) - 1 && round($size, $unit === 0 ? 0 : 1) >= self::BYTES_STEP) {
            $size /= self::BYTES_STEP;
            $unit++;
        }

        return number_format($size, $unit === 0 ? 0 : 1, ',', '').self::NBSP.self::UNITS[$unit];
    }
}
