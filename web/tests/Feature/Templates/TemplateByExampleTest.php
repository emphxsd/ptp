<?php

namespace Tests\Feature\Templates;

use App\Enums\DataType;
use App\Models\Template;
use App\Models\User;
use App\Services\Processor\ProcessorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Шаблон по примеру: пользователь выделяет значения в тексте документа, поля создаются сами. */
class TemplateByExampleTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = "Счёт на оплату № 15 от 01.03.2025\nПоставщик: ООО «Ромашка», ИНН 7707083893\nИтого к оплате: 15 000,00";

    // ответ сервиса на /text; первая подмена Http::fake побеждает, поэтому ответ меняется через свойство
    private mixed $textResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.processor.url' => 'http://processor.test', 'services.processor.token' => 'test-token']);
        $this->app->forgetInstance(ProcessorClient::class);
        Http::fake(['*/text' => fn () => $this->textResponse
            ?? Http::response(['success' => true, 'data' => ['text' => self::TEXT, 'pages' => 1, 'ocr_pages' => 0], 'error' => null])]);
    }

    /** Страница с загруженным примером. */
    private function page(User $user): mixed
    {
        return Livewire::actingAs($user)->test('pages::templates.example')
            ->set('file', UploadedFile::fake()->createWithContent('счёт.pdf', "%PDF-1.4\nсчёт"))
            ->assertHasNoErrors()
            ->assertSet('text', self::TEXT);
    }

    private function offset(string $fragment): int
    {
        return mb_strpos(self::TEXT, $fragment);
    }

    public function test_templates_page_links_to_the_example_constructor(): void
    {
        $this->actingAs(User::factory()->create())->get(route('templates.index'))
            ->assertSee(route('templates.example'), false)
            ->assertSee('Создать по примеру');
    }

    public function test_selected_value_becomes_a_field_with_label_anchor_and_type(): void
    {
        $page = $this->page(User::factory()->create())
            ->call('addField', '15 000,00', $this->offset('15 000,00'), '')
            ->assertHasNoErrors();

        $field = $page->get('fields')[0];
        $this->assertSame('Итого к оплате', $field['label']);
        $this->assertSame(['Итого к оплате'], $field['keywords']);
        $this->assertSame(DataType::Amount->value, $field['data_type']);
    }

    public function test_user_label_wins_and_type_comes_from_the_value(): void
    {
        $page = $this->page(User::factory()->create())
            ->call('addField', '7707083893', $this->offset('7707083893'), 'Налоговый номер поставщика');

        $field = $page->get('fields')[0];
        $this->assertSame('Налоговый номер поставщика', $field['label']);
        $this->assertSame(['ИНН'], $field['keywords']);
        $this->assertSame(DataType::Inn->value, $field['data_type']);
    }

    public function test_selection_must_match_the_document_text(): void
    {
        $this->page(User::factory()->create())
            ->call('addField', 'чего нет в документе', 3, '')
            ->assertHasErrors('selection');
    }

    public function test_template_is_saved_with_selected_fields(): void
    {
        $user = User::factory()->create();

        $this->page($user)
            ->call('addField', '15 000,00', $this->offset('15 000,00'), '')
            ->call('addField', '01.03.2025', $this->offset('01.03.2025'), 'Дата счёта')
            ->call('removeField', 1)
            ->call('addField', '7707083893', $this->offset('7707083893'), '')
            ->set('name', 'Счета Ромашки')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $template = Template::where('name', 'Счета Ромашки')->with('fields')->firstOrFail();
        $this->assertSame($user->id, $template->user_id);
        $this->assertFalse($template->is_system);
        $this->assertSame(['Итого к оплате', 'ИНН'], $template->fields->pluck('label')->all());
        $this->assertSame([DataType::Amount, DataType::Inn], $template->fields->pluck('data_type')->all());
    }

    public function test_template_needs_a_name_and_at_least_one_field(): void
    {
        $this->page(User::factory()->create())
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name', 'fields']);
    }

    public function test_unavailable_service_shows_an_error(): void
    {
        $this->textResponse = Http::response(null, 503);

        Livewire::actingAs(User::factory()->create())->test('pages::templates.example')
            ->set('file', UploadedFile::fake()->createWithContent('счёт.pdf', "%PDF-1.4\nсчёт"))
            ->assertHasErrors('file')
            ->assertSet('text', '');
    }
}
