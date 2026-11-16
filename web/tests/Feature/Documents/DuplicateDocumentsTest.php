<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Template;
use App\Models\User;
use App\Support\DuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ProcessedDocuments;
use Tests\TestCase;

/** Защита от повторной оплаты: тот же файл или тот же счёт (номер, сумма, ИНН), загруженный ещё раз. */
class DuplicateDocumentsTest extends TestCase
{
    use ProcessedDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Queue::fake();
    }

    private function uploadPage(User $user, string $name = 'счёт.pdf'): mixed
    {
        return Livewire::actingAs($user)->test('pages::documents.index')
            ->set('template_id', Template::where('is_system', true)->value('id'))
            ->set('files', [UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nсчёт 15")])
            ->call('process')
            ->assertHasNoErrors();
    }

    public function test_same_file_uploaded_again_is_marked_as_duplicate(): void
    {
        $user = User::factory()->create();

        $this->uploadPage($user);
        $this->uploadPage($user);

        [$first, $second] = Document::orderBy('id')->get()->all();
        $this->assertNull($first->duplicate_of_id);
        $this->assertSame($first->id, $second->duplicate_of_id);
        $this->assertSame(DuplicateDetector::SAME_FILE, $second->duplicate_reason);
        $this->actingAs($user)->get(route('documents.index'))->assertSee('дубликат');
    }

    public function test_upload_warns_about_duplicates(): void
    {
        $user = User::factory()->create();
        $this->uploadPage($user);

        $this->uploadPage($user, 'копия.pdf')->assertDispatched(
            'toast-show',
            fn (string $event, array $params) => str_contains(json_encode($params, JSON_UNESCAPED_UNICODE), 'уже загружали'),
        );
    }

    public function test_same_file_of_another_user_is_not_a_duplicate(): void
    {
        $this->uploadPage(User::factory()->create());
        $this->uploadPage(User::factory()->create());

        $this->assertSame(0, Document::whereNotNull('duplicate_of_id')->count());
    }

    public function test_same_invoice_number_amount_and_inn_is_a_duplicate(): void
    {
        $user = User::factory()->create();
        $original = $this->processedInvoice($user);
        $copy = $this->processedInvoice($user, attributes: ['file_name' => 'скан счёта.jpg']);

        app(DuplicateDetector::class)->refreshContent($copy);

        $copy->refresh();
        $this->assertSame($original->id, $copy->duplicate_of_id);
        $this->assertSame(DuplicateDetector::SAME_CONTENT, $copy->duplicate_reason);
    }

    public function test_different_number_amount_or_inn_is_not_a_duplicate(): void
    {
        $user = User::factory()->create();
        $this->processedInvoice($user);
        $detector = app(DuplicateDetector::class);

        foreach ([['number' => '16'], ['total' => '15000.01'], ['supplier_inn' => '7728168971']] as $changed) {
            $other = $this->processedInvoice($user, $changed);
            $detector->refreshContent($other);
            $this->assertNull($other->fresh()->duplicate_of_id, 'отличается: '.array_key_first($changed));
        }
    }

    public function test_invoice_without_number_or_amount_is_not_compared(): void
    {
        $user = User::factory()->create();
        $this->processedInvoice($user, ['number' => null]);
        $copy = $this->processedInvoice($user, ['number' => null]);

        app(DuplicateDetector::class)->refreshContent($copy);

        $this->assertNull($copy->fresh()->duplicate_of_id);
    }

    public function test_documents_of_other_users_and_failed_documents_are_ignored(): void
    {
        $user = User::factory()->create();
        $this->processedInvoice(User::factory()->create());
        $this->processedInvoice($user, attributes: ['status' => DocumentStatus::Failed]);
        $copy = $this->processedInvoice($user);

        app(DuplicateDetector::class)->refreshContent($copy);

        $this->assertNull($copy->fresh()->duplicate_of_id);
    }

    public function test_correcting_number_updates_duplicate_mark(): void
    {
        $user = User::factory()->create();
        $this->processedInvoice($user);
        $copy = $this->processedInvoice($user, ['number' => '1S']); // OCR прочитал «15» как «1S»
        $number = $copy->latestResult->values()->where('field_name', 'number')->firstOrFail();

        $page = Livewire::actingAs($user)->test('pages::documents.show', ['document' => $copy])
            ->call('editValue', $number->id)->set('editedValue', '15')->call('saveValue')->assertHasNoErrors();
        $this->assertNotNull($copy->fresh()->duplicate_of_id);

        $page->call('editValue', $number->id)->set('editedValue', '16')->call('saveValue')->assertHasNoErrors();
        $this->assertNull($copy->fresh()->duplicate_of_id);
    }

    public function test_result_page_links_to_the_original(): void
    {
        $user = User::factory()->create();
        $original = $this->processedInvoice($user, attributes: ['file_name' => 'счёт поставщика.pdf']);
        $copy = $this->processedInvoice($user);
        app(DuplicateDetector::class)->refreshContent($copy);

        $this->actingAs($user)->get(route('documents.show', $copy))
            ->assertSee('Возможный дубликат')
            ->assertSee('счёт поставщика.pdf')
            ->assertSee(route('documents.show', $original), false);
    }

    public function test_deleting_the_original_clears_the_mark(): void
    {
        $user = User::factory()->create();
        $original = $this->processedInvoice($user);
        $copy = $this->processedInvoice($user);
        app(DuplicateDetector::class)->refreshContent($copy);

        $original->delete();

        $this->assertNull($copy->fresh()->duplicate_of_id);
    }
}
