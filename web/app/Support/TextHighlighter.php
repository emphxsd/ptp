<?php

namespace App\Support;

use App\Models\ExtractedValue;

/**
 * Текст документа с подсветкой найденных значений. Позиции start/end от сервиса — в символах Unicode
 * (как индексы строк Python), поэтому разбор — через mb_* функции. Весь текст экранируется;
 * позиции за пределами текста и пересекающиеся с уже подсвеченными пропускаются.
 */
final class TextHighlighter
{
    // длинный документ показывается не целиком: страница перерисовывается после каждого действия
    public const MAX_DISPLAY_CHARS = 100_000;

    private const STYLES = [
        'found' => 'rounded bg-emerald-200 px-0.5 text-zinc-900',
        'review' => 'rounded bg-amber-200 px-0.5 text-zinc-900 ring-1 ring-amber-500',
        // исправленное вручную: подсвечено место, где сервис нашёл исходное значение
        'corrected' => 'rounded bg-sky-200 px-0.5 text-zinc-900 line-through decoration-sky-700',
    ];

    /**
     * @param  iterable<ExtractedValue>  $values
     * @param  callable(ExtractedValue): string  $title  подпись подсветки (название поля)
     * @return array{html: string, truncated: bool}
     */
    public static function render(string $text, iterable $values, callable $title): array
    {
        $length = mb_strlen($text);
        $visible = mb_substr($text, 0, self::MAX_DISPLAY_CHARS);
        $visibleLength = mb_strlen($visible);

        $marks = [];
        foreach ($values as $value) {
            $start = $value->start_offset;
            $end = $value->end_offset;
            if ($start !== null && $end !== null && $start < $end && $end <= $visibleLength) {
                $marks[] = $value;
            }
        }
        usort($marks, fn (ExtractedValue $a, ExtractedValue $b) => [$a->start_offset, $a->id] <=> [$b->start_offset, $b->id]);

        $html = '';
        $position = 0;
        foreach ($marks as $value) {
            if ($value->start_offset < $position) {
                continue;
            }
            $state = match (true) {
                $value->is_corrected => 'corrected',
                $value->needs_review => 'review',
                default => 'found',
            };
            $html .= e(mb_substr($visible, $position, $value->start_offset - $position));
            $html .= sprintf(
                '<mark id="mark-%d" data-field="%s" data-state="%s" title="%s" class="%s">%s</mark>',
                $value->id,
                e($value->field_name),
                $state,
                e($title($value).($state === 'corrected' ? ' (исправлено вручную)' : '')),
                self::STYLES[$state],
                e(mb_substr($visible, $value->start_offset, $value->end_offset - $value->start_offset)),
            );
            $position = $value->end_offset;
        }
        $html .= e(mb_substr($visible, $position));

        return ['html' => $html, 'truncated' => $visibleLength < $length];
    }
}
