<?php

namespace App\Support;

use App\Enums\DataType;
use App\Livewire\Forms\TemplateFieldForm;
use App\Models\TemplateField;

/**
 * Обучение на исправлениях: пользователь исправил значение — ищем его в тексте документа и берём подпись слева
 * в той же строке («Итого к оплате: 15 000,00» → «Итого к оплате»). Новая подпись добавляется в слова-якоря поля,
 * и в следующих таких документах правила находят значение сами.
 */
final class AnchorLearner
{
    private const MAX_WORDS = 3;

    private const MIN_LETTERS = 3;

    // подпись и значение могут разделять «:», «—», «№» и пробелы
    private const SEPARATORS = " \t\u{00A0}:—–-№#";

    /** Подпись слева от значения или null, если значения нет в тексте или подписи рядом нет. */
    public static function anchorFor(string $text, string $value, DataType $type): ?string
    {
        if (! preg_match_all(self::pattern($value, $type), $text, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        foreach ($matches[0] as [, $offset]) {
            $label = self::labelBefore(substr($text, 0, $offset));
            if ($label !== null) {
                return $label;
            }
        }

        return null;
    }

    /** Добавить подпись в слова-якоря поля; возвращает добавленную подпись или null. */
    public static function learn(TemplateField $field, string $text, string $value): ?string
    {
        $anchor = self::anchorFor($text, $value, $field->data_type);
        $keywords = $field->keywords ?? [];
        if ($anchor === null || count($keywords) >= TemplateFieldForm::MAX_KEYWORDS || self::known($anchor, $keywords)) {
            return null;
        }
        $field->update(['keywords' => [...$keywords, $anchor]]);

        return $anchor;
    }

    /**
     * Регулярное выражение для значения в том виде, в каком оно бывает в документе:
     * сумма 15000.00 — «15 000,00», «15000» ; дата 2025-03-01 — «01.03.2025», «1.3.25».
     */
    private static function pattern(string $value, DataType $type): string
    {
        if ($type === DataType::Amount && preg_match('/^(\d+)\.(\d{2})$/D', $value, $m)) {
            $groups = str_split(strrev($m[1]), 3);
            $integer = strrev(implode(strrev('[\s\x{00A0}]?'), $groups));
            $cents = $m[2] === '00' ? '(?:[.,]00)?' : '[.,]'.$m[2];

            // перед числом не цифра и не «цифра + пробел»: «15 000» не находится внутри «115 000»
            return '/(?<!\d)(?<!\d[\s\x{00A0}])'.$integer.$cents.'(?!\d)/u';
        }
        if ($type === DataType::Date && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) {
            [, $year, $month, $day] = $m;

            return '/(?<!\d)0?'.ltrim($day, '0').'[.\/-]0?'.ltrim($month, '0').'[.\/-](?:'.$year.'|'.substr($year, 2).')(?!\d)/u';
        }
        $words = preg_split('/\s+/u', trim($value)) ?: [];

        return '/(?<![\p{L}\p{N}])'.implode('\s+', array_map(fn (string $w) => preg_quote($w, '/'), $words)).'(?![\p{L}\p{N}])/iu';
    }

    /** До трёх слов из букв непосредственно перед значением, в пределах строки и после последней запятой. */
    private static function labelBefore(string $before): ?string
    {
        $line = (string) preg_replace('/^.*[\n,;(«"|]/su', '', $before);
        $line = rtrim($line, self::SEPARATORS);
        $words = preg_split('/\s+/u', trim($line)) ?: [];

        $label = [];
        foreach (array_reverse($words) as $word) {
            if (! preg_match('/^[\p{L}][\p{L}.\-]*$/u', $word) || count($label) === self::MAX_WORDS) {
                break;
            }
            array_unshift($label, $word);
        }
        $anchor = implode(' ', $label);
        $letters = preg_match_all('/\p{L}/u', $anchor);

        return $letters >= self::MIN_LETTERS ? mb_substr($anchor, 0, TemplateFieldForm::MAX_KEYWORD_LENGTH) : null;
    }

    /**
     * Подпись уже есть среди слов-якорей (без учёта регистра; «*» в конце — любое окончание).
     *
     * @param  list<string>  $keywords
     */
    private static function known(string $anchor, array $keywords): bool
    {
        $needle = mb_strtolower($anchor);
        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower($keyword);
            if ($keyword === $needle || (str_ends_with($keyword, '*') && str_starts_with($needle, rtrim($keyword, '*')))) {
                return true;
            }
        }

        return false;
    }
}
