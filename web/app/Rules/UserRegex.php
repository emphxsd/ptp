<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Регулярное выражение пользователя для сервиса обработки. Сервис выполняет его через RE2 (без ReDoS),
 * поэтому здесь отклоняется то, чего RE2 не поддерживает, и то, что совпадает с пустой строкой —
 * те же проверки, что в processor/app/fields/user_regex.py. Предел сложности программы RE2 проверяет
 * только сам сервис: такой шаблон будет отклонён при обработке документа с понятной ошибкой.
 */
class UserRegex implements ValidationRule
{
    public const MAX_LENGTH = 300;

    /*
     * Не поддерживаются RE2 (быстрая подсказка; окончательно проверяет сервис): обратные ссылки \1 \k<n> \g{1},
     * \G \K \R \h \H \X \Z \N \e \C, проверки вида (?= и (?<!, атомарные группы, (?|, условия (?(, комментарии (?#,
     * рекурсия (?R) (?1) (?&n), глаголы (*UTF) и сверхжадные квантификаторы a++.
     */
    private const UNSUPPORTED = '/\\\\[1-9]|\\\\[kg][<{\'\-+\d]|\\\\[GKRhHXZNeC]|\(\?(?:<?[=!]|>|\||\(|#|R|[+-]?\d|&|P>)|\(\*|(?<!\\\\)[*+?]\+/';

    private const CONTROL_CHARS = '/[\x00-\x08\x0b-\x1f\x7f]/';

    // те же пробные строки, что в сервисе: шаблон не должен давать пустых совпадений (например, \b или ^)
    private const EMPTY_MATCH_PROBES = ['', 'a', '1', ' ', 'аб', 'a 1', "\n"];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }
        if (mb_strlen($value) > self::MAX_LENGTH) {
            $fail('Регулярное выражение длиннее '.self::MAX_LENGTH.' символов.');

            return;
        }
        if (preg_match(self::CONTROL_CHARS, $value) || preg_match(self::UNSUPPORTED, $value)) {
            $fail('Конструкция не поддерживается RE2: обратные ссылки, проверки (?=…) и (?<!…), рекурсия, \G, \K, \R, \h, \Z, a++ и подобные.');

            return;
        }

        // \x01 не может встретиться в шаблоне (управляющие символы отклонены выше), поэтому годится как ограничитель
        $regex = "\x01".$value."\x01u";
        if (@preg_match($regex, '') === false) {
            $fail('Некорректное регулярное выражение.');

            return;
        }
        foreach (self::EMPTY_MATCH_PROBES as $probe) {
            // false — исчерпан предел возвратов PCRE: такое выражение слишком тяжёлое и для проверки
            if (preg_match_all($regex, $probe, $matches, PREG_OFFSET_CAPTURE) === false) {
                $fail('Регулярное выражение слишком сложное.');

                return;
            }
            foreach ($matches[0] as [$match]) {
                if ($match === '') {
                    $fail('Регулярное выражение не должно совпадать с пустой строкой.');

                    return;
                }
            }
        }
    }
}
