<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Queue::fake();
    }

    private function document(User $user, string $name, DocumentStatus $status = DocumentStatus::Done): Document
    {
        $document = new Document([
            'template_id' => Template::where('is_system', true)->value('id'), 'file_name' => $name,
            'file_format' => 'pdf', 'size_bytes' => 9, 'sha256' => hash('sha256', $name),
        ]);
        $document->forceFill(['storage_key' => $user->id.'/'.md5($name).'.pdf', 'status' => $status])
            ->user()->associate($user)->save();
        Storage::disk('documents')->put($document->storage_key, '%PDF-1.4');

        return $document;
    }

    public function test_list_shows_only_own_documents_with_statuses(): void
    {
        $user = User::factory()->create();
        $this->document($user, 'мой-счёт.pdf');
        $this->document($user, 'битый.pdf', DocumentStatus::Failed);
        $this->document(User::factory()->create(), 'чужой.pdf');

        $this->actingAs($user)->get(route('documents.index'))
            ->assertOk()
            ->assertSee('мой-счёт.pdf')
            ->assertSee('битый.pdf')
            ->assertSee(DocumentStatus::Done->label())
            ->assertSee(DocumentStatus::Failed->label())
            ->assertDontSee('чужой.pdf');
    }

    public function test_upload_time_is_shown_in_display_timezone(): void
    {
        config(['app.display_timezone' => 'Europe/Moscow']);
        $user = User::factory()->create();
        $this->document($user, 'счёт.pdf')->forceFill(['uploaded_at' => '2026-01-15 12:00:00'])->save();

        $this->actingAs($user)->get(route('documents.index'))->assertSee('15.01.2026 15:00');
    }

    public function test_list_refreshes_while_documents_are_processing(): void
    {
        $user = User::factory()->create();
        $this->document($user, 'готов.pdf');

        Livewire::actingAs($user)->test('pages::documents.index')->assertDontSeeHtml('wire:poll');

        $this->document($user, 'в-работе.pdf', DocumentStatus::Processing);

        Livewire::actingAs($user)->test('pages::documents.index')->assertSeeHtml('wire:poll');
    }

    public function test_failed_document_can_be_processed_again(): void
    {
        $user = User::factory()->create();
        $failed = $this->document($user, 'битый.pdf', DocumentStatus::Failed);
        $done = $this->document($user, 'готов.pdf');

        Livewire::actingAs($user)->test('pages::documents.index')
            ->call('retry', $failed->id)
            ->call('retry', $done->id);

        $this->assertSame(DocumentStatus::Uploaded, $failed->fresh()->status);
        $this->assertSame(DocumentStatus::Done, $done->fresh()->status);
        Queue::assertPushed(ProcessDocument::class, 1);
    }

    public function test_document_is_deleted_with_its_file(): void
    {
        $user = User::factory()->create();
        $document = $this->document($user, 'удалить.pdf');

        Livewire::actingAs($user)->test('pages::documents.index')->call('delete', $document->id);

        $this->assertNull($document->fresh());
        Storage::disk('documents')->assertMissing($document->storage_key);
    }

    public function test_other_users_documents_cannot_be_retried_or_deleted(): void
    {
        $foreign = $this->document(User::factory()->create(), 'чужой.pdf', DocumentStatus::Failed);

        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $user) {
            foreach (['retry', 'delete'] as $method) {
                Livewire::actingAs($user)->test('pages::documents.index')
                    ->call($method, $foreign->id)
                    ->assertNotFound();
            }
        }

        $this->assertNotNull($foreign->fresh());
        Storage::disk('documents')->assertExists($foreign->storage_key);
        Queue::assertNothingPushed();
    }

    public function test_documents_older_than_retention_period_are_pruned_with_files(): void
    {
        config(['documents.retention_days' => 30]);
        $user = User::factory()->create();
        $old = $this->document($user, 'старый.pdf');
        $old->forceFill(['uploaded_at' => now()->subDays(31)])->save();
        $recent = $this->document($user, 'новый.pdf');

        $this->artisan('model:prune', ['--model' => [Document::class]])->assertSuccessful();

        $this->assertNull($old->fresh());
        Storage::disk('documents')->assertMissing($old->storage_key);
        $this->assertNotNull($recent->fresh());
        Storage::disk('documents')->assertExists($recent->storage_key);
    }

    public function test_abandoned_temporary_uploads_are_removed(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('livewire-tmp/old-upload.pdf', '%PDF-1.4');
        Storage::disk('local')->put('livewire-tmp/fresh-upload.pdf', '%PDF-1.4');
        touch(Storage::disk('local')->path('livewire-tmp/old-upload.pdf'), now()->subHours(2)->getTimestamp());

        $this->artisan('app:prune-temporary-uploads')->assertSuccessful();

        Storage::disk('local')->assertMissing('livewire-tmp/old-upload.pdf');
        Storage::disk('local')->assertExists('livewire-tmp/fresh-upload.pdf');
    }

    public function test_deleting_user_removes_their_files(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->document($user, 'мой.pdf');
        $theirs = $this->document($other, 'чужой.pdf');

        $user->delete();

        Storage::disk('documents')->assertMissing($mine->storage_key);
        Storage::disk('documents')->assertExists($theirs->storage_key);
    }
}
