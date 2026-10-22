<?php

namespace Tests\Unit;

use App\Models\ExtractedValue;
use App\Support\TextHighlighter;
use Tests\TestCase;

/** Позиции от сервиса — в символах Unicode (как индексы строк Python), не в байтах UTF-8. */
class TextHighlighterTest extends TestCase
{
    /** @param  array<string, mixed>  $attributes */
    private function value(int $id, ?int $start, ?int $end, array $attributes = []): ExtractedValue
    {
        return (new ExtractedValue)->forceFill($attributes + [
            'id' => $id, 'field_name' => 'f'.$id, 'start_offset' => $start, 'end_offset' => $end,
            'needs_review' => false, 'is_corrected' => false,
        ]);
    }

    /**
     * @param  list<ExtractedValue>  $values
     * @return array{html: string, truncated: bool}
     */
    private function render(string $text, array $values): array
    {
        return TextHighlighter::render($text, $values, fn (ExtractedValue $value) => 'Поле '.$value->field_name);
    }

    public function test_offsets_are_counted_in_unicode_characters(): void
    {
        // эмодзи — один символ в Python, но 4 байта в UTF-8
        $text = '😀 ИНН 7707083893, итого';
        $start = mb_strpos($text, '7707083893');

        $html = $this->render($text, [$this->value(1, $start, $start + 10)])['html'];

        $this->assertStringContainsString('>7707083893</mark>', $html);
        $this->assertStringStartsWith('😀 ИНН <mark', $html);
        $this->assertStringEndsWith('</mark>, итого', $html);
    }

    public function test_overlapping_invalid_and_out_of_range_marks_are_skipped(): void
    {
        $text = 'Поставщик ООО Ромашка';

        $html = $this->render($text, [
            $this->value(1, 10, 21),
            $this->value(2, 14, 21),
            $this->value(3, 5, 5),
            $this->value(4, 7, 3),
            $this->value(5, 15, 500),
            $this->value(6, null, null),
        ])['html'];

        $this->assertSame(1, substr_count($html, '<mark'));
        $this->assertSame($text, strip_tags($html));
    }

    public function test_adjacent_marks_are_both_rendered(): void
    {
        $html = $this->render('АБВГ', [$this->value(1, 0, 2), $this->value(2, 2, 4)])['html'];

        $this->assertSame(2, substr_count($html, '<mark'));
        $this->assertSame('АБВГ', strip_tags($html));
    }

    public function test_text_field_name_and_title_are_escaped(): void
    {
        $text = '<img src=x onerror=alert(1)> 123';
        $value = $this->value(1, 29, 32, ['field_name' => '"><script>x</script>']);

        $html = $this->render($text, [$value])['html'];

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_long_text_is_truncated_and_marks_beyond_cut_are_dropped(): void
    {
        $text = str_repeat('я', TextHighlighter::MAX_DISPLAY_CHARS + 50);

        $result = $this->render($text, [$this->value(1, TextHighlighter::MAX_DISPLAY_CHARS + 10, TextHighlighter::MAX_DISPLAY_CHARS + 20)]);

        $this->assertTrue($result['truncated']);
        $this->assertSame(TextHighlighter::MAX_DISPLAY_CHARS, mb_strlen($result['html']));
    }

    public function test_review_and_corrected_values_are_styled_differently(): void
    {
        $html = $this->render('ААА БББ ВВВ', [
            $this->value(1, 0, 3),
            $this->value(2, 4, 7, ['needs_review' => true]),
            $this->value(3, 8, 11, ['is_corrected' => true]),
        ])['html'];

        $this->assertStringContainsString('data-state="found"', $html);
        $this->assertStringContainsString('data-state="review"', $html);
        // исправленное значение: подсвечено место, где сервис нашёл исходное
        $this->assertStringContainsString('data-state="corrected"', $html);
        $this->assertStringContainsString('Поле f3 (исправлено вручную)', $html);
    }
}
