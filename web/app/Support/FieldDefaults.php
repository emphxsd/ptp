<?php

namespace App\Support;

use App\Enums\DataType;
use Illuminate\Support\Str;

/**
 * Системное имя и тип поля по его названию: обычному пользователю не нужно знать ни «snake_case», ни типов данных.
 * Тип определяет, какими правилами и сущностями NER сервис обработки ищет значение.
 */
final class FieldDefaults
{
    // как у сервиса обработки: ^[a-z][a-z0-9_]{0,63}$
    private const MAX_NAME_LENGTH = 64;

    private const FALLBACK_NAME = 'field';

    /**
     * Порядок важен: «Номер телефона» — телефон, а не номер документа; «ИНН поставщика» — ИНН, а не организация;
     * «Дата рождения кандидата» — дата, а не ФИО.
     *
     * @var array<string, string>
     */
    private const TYPE_PATTERNS = [
        'inn' => '/\bинн\b/u',
        'kpp' => '/\bкпп\b/u',
        'bik' => '/\bбик\b/u',
        'email' => '/e-?mail|электронн\w*\s+почт|эл\.\s*почт/u',
        'phone' => '/телефон|\bтел\.|мобильн/u',
        'date' => '/\bдат[аеуы]?\b/u',
        'amount' => '/сумм|стоимост|\bцен[аыу]?\b|итог|\bндс\b|\bаванс|\bруб/u',
        'number' => '/номер|№/u',
        'person' => '/\bфио\b|ф\.\s*и\.\s*о|фамили|подписант|руководител|директор|кандидат|сотрудник|представител|ответственн/u',
        'organization' => '/организаци|поставщик|покупател|заказчик|исполнител|подрядчик|контрагент|компани|работодател|\bбанк/u',
    ];

    /** @param  array<array-key, mixed>  $taken  имена других полей шаблона (сравниваются строго) */
    public static function systemName(string $label, array $taken): string
    {
        $base = Str::slug($label, '_', 'ru');
        if ($base === '') {
            $base = self::FALLBACK_NAME;
        } elseif (! ctype_alpha($base[0])) {
            $base = self::FALLBACK_NAME.'_'.$base;
        }
        $base = self::truncate($base, self::MAX_NAME_LENGTH);

        $name = $base;
        for ($suffix = 2; in_array($name, $taken, true); $suffix++) {
            $name = self::truncate($base, self::MAX_NAME_LENGTH - strlen("_{$suffix}"))."_{$suffix}";
        }

        return $name;
    }

    public static function guessType(string $label): DataType
    {
        $text = str_replace('ё', 'е', mb_strtolower($label));
        foreach (self::TYPE_PATTERNS as $type => $pattern) {
            if (preg_match($pattern, $text)) {
                return DataType::from($type);
            }
        }

        return DataType::String;
    }

    /** Тип по самому значению (шаблон по примеру: выделенный фрагмент документа). */
    public static function guessTypeFromValue(string $value): DataType
    {
        $value = trim($value);

        return match (true) {
            (bool) preg_match('/^\d{10}(\d{2})?$/D', $value) => DataType::Inn,
            (bool) preg_match('/^\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}$/D', $value) => DataType::Date,
            (bool) preg_match('/^[\w.+-]+@[\w-]+(\.[\w-]+)+$/uD', $value) => DataType::Email,
            (bool) preg_match('/^\+?[78][\s(-]*\d{3}[\s)-]*\d{3}[\s-]*\d{2}[\s-]*\d{2}$/D', $value) => DataType::Phone,
            // сумма — число с копейками, рублями или разрядами через пробел (а не просто номер)
            preg_match('/^\d{1,3}([\s\x{00A0}]?\d{3})*([.,]\d{2})?(\s*(руб\.?|₽|р\.))?$/uD', $value) === 1
                && preg_match('/[.,]\d{2}$|руб|₽|р\.|\d[\s\x{00A0}]\d/u', $value) === 1 => DataType::Amount,
            (bool) preg_match('/^(ООО|АО|ПАО|ЗАО|ОАО|ИП|НКО|ГУП|МУП|ФГУП)(\s|$)/u', $value) => DataType::Organization,
            default => DataType::String,
        };
    }

    private static function truncate(string $name, int $length): string
    {
        return rtrim(substr($name, 0, $length), '_');
    }
}
