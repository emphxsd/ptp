<?php

namespace App\Support;

/**
 * Правила проверки значения из поля шаблона (TemplateField::validation) — те же, что применяет сервис
 * (processor/app/fields/validation.py). Ручное исправление, которое их нарушает, не сохраняется:
 * иначе пользователь снял бы пометку «требует проверки» с заведомо неверного значения.
 */
final class ValueRules
{
    private const DECIMAL = '/^(-?)(\d+)(?:\.(\d+))?$/D';

    /**
     * Первое нарушенное правило или null. Значение уже нормализовано: сумма — 15000.00, дата — ГГГГ-ММ-ДД.
     *
     * @param  array<string, mixed>|null  $rules
     */
    public static function violation(?array $rules, string $value): ?string
    {
        if (! $rules) {
            return null;
        }

        return self::range($rules, $value)
            ?? self::length($rules, $value)
            ?? self::allowed($rules, $value)
            ?? self::pattern($rules, $value);
    }

    /** @param  array<string, mixed>  $rules */
    private static function range(array $rules, string $value): ?string
    {
        if (isset($rules['min_value']) && self::compareDecimal($value, (string) $rules['min_value']) < 0) {
            return 'не меньше '.$rules['min_value'];
        }
        if (isset($rules['max_value']) && self::compareDecimal($value, (string) $rules['max_value']) > 0) {
            return 'не больше '.$rules['max_value'];
        }
        // даты ГГГГ-ММ-ДД: порядок строк совпадает с порядком дат
        if (isset($rules['min_date']) && $value < (string) $rules['min_date']) {
            return 'не раньше '.$rules['min_date'];
        }
        if (isset($rules['max_date']) && $value > (string) $rules['max_date']) {
            return 'не позже '.$rules['max_date'];
        }

        return null;
    }

    /**
     * Сравнение десятичных строк без потери точности (до 18 знаков в целой части — больше, чем точно хранит float).
     * Не число — 0: правило диапазона к такому значению не применяется.
     */
    private static function compareDecimal(string $a, string $b): int
    {
        if (! preg_match(self::DECIMAL, $a, $x) || ! preg_match(self::DECIMAL, $b, $y)) {
            return 0;
        }
        $sign = fn (array $m) => $m[1] === '-' && trim($m[2].($m[3] ?? ''), '0') !== '' ? -1 : 1;
        if ($sign($x) !== $sign($y)) {
            return $sign($x);
        }

        $intA = ltrim($x[2], '0');
        $intB = ltrim($y[2], '0');
        $fracLength = max(strlen($x[3] ?? ''), strlen($y[3] ?? ''));
        $digitsA = str_pad($intA, strlen($intB), '0', STR_PAD_LEFT).str_pad($x[3] ?? '', $fracLength, '0');
        $digitsB = str_pad($intB, strlen($intA), '0', STR_PAD_LEFT).str_pad($y[3] ?? '', $fracLength, '0');

        return ($digitsA <=> $digitsB) * $sign($x);
    }

    /** @param  array<string, mixed>  $rules */
    private static function length(array $rules, string $value): ?string
    {
        return isset($rules['max_length']) && mb_strlen($value) > (int) $rules['max_length']
            ? 'не длиннее '.$rules['max_length'].' символов'
            : null;
    }

    /** @param  array<string, mixed>  $rules */
    private static function allowed(array $rules, string $value): ?string
    {
        if (empty($rules['allowed_values']) || ! is_array($rules['allowed_values'])) {
            return null;
        }
        $allowed = array_map(fn (mixed $item) => mb_strtolower((string) $item), $rules['allowed_values']);

        return in_array(mb_strtolower($value), $allowed, true)
            ? null
            : 'одно из: '.implode(', ', $rules['allowed_values']);
    }

    /** @param  array<string, mixed>  $rules */
    private static function pattern(array $rules, string $value): ?string
    {
        if (empty($rules['pattern']) || ! is_string($rules['pattern'])) {
            return null;
        }
        // выражение проверено при сохранении шаблона (UserRegex и сервис); значение должно совпасть целиком
        $matched = @preg_match("\x01^(?:".$rules['pattern'].")$\x01uD", $value);

        return $matched === 1 ? null : 'в формате '.$rules['pattern'];
    }
}
