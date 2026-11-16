<?php

namespace Tests\Feature\Documents;

use App\Models\Template;
use App\Models\User;
use App\Services\Processor\ProcessorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Автоопределение типа документа: после выбора файла сервис предлагает подходящий шаблон. */
class TemplateSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSIFY_URL = '*/classify';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.processor.url' => 'http://processor.test', 'services.processor.token' => 'test-token']);
        $this->app->forgetInstance(ProcessorClient::class);
    }

    private function invoice(): Template
    {
        return Template::where('is_system', true)->where('name', 'Счёт на оплату')->firstOrFail();
    }

    private function answer(?int $templateId): void
    {
        Http::fake([self::CLASSIFY_URL => Http::response([
            'success' => true,
            'data' => ['template_id' => $templateId, 'confidence' => 0.8, 'scores' => []],
            'error' => null,
        ])]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('счёт.pdf', "%PDF-1.4\nСчёт на оплату");
    }

    public function test_choosing_a_file_suggests_and_selects_template(): void
    {
        $this->answer($this->invoice()->id);

        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->set('files', [$this->pdf()])
            ->assertSet('template_id', $this->invoice()->id)
            ->assertSee('Похоже на «Счёт на оплату»');

        Http::assertSent(function (Request $request): bool {
            $templates = json_decode((string) collect($request->data())->firstWhere('name', 'templates')['contents'], true);
            $invoice = collect($templates)->firstWhere('name', 'Счёт на оплату');

            return $request->url() === 'http://processor.test/classify'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && in_array('Итого к оплате', $invoice['phrases'], true);
        });
    }

    public function test_template_chosen_by_user_is_not_replaced(): void
    {
        $this->answer($this->invoice()->id);
        $act = Template::where('is_system', true)->where('name', 'like', 'Акт%')->firstOrFail();

        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->set('template_id', $act->id)
            ->set('files', [$this->pdf()])
            ->assertSet('template_id', $act->id);

        Http::assertNothingSent();
    }

    public function test_no_confident_match_leaves_choice_to_user(): void
    {
        $this->answer(null);

        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->set('files', [$this->pdf()])
            ->assertSet('template_id', null)
            ->assertDontSee('Похоже на');
    }

    public function test_unavailable_service_does_not_break_upload(): void
    {
        Http::fake([self::CLASSIFY_URL => Http::response(null, 503)]);

        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->set('files', [$this->pdf()])
            ->assertHasNoErrors()
            ->assertSet('template_id', null);
    }

    public function test_template_not_visible_to_user_is_ignored(): void
    {
        $foreign = new Template(['name' => 'Чужой шаблон']);
        $foreign->user()->associate(User::factory()->create())->save();
        $foreign->fields()->create(['name' => 'x', 'label' => 'Икс', 'data_type' => 'string', 'sort_order' => 1]);
        $this->answer($foreign->id);

        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->set('files', [$this->pdf()])
            ->assertSet('template_id', null);
    }

    public function test_changing_template_by_hand_removes_the_hint(): void
    {
        $this->answer($this->invoice()->id);
        $act = Template::where('is_system', true)->where('name', 'like', 'Акт%')->firstOrFail();

        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->set('files', [$this->pdf()])
            ->assertSee('Похоже на')
            ->set('template_id', $act->id)
            ->assertDontSee('Похоже на');
    }
}
