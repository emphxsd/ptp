<?php

namespace App\Support;

/**
 * Запрос к архиву на естественном языке → фильтры по извлечённым полям:
 * «все договоры с ООО Ромашка за 2025 год дороже 100 тыс.» → тип «договор», организация «Ромашка»,
 * даты 01.01.2025—31.12.2025, сумма от 100 000. Разбор — правилами: быстро, предсказуемо и без отправки запроса в LLM.
 */
final class ArchiveQuery
{
    private const LEGAL_FORMS = 'ООО|АО|ПАО|ЗАО|ОАО|ИП';

    // слова-связки, которые после разбора ничего не ищут
    private const STOP_WORDS = [
        'все', 'всех', 'документы', 'документ', 'документов', 'с', 'со', 'за', 'год', 'году', 'года', 'г', 'в', 'по',
        'от', 'до', 'и', 'на', 'тыс', 'руб', 'рублей', 'инн', '₽', 'найди', 'найти', 'покажи',
    ];

    private const NUMBER = '(\d[\d\s\x{00A0}]*(?:[.,]\d+)?)\s*(тыс\.?|тысяч\p{L}*|млн\.?|миллион\p{L}*)?\s*(?:руб\p{L}*\.?|₽)?';

    /** @param  list<string>  $words */
    private function __construct(
        public readonly ?string $type,
        public readonly ?string $organization,
        public readonly ?string $inn,
        public readonly ?string $dateFrom,
        public readonly ?string $dateTo,
        public readonly ?string $amountMin,
        public readonly ?string $amountMax,
        public readonly array $words,
    ) {}

    public static function parse(string $query): self
    {
        $rest = ' '.preg_replace('/\s+/u', ' ', trim($query)).' ';

        self::take($rest, '/(?<!\p{L})инн\s*/iu'); // слово «ИНН» — подпись, ищется само число
        $inn = ($m = self::take($rest, '/(?<!\d)(\d{12}|\d{10})(?!\d)/u')) !== null ? $m[1] : null;

        [$dateFrom, $dateTo] = [null, null];
        if (($m = self::take($rest, '/(?<!\p{L})с\s+(\d{1,2}\.\d{1,2}\.\d{4})\s+(?:по|до)\s+(\d{1,2}\.\d{1,2}\.\d{4})/iu')) !== null) {
            [$dateFrom, $dateTo] = [self::isoDate($m[1]), self::isoDate($m[2])];
        } elseif (($m = self::take($rest, '/(?<!\p{L})(?:за|в)\s+(\d{4})\s*(?:год\p{L}*|г\.?)?(?!\p{L})/iu')) !== null) {
            [$dateFrom, $dateTo] = ["{$m[1]}-01-01", "{$m[1]}-12-31"];
        } elseif (($m = self::take($rest, '/(?<!\p{L})от\s+(\d{1,2}\.\d{1,2}\.\d{4})/iu')) !== null) {
            $dateFrom = $dateTo = self::isoDate($m[1]);
        }

        $min = self::take($rest, '/(?<!\p{L})(?:дороже|больше|свыше|более|от)\s+'.self::NUMBER.'/iu');
        $max = self::take($rest, '/(?<!\p{L})(?:дешевле|меньше|менее|до)\s+'.self::NUMBER.'/iu');

        $organization = null;
        $form = '/(?<!\p{L})(?:'.self::LEGAL_FORMS.')\s+[«"]?([^«»",.]+?)[»"]?(?=\s+(?:за|с|по|от|до|дороже|дешевле|больше|меньше|свыше|более|менее)(?!\p{L})|[,.]|\s*$)/u';
        if (($m = self::take($rest, $form)) !== null) {
            $organization = trim($m[1]);
        }

        $type = null;
        if (($m = self::take($rest, '/(?<!\p{L})(договор\p{L}*|сч[её]т\p{L}*|акт\p{L}*|резюме)(?!\p{L})/iu')) !== null) {
            $word = str_replace('ё', 'е', mb_strtolower($m[1]));
            $type = match (true) {
                str_starts_with($word, 'договор') => 'договор',
                str_starts_with($word, 'сч') => 'счет',
                str_starts_with($word, 'акт') => 'акт',
                default => 'резюме',
            };
        }

        $words = [];
        foreach (preg_split('/\s+/u', trim($rest)) ?: [] as $word) {
            $word = trim($word, " \t.,;:!?«»\"'()");
            if (mb_strlen($word) >= 2 && ! in_array(mb_strtolower($word), self::STOP_WORDS, true)) {
                $words[] = $word;
            }
        }

        return new self($type, $organization, $inn, $dateFrom, $dateTo, self::amount($min), self::amount($max), $words);
    }

    public function isEmpty(): bool
    {
        return $this->type === null && $this->organization === null && $this->inn === null && $this->dateFrom === null
            && $this->amountMin === null && $this->amountMax === null && $this->words === [];
    }

    /** @return list<string> как понят запрос — показывается под строкой поиска */
    public function describe(): array
    {
        $date = fn (string $iso) => implode('.', array_reverse(explode('-', $iso)));
        $money = fn (string $amount) => number_format((float) $amount, 0, ',', ' ');

        return array_values(array_filter([
            $this->type === null ? null : "Тип: {$this->type}",
            $this->organization === null ? null : "Организация: {$this->organization}",
            $this->inn === null ? null : "ИНН: {$this->inn}",
            match (true) {
                $this->dateFrom === null => null,
                $this->dateFrom === $this->dateTo => 'Дата: '.$date($this->dateFrom),
                default => 'Дата: '.$date($this->dateFrom).' — '.$date((string) $this->dateTo),
            },
            $this->amountMin === null ? null : 'Сумма от '.$money($this->amountMin),
            $this->amountMax === null ? null : 'Сумма до '.$money($this->amountMax),
            $this->words === [] ? null : 'Слова: '.implode(', ', $this->words),
        ]));
    }

    /**
     * Найти фрагмент и вырезать его из остатка запроса.
     *
     * @return list<string>|null
     */
    private static function take(string &$rest, string $pattern): ?array
    {
        if (! preg_match($pattern, $rest, $m)) {
            return null;
        }
        $rest = (string) preg_replace($pattern, ' ', $rest, 1);

        return array_values(array_map('strval', $m));
    }

    private static function isoDate(string $date): ?string
    {
        [$day, $month, $year] = array_map('intval', explode('.', $date));

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /** @param  list<string>|null  $match  [фрагмент, число, множитель] */
    private static function amount(?array $match): ?string
    {
        if ($match === null) {
            return null;
        }
        $number = (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $match[1]);
        $unit = mb_strtolower($match[2] ?? '');
        $multiplier = match (true) {
            str_starts_with($unit, 'тыс') => 1_000,
            str_starts_with($unit, 'млн'), str_starts_with($unit, 'миллион') => 1_000_000,
            default => 1,
        };
        $value = $number * $multiplier;

        return floor($value) === $value ? (string) (int) $value : number_format($value, 2, '.', '');
    }
}
