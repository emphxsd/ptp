<?php

namespace Tests\Feature\Documents;

use App\Enums\DataType;
use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n%синтетический документ\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Queue::fake();
    }

    private function system(): Template
    {
        return Template::where('is_system', true)->orderBy('id')->firstOrFail();
    }

    private function pdf(string $name = 'счёт.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('documents.index'))->assertRedirect(route('login'));
    }

    public function test_page_offers_only_visible_templates_with_fields(): void
    {
        $user = User::factory()->create();
        $empty = new Template(['document_type_id' => DocumentType::firstOrFail()->id, 'name' => 'Пустой шаблон']);
        $empty->user()->associate($user)->save();
        $foreign = new Template(['document_type_id' => DocumentType::firstOrFail()->id, 'name' => 'Чужой шаблон']);
        $foreign->user()->associate(User::factory()->create())->save();
        $foreign->fields()->create(['name' => 'f', 'label' => 'F', 'data_type' => DataType::String]);

        $this->actingAs($user)->get(route('documents.index'))
            ->assertOk()
            ->assertSee('Счёт на оплату')
            ->assertDontSee('Пустой шаблон')
            ->assertDontSee('Чужой шаблон');
    }

    public function test_uploaded_files_are_stored_and_queued_for_processing(): void
    {
        $user = User::factory()->create();
        $template = $this->system();

        Livewire::actingAs($user)->test('pages::documents.index')
            ->set('template_id', $template->id)
            ->set('use_llm', false)
            ->set('files', [
                $this->pdf('счёт №1.pdf'),
                UploadedFile::fake()->createWithContent('скан.png', "\x89PNG\r\n\x1a\n".str_repeat("\0", 16)),
            ])
            ->call('process')
            ->assertHasNoErrors();

        $documents = Document::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $documents);
        [$pdf, $png] = $documents;

        $this->assertSame('счёт №1.pdf', $pdf->file_name);
        $this->assertSame('pdf', $pdf->file_format);
        $this->assertSame('png', $png->file_format);
        $this->assertSame($template->id, $pdf->template_id);
        $this->assertFalse($pdf->use_llm);
        $this->assertSame(DocumentStatus::Uploaded, $pdf->status);
        $this->assertSame(strlen(self::PDF), $pdf->size_bytes);
        $this->assertSame(hash('sha256', self::PDF), $pdf->sha256);
        // имя файла на диске не зависит от имени, которое дал пользователь
        $this->assertStringStartsWith($user->id.'/', $pdf->storage_key);
        $this->assertStringNotContainsString('счёт', $pdf->storage_key);
        Storage::disk('documents')->assertExists($pdf->storage_key);
        $this->assertSame(self::PDF, Storage::disk('documents')->get($pdf->storage_key));

        Queue::assertPushed(ProcessDocument::class, 2);
        Queue::assertPushed(ProcessDocument::class, fn (ProcessDocument $job) => $job->document->is($pdf));
        $this->assertSame(2, AuditLog::where('action', 'document_uploaded')->where('user_id', $user->id)->count());
    }

    public function test_format_is_detected_by_content_not_by_extension(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::documents.index')
            ->set('template_id', $this->system()->id)
            ->set('files', [UploadedFile::fake()->createWithContent('вирус.pdf', "MZ\x90\0 not a pdf")])
            ->call('process')
            ->assertHasErrors('files.0');

        $this->assertSame(0, Document::count());
        Queue::assertNothingPushed();
    }

    public function test_unsupported_extension_is_rejected(): void
    {
        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->set('template_id', $this->system()->id)
            ->set('files', [UploadedFile::fake()->createWithContent('документ.txt', self::PDF)])
            ->call('process')
            ->assertHasErrors('files.0');

        $this->assertSame(0, Document::count());
    }

    public function test_too_large_file_is_rejected(): void
    {
        $tooLarge = UploadedFile::fake()->create('большой.pdf', config('documents.max_file_mb') * 1024 + 1, 'application/pdf');

        // слишком большой файл Livewire отклоняет ещё при выборе (временная загрузка), до нажатия «Обработать»
        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->set('template_id', $this->system()->id)
            ->set('files', [$tooLarge])
            ->assertHasErrors('files.0')
            ->call('process')
            ->assertHasErrors('files');

        $this->assertSame(0, Document::count());
    }

    public function test_too_many_files_at_once_are_rejected(): void
    {
        $files = array_map(fn (int $i) => $this->pdf("{$i}.pdf"), range(1, config('documents.max_files_per_upload') + 1));

        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->set('template_id', $this->system()->id)
            ->set('files', $files)
            ->call('process')
            ->assertHasErrors('files');

        $this->assertSame(0, Document::count());
    }

    public function test_template_must_be_visible_and_have_fields(): void
    {
        $user = User::factory()->create();
        $foreign = new Template(['document_type_id' => DocumentType::firstOrFail()->id, 'name' => 'Чужой']);
        $foreign->user()->associate(User::factory()->create())->save();
        $foreign->fields()->create(['name' => 'f', 'label' => 'F', 'data_type' => DataType::String]);
        $empty = new Template(['document_type_id' => DocumentType::firstOrFail()->id, 'name' => 'Пустой']);
        $empty->user()->associate($user)->save();

        foreach ([null, $foreign->id, $empty->id, 999999] as $templateId) {
            Livewire::actingAs($user)->test('pages::documents.index')
                ->set('template_id', $templateId)
                ->set('files', [$this->pdf()])
                ->call('process')
                ->assertHasErrors('template_id');
        }

        $this->assertSame(0, Document::count());
    }

    public function test_uploads_are_rate_limited(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < config('documents.uploads_per_minute'); $i++) {
            RateLimiter::hit('document-uploads:'.$user->id);
        }

        Livewire::actingAs($user)->test('pages::documents.index')
            ->set('template_id', $this->system()->id)
            ->set('files', [$this->pdf()])
            ->call('process');

        $this->assertSame(0, Document::count());
    }

    public function test_llm_is_off_unless_user_opts_in(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::documents.index')
            ->assertSet('use_llm', false)
            ->set('template_id', $this->system()->id)
            ->set('files', [$this->pdf()])
            ->call('process')
            ->assertHasNoErrors();

        $document = Document::where('user_id', $user->id)->firstOrFail();
        $this->assertFalse($document->use_llm);
        $this->assertFalse(AuditLog::where('action', 'document_uploaded')->firstOrFail()->details['use_llm']);
    }

    public function test_llm_consent_is_recorded_when_given(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::documents.index')
            ->set('template_id', $this->system()->id)
            ->set('use_llm', true)
            ->set('files', [$this->pdf()])
            ->call('process');

        $this->assertTrue(Document::where('user_id', $user->id)->firstOrFail()->use_llm);
        $this->assertTrue(AuditLog::where('action', 'document_uploaded')->firstOrFail()->details['use_llm']);
    }

    public function test_llm_cannot_be_used_when_disabled_globally(): void
    {
        config(['documents.llm_enabled' => false]);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::documents.index')
            ->assertDontSee('Использовать LLM')
            ->set('template_id', $this->system()->id)
            ->set('use_llm', true)
            ->set('files', [$this->pdf()])
            ->call('process');

        $this->assertFalse(Document::where('user_id', $user->id)->firstOrFail()->use_llm);
    }

    public function test_template_placeholder_is_selectable_so_first_template_is_not_shown_as_chosen(): void
    {
        // неактивную подсказку браузер подменяет первым шаблоном: на экране «Акт», а не выбран никакой,
        // и повторный выбор «Акта» не доходит до сервера
        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->assertDontSeeHtml('class="placeholder"')
            ->assertSeeHtml('value=""')
            ->set('template_id', '')
            ->assertSet('template_id', null)
            ->set('files', [$this->pdf()])
            ->call('process')
            ->assertHasErrors(['template_id' => 'Выберите шаблон.']);
    }

    public function test_local_llm_option_says_document_stays_on_server(): void
    {
        config(['documents.llm_external' => false]);

        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->assertSee('локальной моделью')
            ->assertSee('никуда не передаётся')
            ->assertDontSee('внешний сервис');
    }

    public function test_external_llm_option_warns_about_personal_data(): void
    {
        config(['documents.llm_external' => true]);

        Livewire::actingAs(User::factory()->create())->test('pages::documents.index')
            ->assertSee('внешний сервис')
            ->assertSee('без согласия на их обработку')
            ->assertDontSee('локальной моделью');
    }

    public function test_batch_that_exceeds_remaining_rate_limit_is_rejected_entirely(): void
    {
        $user = User::factory()->create();
        for ($i = 1; $i < config('documents.uploads_per_minute'); $i++) {
            RateLimiter::hit('document-uploads:'.$user->id);
        }

        Livewire::actingAs($user)->test('pages::documents.index')
            ->set('template_id', $this->system()->id)
            ->set('files', [$this->pdf('1.pdf'), $this->pdf('2.pdf')])
            ->call('process');

        $this->assertSame(0, Document::count());
    }

    public function test_document_count_quota_is_enforced(): void
    {
        config(['documents.max_documents_per_user' => 2]);
        $user = User::factory()->create();

        $page = Livewire::actingAs($user)->test('pages::documents.index')->set('template_id', $this->system()->id);
        $page->set('files', [$this->pdf('1.pdf')])->call('process')->assertHasNoErrors();
        $page->set('files', [$this->pdf('2.pdf'), $this->pdf('3.pdf')])->call('process')->assertHasErrors('files');

        $this->assertSame(1, Document::count());
    }

    public function test_storage_quota_is_enforced(): void
    {
        config(['documents.max_storage_mb_per_user' => 1]);
        $user = User::factory()->create();
        $existing = new Document([
            'template_id' => $this->system()->id, 'file_name' => 'старый.pdf', 'file_format' => 'pdf',
            'size_bytes' => 1024 * 1024 - 10, 'sha256' => str_repeat('b', 64),
        ]);
        $existing->forceFill(['storage_key' => $user->id.'/old.pdf'])->user()->associate($user)->save();

        Livewire::actingAs($user)->test('pages::documents.index')
            ->set('template_id', $this->system()->id)
            ->set('files', [$this->pdf()])
            ->call('process')
            ->assertHasErrors('files');

        $this->assertSame(1, Document::count());
    }

    public function test_control_characters_and_paths_are_removed_from_file_name(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::documents.index')
            ->set('template_id', $this->system()->id)
            ->set('files', [$this->pdf("..\\..\\evil\u{202E}fdp.exe\x07\u{200B}\u{FEFF}\u{2028}.pdf")])
            ->call('process')
            ->assertHasNoErrors();

        $name = Document::where('user_id', $user->id)->value('file_name');
        $this->assertStringNotContainsString('\\', $name);
        $this->assertStringNotContainsString('/', $name);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f\x{200B}\x{2028}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', $name);
        $this->assertStringEndsWith('.pdf', $name);
    }
}
