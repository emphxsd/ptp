<?php

namespace Tests\Feature\Templates;

use App\Enums\DataType;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\User;
use App\Services\Processor\ProcessorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TemplateConstructorTest extends TestCase
{
    use RefreshDatabase;

    private const VALIDATE_URL = '*/templates/validate';

    // ответ подменённого сервиса обработки; null — «шаблон корректен»
    private mixed $processorResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        // поля с регулярным выражением проверяет сервис обработки; в тестах — подменённый ответ
        Http::preventStrayRequests();
        Http::fake([self::VALIDATE_URL => fn () => $this->processorResponse ?? $this->processorAnswer([])]);
    }

    /** @param  list<array{field: string, message: string}>  $issues */
    private function processorAnswer(array $issues): mixed
    {
        return Http::response(['success' => true, 'data' => ['valid' => $issues === [], 'issues' => $issues], 'error' => null]);
    }

    private function ownTemplate(User $user, string $name = 'Мой счёт'): Template
    {
        $template = new Template(['document_type_id' => DocumentType::firstOrFail()->id, 'name' => $name]);
        $template->user()->associate($user)->save();

        return $template;
    }

    /** @param  array<string, mixed>  $attributes */
    private function addField(Template $template, array $attributes = []): TemplateField
    {
        return $template->fields()->create($attributes + [
            'name' => 'total', 'label' => 'Сумма', 'data_type' => DataType::Amount, 'sort_order' => 1,
        ]);
    }

    /** Открытая форма нового поля с обязательными значениями. */
    private function newFieldForm(User $user, Template $template, string $type = 'amount'): mixed
    {
        return Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->call('newField')
            ->set('field.name', 'total')
            ->set('field.label', 'Сумма')
            ->set('field.data_type', $type);
    }

    public function test_system_templates_for_four_document_types_are_created_by_migrations(): void
    {
        $data = json_decode((string) file_get_contents(database_path('data/system_templates.json')), true);

        $system = Template::where('is_system', true)->with('fields', 'documentType')->get();

        $this->assertCount(4, $system);
        foreach ($data as $item) {
            $template = $system->firstWhere('name', $item['name']);
            $this->assertNotNull($template, $item['name']);
            $this->assertNull($template->user_id);
            $this->assertSame($item['document_type'], $template->documentType->name);
            $this->assertTrue($template->isUsable());
            // шаблон для сервиса обработки совпадает с тем, на котором измерялось качество
            $this->assertEquals($item['fields'], $template->toProcessorPayload()['fields']);
        }
    }

    public function test_template_built_in_constructor_matches_contract_fixture(): void
    {
        // эту же фикстуру processor/tests/test_web_contract.py проверяет схемой сервиса
        $expected = json_decode((string) file_get_contents(base_path('tests/Fixtures/constructor_template.json')), true);
        $user = User::factory()->create();
        $template = $this->ownTemplate($user, $expected['name']);
        $page = Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template]);

        $page->call('newField')
            ->set('field.name', 'total')->set('field.label', 'Сумма к оплате')->set('field.data_type', 'amount')
            ->set('field.is_required', true)->set('field.keywords', "Итого к оплате\nВсего*")
            ->set('field.regex_pattern', '(\d+[.,]\d{2})')->set('field.llm_hint', 'Сумма с НДС')
            ->set('field.min_value', '0')->set('field.max_value', '1 000 000,50')
            ->call('saveField')->assertHasNoErrors();
        $page->call('newField')
            ->set('field.name', 'date')->set('field.label', 'Дата')->set('field.data_type', 'date')
            ->set('field.keywords', 'Дата')->set('field.min_date', '2020-01-01')->set('field.max_date', '2030-12-31')
            ->call('saveField')->assertHasNoErrors();
        $page->call('newField')
            ->set('field.name', 'signer')->set('field.label', 'Подписант')->set('field.data_type', 'person')
            ->set('field.ner_label', 'PER')->set('field.max_length', '150')
            ->call('saveField')->assertHasNoErrors();
        $page->call('newField')
            ->set('field.name', 'status')->set('field.label', 'Статус')->set('field.data_type', 'string')
            ->set('field.allowed_values', "оплачен\nне оплачен")->set('field.pattern', '[а-яё ]+')
            ->call('saveField')->assertHasNoErrors();

        $this->assertSame(
            json_encode($expected, JSON_UNESCAPED_UNICODE),
            json_encode($template->fresh()->toProcessorPayload(), JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('templates.index'))->assertRedirect(route('login'));
    }

    public function test_list_shows_system_and_own_templates_but_not_other_users(): void
    {
        $user = User::factory()->create();
        $this->ownTemplate($user, 'Мой шаблон');
        $this->ownTemplate(User::factory()->create(), 'Чужой шаблон');

        $this->actingAs($user)->get(route('templates.index'))
            ->assertOk()
            ->assertSee('Мой шаблон')
            ->assertSee('Счёт на оплату')
            ->assertSee('нет полей')
            ->assertDontSee('Чужой шаблон');

        $this->actingAs(User::factory()->admin()->create())->get(route('templates.index'))
            ->assertOk()
            ->assertDontSee('Мой шаблон');
    }

    public function test_template_without_fields_is_not_usable(): void
    {
        $template = $this->ownTemplate(User::factory()->create());
        $this->assertFalse($template->isUsable());

        $this->addField($template);
        $this->assertTrue($template->isUsable());
    }

    public function test_user_creates_own_template_and_cannot_make_it_system(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::templates.create')
            ->set('form.name', 'Счета поставщиков')
            ->set('form.description', 'Для бухгалтерии')
            ->set('is_system', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $template = Template::where('name', 'Счета поставщиков')->firstOrFail();
        $this->assertSame($user->id, $template->user_id);
        $this->assertFalse($template->is_system);
    }

    public function test_admin_creates_system_template_and_it_is_audited(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test('pages::templates.create')
            ->set('form.name', 'Накладная')
            ->set('is_system', true)
            ->call('save')
            ->assertHasNoErrors();

        $template = Template::where('name', 'Накладная')->firstOrFail();
        $this->assertTrue($template->is_system);
        $this->assertNull($template->user_id);
        $this->assertTrue(AuditLog::where('action', 'system_template_changed')->where('user_id', $admin->id)->exists());
    }

    public function test_template_requires_only_a_name(): void
    {
        $user = User::factory()->create();

        // сервис работает с любыми документами: тип документа не спрашивается
        $this->actingAs($user)->get(route('templates.create'))->assertOk()->assertDontSee('Тип документа');
        Livewire::actingAs($user)->test('pages::templates.create')
            ->set('form.name', '')
            ->call('save')
            ->assertHasErrors(['form.name']);
        Livewire::actingAs($user)->test('pages::templates.create')
            ->set('form.name', 'Любые документы')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Template::where('name', 'Любые документы')->firstOrFail()->document_type_id);
    }

    public function test_editing_system_template_keeps_its_document_type(): void
    {
        $system = Template::where('is_system', true)->firstOrFail();
        $type = $system->document_type_id;

        Livewire::actingAs(User::factory()->admin()->create())->test('pages::templates.edit', ['template' => $system])
            ->set('form.name', 'Счёт (изменён)')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertSame($type, $system->fresh()->document_type_id);
    }

    public function test_template_without_document_type_is_listed(): void
    {
        $user = User::factory()->create();
        $template = new Template(['name' => 'Без типа']);
        $template->user()->associate($user)->save();

        $this->actingAs($user)->get(route('templates.index'))->assertOk()->assertSee('Без типа');
    }

    public function test_edit_access_is_limited_to_owner_and_admin_for_system_templates(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $own = $this->ownTemplate($owner);
        $system = Template::where('is_system', true)->firstOrFail();

        $this->actingAs($owner)->get(route('templates.edit', $own))->assertOk();
        $this->actingAs($owner)->get(route('templates.edit', $system))->assertForbidden();
        $this->actingAs($admin)->get(route('templates.edit', $system))->assertOk();
        // чужой личный шаблон не виден никому, в том числе администратору: 404, как несуществующий
        $this->actingAs(User::factory()->create())->get(route('templates.edit', $own))->assertNotFound();
        $this->actingAs($admin)->get(route('templates.edit', $own))->assertNotFound();
    }

    public function test_template_header_can_be_updated(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->set('form.name', 'Новое название')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertSame('Новое название', $template->fresh()->name);
    }

    public function test_field_is_added_with_keywords_and_validation_rules(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        $this->newFieldForm($user, $template)
            ->set('field.label', 'Сумма к оплате')
            ->set('field.is_required', true)
            // неразрывные пробелы по краям обрезаются, как strip() в сервисе
            ->set('field.keywords', "Итого к оплате\n\n  Всего к оплате\u{00A0}\nИтого*")
            ->set('field.min_value', '0')
            ->set('field.max_value', '1000000.50')
            ->set('field.llm_hint', 'Сумма с НДС')
            ->call('saveField')
            ->assertHasNoErrors();

        $field = $template->fields()->firstOrFail();
        $this->assertSame(DataType::Amount, $field->data_type);
        $this->assertTrue($field->is_required);
        $this->assertSame(['Итого к оплате', 'Всего к оплате', 'Итого*'], $field->keywords);
        $this->assertEquals(['min_value' => '0', 'max_value' => '1000000.50'], $field->validation);
        // без регулярного выражения сервис обработки не нужен
        Http::assertNothingSent();
    }

    public function test_rules_not_applicable_to_data_type_are_not_saved(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        $this->newFieldForm($user, $template, 'string')
            ->set('field.min_value', '10')
            ->set('field.min_date', '2020-01-01')
            ->set('field.max_length', '50')
            ->set('field.allowed_values', "Москва\nКазань")
            ->call('saveField')
            ->assertHasNoErrors();

        $this->assertEquals(
            ['max_length' => 50, 'allowed_values' => ['Москва', 'Казань']],
            $template->fields()->firstOrFail()->validation,
        );
    }

    public function test_changing_data_type_drops_stale_range_rules(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);
        $field = $this->addField($template, ['validation' => ['min_value' => '10']]);

        Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->call('editField', $field->id)
            ->assertSet('field.min_value', '10')
            ->set('field.data_type', 'string')
            ->call('saveField')
            ->assertHasNoErrors();

        $this->assertNull($field->fresh()->validation);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidFields(): array
    {
        return [
            'имя не латиницей' => ['field.name', 'Сумма'],
            'имя с заглавной' => ['field.name', 'Total'],
            'короткий якорь' => ['field.keywords', "Итого\nа"],
            'звёздочка в середине' => ['field.keywords', 'Ито*го'],
            'слишком много якорей' => ['field.keywords', implode("\n", array_map(fn ($i) => "якорь{$i}", range(1, 21)))],
            'длинный якорь' => ['field.keywords', str_repeat('я', 101)],
            'lookahead в regex' => ['field.regex_pattern', '(?=\d)\d+'],
            'обратная ссылка' => ['field.regex_pattern', '(\d)\1'],
            'синтаксическая ошибка regex' => ['field.regex_pattern', '([0-9]'],
            'пустое совпадение regex' => ['field.regex_pattern', '\d*'],
            'неподдерживаемый формат значения' => ['field.pattern', '\d++'],
            'управляющие символы' => ['field.label', "Сумма\x07"],
            'неизвестная метка NER' => ['field.ner_label', 'MISC'],
            'сумма с тремя знаками' => ['field.min_value', '1.005'],
            'длинная подсказка' => ['field.llm_hint', str_repeat('я', 501)],
            'большая максимальная длина' => ['field.max_length', '10001'],
            'много допустимых значений' => ['field.allowed_values', implode("\n", range(1, 101))],
            'длинное допустимое значение' => ['field.allowed_values', str_repeat('я', 201)],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_field_is_rejected(string $property, string $value): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        $this->newFieldForm($user, $template)
            ->set($property, $value)
            ->call('saveField')
            ->assertHasErrors($property);

        $this->assertSame(0, $template->fields()->count());
        Http::assertNothingSent();
    }

    public function test_min_greater_than_max_is_rejected(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        $this->newFieldForm($user, $template, 'date')
            ->set('field.min_date', '2025-01-01')
            ->set('field.max_date', '2024-01-01')
            ->call('saveField')
            ->assertHasErrors('field.max_date');
    }

    public function test_regex_is_checked_by_processor_with_token(): void
    {
        config(['services.processor.url' => 'http://processor.test', 'services.processor.token' => 'test-token']);
        $this->app->forgetInstance(ProcessorClient::class);
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        $this->newFieldForm($user, $template)
            ->set('field.regex_pattern', '(\d+)')
            ->call('saveField')
            ->assertHasNoErrors();

        $this->assertSame('(\d+)', $template->fields()->firstOrFail()->regex_pattern);
        Http::assertSent(function (Request $request): bool {
            $sent = json_decode($request['template'], true);

            return $request->url() === 'http://processor.test/templates/validate'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $sent['fields'][0]['regex_pattern'] === '(\d+)';
        });
    }

    public function test_regex_rejected_by_processor_is_shown_on_its_field(): void
    {
        $this->processorResponse = $this->processorAnswer([
            ['field' => 'fields.0.regex_pattern', 'message' => 'регулярное выражение слишком сложное'],
        ]);
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        $this->newFieldForm($user, $template, 'string')
            ->set('field.regex_pattern', '\pL+')
            ->call('saveField')
            ->assertHasErrors('field.regex_pattern');

        $this->assertSame(0, $template->fields()->count());
    }

    public function test_value_format_rejected_by_processor_is_shown_on_its_field(): void
    {
        $this->processorResponse = $this->processorAnswer([
            ['field' => 'fields.0.validation.pattern', 'message' => 'регулярное выражение слишком сложное'],
        ]);
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        $this->newFieldForm($user, $template, 'string')
            ->set('field.pattern', '[А-Яа-яЁё]{1,100}')
            ->call('saveField')
            ->assertHasErrors('field.pattern');
    }

    public function test_regex_is_not_saved_when_processor_is_unavailable(): void
    {
        $this->processorResponse = Http::response('Bad gateway', 502);
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        $this->newFieldForm($user, $template)
            ->set('field.regex_pattern', '(\d+)')
            ->call('saveField')
            ->assertHasErrors('field.regex_pattern');

        $this->assertSame(0, $template->fields()->count());
    }

    public function test_explicit_field_name_must_be_unique_within_template(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);
        $this->addField($template);

        $this->newFieldForm($user, $template)
            ->set('field.label', 'Ещё сумма')
            ->call('saveField')
            ->assertHasErrors('field.name');
    }

    public function test_field_needs_only_a_label(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->call('newField')
            ->assertSet('field.data_type', 'auto')
            ->set('field.label', 'ИНН поставщика')
            ->call('saveField')
            ->assertHasNoErrors();

        $field = $template->fields()->firstOrFail();
        $this->assertSame('inn_postavschika', $field->name);
        $this->assertSame(DataType::Inn, $field->data_type);
    }

    public function test_generated_name_does_not_clash_with_existing_field(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);
        $this->addField($template, ['name' => 'summa', 'label' => 'Сумма']);

        Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->call('newField')
            ->set('field.label', 'Сумма')
            ->call('saveField')
            ->assertHasNoErrors();

        $this->assertSame(['summa', 'summa_2'], $template->fields()->pluck('name')->all());
    }

    public function test_field_without_label_shows_only_label_error(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->call('newField')
            ->call('saveField')
            ->assertHasErrors(['field.label'])
            ->assertHasNoErrors(['field.data_type', 'field.name']);

        $this->assertSame(0, $template->fields()->count());
    }

    public function test_type_and_name_are_guessed_again_after_failed_save(): void
    {
        // после ошибки пользователь меняет название — тип и имя должны угадываться по новому, а не остаться от старого
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->call('newField')
            ->set('field.label', 'Дата договора')
            ->set('field.keywords', '*')
            ->call('saveField')
            ->assertHasErrors('field.keywords')
            ->assertSet('field.data_type', 'auto')
            ->assertSet('field.name', '')
            ->set('field.label', 'Сумма договора')
            ->set('field.keywords', '')
            ->call('saveField')
            ->assertHasNoErrors();

        $field = $template->fields()->firstOrFail();
        $this->assertSame(DataType::Amount, $field->data_type);
        $this->assertSame('summa_dogovora', $field->name);
    }

    public function test_errors_from_advanced_section_are_shown_in_main_view(): void
    {
        // системное имя и регулярное выражение — в свёрнутом разделе: ошибку видно и без него
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->call('newField')
            ->set('field.label', 'Сумма')
            ->set('field.name', 'Плохое имя')
            ->call('saveField')
            ->assertHasErrors('field.name')
            ->assertSee('Исправьте в разделе «Для опытных пользователей»');
    }

    public function test_chosen_data_type_overrides_the_guess(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->call('newField')
            ->set('field.label', 'Сумма прописью')
            ->set('field.data_type', 'string')
            ->call('saveField')
            ->assertHasNoErrors();

        $this->assertSame(DataType::String, $template->fields()->firstOrFail()->data_type);
    }

    public function test_renaming_field_keeps_its_system_name(): void
    {
        // имя — столбец в выгрузке CSV/JSON: переименование поля не должно его менять
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);
        $field = $this->addField($template);

        Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->call('editField', $field->id)
            ->assertSet('field.data_type', 'amount')
            ->set('field.label', 'Итого с НДС')
            ->call('saveField')
            ->assertHasNoErrors();

        $this->assertSame('total', $field->fresh()->name);
    }

    public function test_field_form_hides_technical_settings_from_main_view(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->call('newField')
            ->assertSee('Определить по названию')
            ->assertSee('Для опытных пользователей');
    }

    public function test_existing_field_is_edited(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);
        $field = $this->addField($template);

        Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->call('editField', $field->id)
            ->assertSet('field.name', 'total')
            ->set('field.label', 'Итоговая сумма')
            ->call('saveField')
            ->assertHasNoErrors();

        $this->assertSame('Итоговая сумма', $field->fresh()->label);
        $this->assertSame(1, $template->fields()->count());
    }

    public function test_field_id_cannot_be_changed_from_the_browser(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);
        $field = $this->addField($template);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
            ->call('newField')
            ->set('field.id', $field->id);
    }

    public function test_field_of_another_template_cannot_be_edited_moved_or_deleted(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);
        $foreign = $this->addField($this->ownTemplate(User::factory()->create(), 'Чужой'));

        foreach (['editField' => [$foreign->id], 'deleteField' => [$foreign->id], 'moveField' => [$foreign->id, 1]] as $method => $args) {
            Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template])
                ->call($method, ...$args)
                ->assertNotFound();
        }

        $this->assertNotNull($foreign->fresh());
    }

    public function test_fields_are_deleted_and_reordered(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);
        $first = $this->addField($template, ['name' => 'first', 'sort_order' => 1]);
        $second = $this->addField($template, ['name' => 'second', 'sort_order' => 2]);
        $third = $this->addField($template, ['name' => 'third', 'sort_order' => 3]);

        $component = Livewire::actingAs($user)->test('pages::templates.edit', ['template' => $template]);
        $component->call('moveField', $third->id, -1);
        $this->assertSame(['first', 'third', 'second'], $template->fields()->pluck('name')->all());

        $component->call('moveField', $first->id, -1);
        $this->assertSame(['first', 'third', 'second'], $template->fields()->pluck('name')->all());

        $component->call('deleteField', $second->id);
        $this->assertSame(['first', 'third'], $template->fields()->pluck('name')->all());
    }

    public function test_template_field_limit_is_enforced(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);
        for ($i = 0; $i < Template::MAX_FIELDS; $i++) {
            $this->addField($template, ['name' => "f{$i}", 'sort_order' => $i]);
        }

        $this->newFieldForm($user, $template)
            ->set('field.name', 'extra')
            ->call('saveField')
            ->assertHasErrors('field.name');
    }

    public function test_regex_field_limit_is_enforced(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);
        for ($i = 0; $i < Template::MAX_REGEX_FIELDS; $i++) {
            $this->addField($template, ['name' => "f{$i}", 'sort_order' => $i, 'regex_pattern' => '(\d+)']);
        }

        $this->newFieldForm($user, $template)
            ->set('field.name', 'extra')
            ->set('field.regex_pattern', '(\d+)')
            ->call('saveField')
            ->assertHasErrors('field.regex_pattern');

        $this->assertSame(Template::MAX_REGEX_FIELDS, $template->fields()->count());
    }

    public function test_user_copies_system_template_to_own(): void
    {
        $user = User::factory()->create();
        $system = Template::where('is_system', true)->with('fields')->firstOrFail();

        Livewire::actingAs($user)->test('pages::templates.index')
            ->call('copy', $system->id)
            ->assertRedirect();

        $copy = Template::where('user_id', $user->id)->with('fields')->firstOrFail();
        $this->assertFalse($copy->is_system);
        $this->assertSame($system->document_type_id, $copy->document_type_id);
        $this->assertEquals($system->toProcessorPayload()['fields'], $copy->toProcessorPayload()['fields']);
    }

    public function test_copy_respects_own_template_limit(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < Template::MAX_PER_USER; $i++) {
            $this->ownTemplate($user, "Шаблон {$i}");
        }

        Livewire::actingAs($user)->test('pages::templates.index')
            ->call('copy', Template::where('is_system', true)->firstOrFail()->id)
            ->assertNoRedirect();

        $this->assertSame(Template::MAX_PER_USER, $user->templates()->count());
    }

    public function test_other_users_template_cannot_be_copied_or_deleted_even_by_admin(): void
    {
        $foreign = $this->ownTemplate(User::factory()->create(), 'Чужой');

        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $user) {
            foreach (['copy', 'delete'] as $method) {
                Livewire::actingAs($user)->test('pages::templates.index')
                    ->call($method, $foreign->id)
                    ->assertNotFound();
            }
        }

        $this->assertNotNull($foreign->fresh());
        $this->assertSame(1, Template::where('is_system', false)->count());
    }

    public function test_user_cannot_delete_system_template_but_admin_can_and_it_is_audited(): void
    {
        $system = Template::where('is_system', true)->firstOrFail();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs(User::factory()->create())->test('pages::templates.index')
            ->call('delete', $system->id)
            ->assertForbidden();
        $this->assertNotNull($system->fresh());

        Livewire::actingAs($admin)->test('pages::templates.index')->call('delete', $system->id);

        $this->assertNull($system->fresh());
        $entry = AuditLog::where('action', 'system_template_changed')->firstOrFail();
        $this->assertSame($admin->id, $entry->user_id);
        // assertEquals: jsonb в PostgreSQL хранит ключи в своём порядке
        $this->assertEquals(['template_id' => $system->id, 'change' => 'deleted'], $entry->details);
    }

    public function test_changes_to_own_template_are_not_audited(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);

        $this->newFieldForm($user, $template)->call('saveField')->assertHasNoErrors();

        $this->assertFalse(AuditLog::where('action', 'system_template_changed')->exists());
    }

    public function test_template_changes_are_rate_limited(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);
        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit('template-writes:'.$user->id);
        }

        $this->newFieldForm($user, $template)->call('saveField');

        $this->assertSame(0, $template->fields()->count());
    }

    public function test_deleting_template_keeps_processed_documents(): void
    {
        $user = User::factory()->create();
        $template = $this->ownTemplate($user);
        $this->addField($template);
        $document = new Document([
            'template_id' => $template->id, 'file_name' => 'счёт.pdf', 'file_format' => 'pdf',
            'size_bytes' => 100, 'sha256' => str_repeat('a', 64),
        ]);
        $document->forceFill(['storage_key' => 'documents/test.pdf'])->user()->associate($user)->save();

        Livewire::actingAs($user)->test('pages::templates.index')->call('delete', $template->id);

        $this->assertNull($template->fresh());
        $this->assertSame(0, TemplateField::where('template_id', $template->id)->count());
        $this->assertNull($document->fresh()->template_id);
    }

    public function test_own_template_limit_is_enforced(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < Template::MAX_PER_USER; $i++) {
            $this->ownTemplate($user, "Шаблон {$i}");
        }

        Livewire::actingAs($user)->test('pages::templates.create')
            ->set('form.name', 'Лишний')
            ->call('save')
            ->assertHasErrors('form.name');
    }
}
