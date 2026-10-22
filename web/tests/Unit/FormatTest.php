<?php

namespace Tests\Unit;

use App\Support\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    /** @return array<string, array{int, string}> */
    public static function sizes(): array
    {
        return [
            'ноль' => [0, '0 Б'],
            'байты' => [1023, '1023 Б'],
            'ровно килобайт' => [1024, '1,0 КБ'],
            'типичный скан' => [395_776, '386,5 КБ'],
            // 1023,999 КБ при округлении до десятых — уже мегабайт, а не «1024,0 КБ»
            'граница мегабайта' => [1_048_575, '1,0 МБ'],
            'ровно мегабайт' => [1_048_576, '1,0 МБ'],
            'граница гигабайта' => [1_073_741_823, '1,0 ГБ'],
            'отрицательное' => [-5, '0 Б'],
        ];
    }

    #[DataProvider('sizes')]
    public function test_file_size_is_formatted_in_russian_units(int $bytes, string $expected): void
    {
        // между числом и единицей — неразрывный пробел: «386,5» и «КБ» не разрываются переносом строки
        $this->assertSame(str_replace(' ', self::NBSP, $expected), Format::fileSize($bytes));
    }
}
