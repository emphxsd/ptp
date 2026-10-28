<?php

namespace App\Livewire\Forms;

use App\Enums\DataType;
use App\Models\Template;
use App\Models\TemplateField;
use App\Rules\UserRegex;
use App\Services\Processor\ProcessorClient;
use App\Services\Processor\ProcessorUnavailableException;
use App\Support\FieldDefaults;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Form;

/**
 * Поле шаблона. Правила совпадают со схемой сервиса обработки (processor/app/schemas.py: TemplateField,
 * FieldValidation), чтобы сохранённый шаблон сервис принимал без ошибок. Поле с регулярным выражением
 * дополнительно проверяет сам сервис: только он знает, примет ли выражение RE2.
 * Списки (слова-якоря, допустимые значения) вводятся по одному на строку.
 */
class TemplateFieldForm extends Form
{
    public const MAX_KEYWORDS = 20;

    public const MAX_KEYWORD_LENGTH = 100;

    public const MIN_KEYWORD_CHARS = 2;

    public const MAX_ALLOWED_VALUES = 100;

    public const MAX_ALLOWED_VALUE_LENGTH = 200;

    public const MAX_HINT_LENGTH = 500;

    public const MAX_TEXT_LENGTH = 10_000;

    // в processor: Decimal(max_digits=20, decimal_places=2); D — «$» не пропускает перевод строки в конце
    private const AMOUNT = '/^-?\d{1,18}(\.\d{1,2})?$/D';

    private const NAME = '/^[a-z][a-z0-9_]{0,63}$/D';

    private const SINGLE_LINE = '/[\x00-\x1f\x7f]/';

    // как _CONTROL_CHARS в processor: табуляция и перевод строки допустимы
    private const CONTROL_CHARS = '/[\x00-\x08\x0b-\x1f\x7f]/';

    // свойства формы → путь в шаблоне сервиса (ошибки сервиса показываются у нужного поля формы)
    private const PROCESSOR_PATHS = [
        'name' => 'name', 'label' => 'label', 'data_type' => 'data_type', 'keywords' => 'keywords',
        'regex_pattern' => 'regex_pattern', 'ner_label' => 'ner_label', 'llm_hint' => 'llm_hint',
        'min_value' => 'validation.min_value', 'max_value' => 'validation.max_value',
        'min_date' => 'validation.min_date', 'max_date' => 'validation.max_date',
        'max_length' => 'validation.max_length', 'allowed_values' => 'validation.allowed_values',
        'pattern' => 'validation.pattern',
    ];

    // тип не выбран — определяется по названию поля (FieldDefaults::guessType)
    public const AUTO_TYPE = 'auto';

    // null — новое поле; задаётся только на сервере (editField), клиент изменить не может
    #[Locked]
    public ?int $id = null;

    // пусто — составляется из названия (FieldDefaults::systemName); у существующего поля не меняется
    public string $name = '';

    public string $label = '';

    public string $data_type = self::AUTO_TYPE;

    public bool $is_required = false;

    public string $keywords = '';

    public string $regex_pattern = '';

    public string $ner_label = '';

    public string $llm_hint = '';

    public string $min_value = '';

    public string $max_value = '';

    public string $min_date = '';

    public string $max_date = '';

    public string $max_length = '';

    public string $allowed_values = '';

    public string $pattern = '';

    public function fillFrom(TemplateField $field): void
    {
        $rules = $field->validation ?? [];

        $this->id = $field->id;
        $this->name = $field->name;
        $this->label = $field->label;
        $this->data_type = $field->data_type->value;
        $this->is_required = $field->is_required;
        $this->keywords = implode("\n", $field->keywords ?? []);
        $this->regex_pattern = (string) $field->regex_pattern;
        $this->ner_label = (string) $field->ner_label;
        $this->llm_hint = (string) $field->llm_hint;
        $this->min_value = (string) ($rules['min_value'] ?? '');
        $this->max_value = (string) ($rules['max_value'] ?? '');
        $this->min_date = (string) ($rules['min_date'] ?? '');
        $this->max_date = (string) ($rules['max_date'] ?? '');
        $this->max_length = (string) ($rules['max_length'] ?? '');
        $this->allowed_values = implode("\n", $rules['allowed_values'] ?? []);
        $this->pattern = (string) ($rules['pattern'] ?? '');
    }

    /**
     * Проверяет форму (и регулярные выражения — сервисом обработки) и возвращает атрибуты поля для сохранения.
     * Ограничения на шаблон целиком проверяет assertWithinLimits() — в транзакции вместе с записью.
     *
     * @return array<string, mixed>
     */
    public function validatedFor(Template $template): array
    {
        $this->normalize();
        // угаданные имя и тип не остаются в форме: после ошибки пользователь может сменить название,
        // и угадывать нужно заново по новому
        [$name, $type] = [$this->name, $this->data_type];
        $this->fillDefaults($template);
        try {
            return $this->validatedAttributes($template);
        } finally {
            [$this->name, $this->data_type] = [$name, $type];
        }
    }

    /** @return array<string, mixed> */
    private function validatedAttributes(Template $template): array
    {
        $data = $this->validate($this->rulesFor($template), [
            'name.regex' => 'Только строчные латинские буквы, цифры и «_», первая — буква (например, total_sum).',
            'min_value.regex' => 'Введите сумму, например 1500 или 1500,50.',
            'max_value.regex' => 'Введите сумму, например 1500 или 1500,50.',
        ] + ValidationMessages::all());
        $type = DataType::from($data['data_type']);

        $validation = array_filter([
            'min_value' => $type === DataType::Amount ? $this->nullIfBlank($data['min_value'] ?? null) : null,
            'max_value' => $type === DataType::Amount ? $this->nullIfBlank($data['max_value'] ?? null) : null,
            'min_date' => $type === DataType::Date ? $this->nullIfBlank($data['min_date'] ?? null) : null,
            'max_date' => $type === DataType::Date ? $this->nullIfBlank($data['max_date'] ?? null) : null,
            'max_length' => $this->nullIfBlank($data['max_length'] ?? null) === null ? null : (int) $data['max_length'],
            'allowed_values' => self::lines($this->allowed_values) ?: null,
            'pattern' => $this->nullIfBlank($data['pattern'] ?? null),
        ], fn (mixed $value) => $value !== null);

        $attributes = [
            'name' => $data['name'],
            'label' => $data['label'],
            'data_type' => $type,
            'is_required' => (bool) $data['is_required'],
            'keywords' => self::lines($this->keywords) ?: null,
            'regex_pattern' => $this->nullIfBlank($data['regex_pattern'] ?? null),
            'ner_label' => $this->nullIfBlank($data['ner_label'] ?? null),
            'llm_hint' => $this->nullIfBlank($data['llm_hint'] ?? null),
            'validation' => $validation ?: null,
        ];

        if ($attributes['regex_pattern'] !== null || isset($validation['pattern'])) {
            $this->checkWithProcessor($attributes);
        }

        return $attributes;
    }

    /** Ограничения на шаблон целиком: число полей, полей с регулярным выражением и полей у владельца. */
    public function assertWithinLimits(Template $template, bool $hasRegex): void
    {
        $others = $template->fields()->when($this->id !== null, fn ($query) => $query->whereKeyNot($this->id));

        if ($this->id === null && (clone $others)->count() >= Template::MAX_FIELDS) {
            $this->failOn('name', 'В шаблоне не может быть больше '.Template::MAX_FIELDS.' полей.');
        }
        if ($this->id === null && $template->user !== null
            && TemplateField::whereIn('template_id', $template->user->templates()->select('id'))->count() >= Template::MAX_FIELDS_PER_USER) {
            $this->failOn('name', 'Во всех ваших шаблонах не может быть больше '.Template::MAX_FIELDS_PER_USER.' полей.');
        }
        if ($hasRegex && (clone $others)->whereNotNull('regex_pattern')->count() >= Template::MAX_REGEX_FIELDS) {
            $this->failOn('regex_pattern', 'Регулярное выражение можно задать не больше чем для '.Template::MAX_REGEX_FIELDS.' полей.');
        }
    }

    private function failOn(string $property, string $message): never
    {
        throw ValidationException::withMessages([$this->getPropertyName().'.'.$property => $message]);
    }

    /** @return list<string> непустые строки без пробелов (включая неразрывные) по краям — как strip() в сервисе */
    public static function lines(string $text): array
    {
        $lines = array_map(
            fn (string $line) => (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $line),
            preg_split('/\R/u', $text) ?: [],
        );

        return array_values(array_filter($lines, fn (string $line) => $line !== ''));
    }

    /**
     * Сервис проверяет поле той же схемой, что при обработке, и компилирует выражения через RE2.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function checkWithProcessor(array $attributes): void
    {
        $field = (new TemplateField)->forceFill($attributes);
        try {
            $issues = app(ProcessorClient::class)->validateTemplate([
                'name' => 'check',
                'fields' => [$field->toProcessorPayload()],
            ]);
        } catch (ProcessorUnavailableException) {
            $property = $attributes['regex_pattern'] !== null ? 'regex_pattern' : 'pattern';
            $this->failOn($property, 'Не удалось проверить регулярное выражение: сервис обработки недоступен. Попробуйте позже.');
        }

        $errors = [];
        foreach ($issues as $issue) {
            $path = preg_replace('/^fields\.0\./', '', $issue['field']);
            $property = array_search($path, self::PROCESSOR_PATHS, true) ?: 'name';
            $errors[$this->getPropertyName().'.'.$property][] = $issue['message'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Обычному пользователю достаточно названия: имя и тип поля определяются по нему. */
    private function fillDefaults(Template $template): void
    {
        if ($this->label === '') {
            return; // «Название обязательно» — ошибка валидации, имя и тип угадывать не по чему
        }
        if ($this->data_type === self::AUTO_TYPE) {
            $this->data_type = FieldDefaults::guessType($this->label)->value;
        }
        if ($this->name === '') {
            $taken = $template->fields()->when($this->id !== null, fn ($query) => $query->whereKeyNot($this->id))->pluck('name')->all();
            $this->name = FieldDefaults::systemName($this->label, $taken);
        }
    }

    private function normalize(): void
    {
        $this->name = trim($this->name);
        $this->label = trim($this->label);
        $this->llm_hint = trim($this->llm_hint);
        // «1 000,50» → «1000.50»
        foreach (['min_value', 'max_value'] as $property) {
            $this->{$property} = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($this->{$property}));
        }
        foreach (['min_date', 'max_date', 'max_length'] as $property) {
            $this->{$property} = trim($this->{$property});
        }
    }

    /** @return array<string, list<mixed>> */
    private function rulesFor(Template $template): array
    {
        $maxValue = ['exclude_unless:data_type,amount', 'nullable', 'regex:'.self::AMOUNT];
        if ($this->min_value !== '' && $this->max_value !== '') {
            $maxValue[] = function (string $attribute, mixed $value, Closure $fail): void {
                if (preg_match(self::AMOUNT, $this->min_value) && (float) $value < (float) $this->min_value) {
                    $fail('Максимум меньше минимума.');
                }
            };
        }
        $maxDate = ['exclude_unless:data_type,date', 'nullable', 'date_format:Y-m-d'];
        if ($this->min_date !== '' && $this->max_date !== '') {
            $maxDate[] = 'after_or_equal:min_date';
        }

        // предел длины до разбора по строкам: иначе разбирался бы ввод любого размера
        $keywordsMax = self::MAX_KEYWORDS * (self::MAX_KEYWORD_LENGTH + 2);
        $allowedMax = self::MAX_ALLOWED_VALUES * (self::MAX_ALLOWED_VALUE_LENGTH + 2);

        return [
            // без названия имя не составить — ошибка одна: «Название обязательно»
            'name' => $this->label === '' ? [] : [
                'required', 'string', 'regex:'.self::NAME,
                Rule::unique('template_fields', 'name')->where('template_id', $template->id)->ignore($this->id),
            ],
            'label' => ['required', 'string', 'max:150', 'not_regex:'.self::SINGLE_LINE],
            // «auto» остаётся только без названия — тогда ошибка одна: «Название обязательно»
            'data_type' => ['required', Rule::in([...array_column(DataType::cases(), 'value'), self::AUTO_TYPE])],
            'is_required' => ['boolean'],
            'keywords' => ['nullable', 'string', 'max:'.$keywordsMax, $this->keywordsRule()],
            'regex_pattern' => ['nullable', 'string', 'max:'.UserRegex::MAX_LENGTH, new UserRegex],
            'ner_label' => ['nullable', Rule::in(TemplateField::NER_LABELS)],
            'llm_hint' => ['nullable', 'string', 'max:'.self::MAX_HINT_LENGTH, 'not_regex:'.self::CONTROL_CHARS],
            'min_value' => ['exclude_unless:data_type,amount', 'nullable', 'regex:'.self::AMOUNT],
            'max_value' => $maxValue,
            'min_date' => ['exclude_unless:data_type,date', 'nullable', 'date_format:Y-m-d'],
            'max_date' => $maxDate,
            'max_length' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_TEXT_LENGTH],
            'allowed_values' => ['nullable', 'string', 'max:'.$allowedMax, $this->allowedValuesRule()],
            'pattern' => ['nullable', 'string', 'max:'.UserRegex::MAX_LENGTH, new UserRegex],
        ];
    }

    private function keywordsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $keywords = self::lines((string) $value);
            if (count($keywords) > self::MAX_KEYWORDS) {
                $fail('Не больше '.self::MAX_KEYWORDS.' слов-якорей.');

                return;
            }
            foreach ($keywords as $keyword) {
                // «*» в конце — поиск по началу слова (поставщик* → поставщиком)
                $core = str_ends_with($keyword, '*') ? mb_substr($keyword, 0, -1) : $keyword;
                if (mb_strlen($keyword) > self::MAX_KEYWORD_LENGTH || preg_match(self::CONTROL_CHARS, $keyword)) {
                    $fail('Слово-якорь «'.mb_substr($keyword, 0, 30).'…» длиннее '.self::MAX_KEYWORD_LENGTH.' символов.');

                    return;
                }
                if (mb_strlen($core) < self::MIN_KEYWORD_CHARS || str_contains($core, '*') || ! preg_match('/[\p{L}\p{N}]/u', $core)) {
                    $fail("Слово-якорь «{$keyword}»: нужно не меньше ".self::MIN_KEYWORD_CHARS.' символов с буквой или цифрой, «*» — только в конце.');

                    return;
                }
            }
        };
    }

    private function allowedValuesRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $values = self::lines((string) $value);
            if (count($values) > self::MAX_ALLOWED_VALUES) {
                $fail('Не больше '.self::MAX_ALLOWED_VALUES.' допустимых значений.');

                return;
            }
            foreach ($values as $allowed) {
                if (mb_strlen($allowed) > self::MAX_ALLOWED_VALUE_LENGTH || preg_match(self::CONTROL_CHARS, $allowed)) {
                    $fail('Каждое допустимое значение — не длиннее '.self::MAX_ALLOWED_VALUE_LENGTH.' символов.');

                    return;
                }
            }
        };
    }

    private function nullIfBlank(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }
}
