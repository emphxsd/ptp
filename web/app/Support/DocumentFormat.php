<?php

namespace App\Support;

/**
 * Формат загруженного файла по содержимому (сигнатуре), а не по имени — как detect_format в сервисе обработки
 * (processor/app/extraction/file_types.py). Сервис проверяет файл ещё раз и подробнее (структура DOCX, zip-бомбы).
 */
final class DocumentFormat
{
    private const SIGNATURES = [
        'png' => "\x89PNG\r\n\x1a\n",
        'jpg' => "\xff\xd8\xff",
        'docx' => "PK\x03\x04",
    ];

    private const PDF_SIGNATURE = '%PDF-';

    // стандарт PDF допускает «мусор» перед заголовком в пределах первых 1024 байт
    private const PDF_HEADER_SEARCH_LIMIT = 1024;

    // расширение имени → формат; .jpeg — тот же JPG
    private const EXTENSIONS = ['pdf' => 'pdf', 'docx' => 'docx', 'png' => 'png', 'jpg' => 'jpg', 'jpeg' => 'jpg'];

    /** pdf, docx, png или jpg — если содержимое совпадает с расширением имени; иначе null. */
    public static function detect(string $path, string $fileName): ?string
    {
        $expected = self::EXTENSIONS[mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION))] ?? null;
        $head = $expected === null ? false : @file_get_contents($path, length: self::PDF_HEADER_SEARCH_LIMIT);
        if ($head === false || $head === '') {
            return null;
        }

        return self::fromHead($head) === $expected ? $expected : null;
    }

    private static function fromHead(string $head): ?string
    {
        // сначала точные префиксы, PDF — последним: файл-полиглот с %PDF- внутри не будет принят за PDF
        foreach (self::SIGNATURES as $format => $signature) {
            if (str_starts_with($head, $signature)) {
                return $format;
            }
        }

        return str_contains($head, self::PDF_SIGNATURE) ? 'pdf' : null;
    }
}
