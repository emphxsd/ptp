<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Имя файла, которое дал пользователь, — только для показа: без пути, управляющих символов и переворота текста. */
final class FileName
{
    public const MAX_LENGTH = 200;

    // управляющие, невидимые и меняющие направление текста символы (U+202E превращает «fdp.exe» в «exe.pdf» на экране)
    private const UNSAFE = '/[\x00-\x1f\x7f\x{00AD}\x{061C}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';

    public static function clean(string $name, string $fallbackExtension): string
    {
        $base = (string) Str::afterLast(str_replace('\\', '/', $name), '/');
        $base = trim((string) preg_replace(self::UNSAFE, '', $base), " .\t");
        if ($base === '' || ! mb_check_encoding($base, 'UTF-8')) {
            return 'документ.'.$fallbackExtension;
        }
        if (mb_strlen($base) <= self::MAX_LENGTH) {
            return $base;
        }

        // длинное имя укорачивается с сохранением расширения
        $extension = pathinfo($base, PATHINFO_EXTENSION);
        $suffix = $extension === '' ? '' : '.'.mb_substr($extension, 0, 10);

        return mb_substr($base, 0, self::MAX_LENGTH - mb_strlen($suffix)).$suffix;
    }
}
