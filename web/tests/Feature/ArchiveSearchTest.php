<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ProcessedDocuments;
use Tests\TestCase;

/** Поиск по архиву: запрос на естественном языке по извлечённым значениям документов пользователя. */
class ArchiveSearchTest extends TestCase
{
    use ProcessedDocuments, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->user = User::factory()->create();
        $this->processedInvoice($this->user, ['supplier' => 'ООО "Ромашка"', 'date' => '2025-03-01', 'total' => '150000.00'], ['file_name' => 'ромашка-март.pdf']);
        $this->processedInvoice($this->user, ['supplier' => 'ООО "Ромашка"', 'date' => '2024-11-20', 'total' => '250000.00'], ['file_name' => 'ромашка-2024.pdf']);
        $this->processedInvoice($this->user, ['supplier' => 'АО "Лотос"', 'date' => '2025-05-10', 'total' => '90000.00', 'supplier_inn' => '7728168971'], ['file_name' => 'лотос.pdf']);
        $this->processedInvoice($this->user, ['supplier' => 'ООО "Ромашка"', 'date' => '2025-06-01', 'total' => '500000.00'], ['file_name' => 'в-очереди.pdf', 'status' => DocumentStatus::Processing]);
        $this->processedInvoice(User::factory()->create(), ['supplier' => 'ООО "Ромашка"', 'date' => '2025-03-01', 'total' => '150000.00'], ['file_name' => 'чужой.pdf']);
    }

    private function search(string $query): mixed
    {
        return Livewire::actingAs($this->user)->test('pages::search')->set('q', $query);
    }

    public function test_example_query_finds_matching_documents_only(): void
    {
        $this->search('все счета с ООО Ромашка за 2025 год дороже 100 тыс.')
            ->assertSee('ромашка-март.pdf')
            ->assertDontSee('ромашка-2024.pdf')
            ->assertDontSee('лотос.pdf')
            ->assertDontSee('в-очереди.pdf')
            ->assertDontSee('чужой.pdf')
            ->assertSee('Организация: Ромашка');
    }

    public function test_type_from_another_template_finds_nothing(): void
    {
        $this->search('договоры с ООО Ромашка')->assertSee('Ничего не найдено')->assertDontSee('ромашка-март.pdf');
    }

    public function test_inn_and_amount_limit(): void
    {
        $this->search('ИНН 7728168971 до 100 000')->assertSee('лотос.pdf')->assertDontSee('ромашка-март.pdf');
    }

    public function test_free_words_search_all_values(): void
    {
        $this->search('Лотос')->assertSee('лотос.pdf')->assertDontSee('ромашка-март.pdf');
    }

    public function test_query_is_kept_in_the_address(): void
    {
        $this->actingAs($this->user)->get(route('search', ['q' => 'Лотос']))->assertOk()->assertSee('лотос.pdf');
    }

    public function test_search_is_in_the_menu(): void
    {
        $this->actingAs($this->user)->get(route('dashboard'))->assertSee(route('search'), false);
    }
}
