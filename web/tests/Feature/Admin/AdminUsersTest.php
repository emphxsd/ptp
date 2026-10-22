<?php

namespace Tests\Feature\Admin;

use App\Enums\DocumentStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ProcessedDocuments;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use ProcessedDocuments, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->admin = User::factory()->admin()->create(['name' => 'Главный Админ']);
    }

    private function page(?User $as = null): mixed
    {
        return Livewire::actingAs($as ?? $this->admin)->test('pages::admin.users');
    }

    public function test_only_admin_can_open_users_page(): void
    {
        $this->get(route('admin.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.index'))->assertOk()->assertSee('Пользователи');
    }

    public function test_page_shows_users_with_document_counts_and_overall_statistics(): void
    {
        $user = User::factory()->create(['name' => 'Иванов Иван', 'email' => 'ivanov@example.com']);
        $this->processedInvoice($user);
        $this->processedInvoice($user, attributes: ['status' => DocumentStatus::Failed]);

        $this->actingAs($this->admin)->get(route('admin.index'))
            ->assertSee('Иванов Иван')
            ->assertSee('ivanov@example.com')
            ->assertSee('Документов обработано')
            ->assertSeeInOrder(['Иванов Иван', 'ivanov@example.com', '2']);
    }

    public function test_admin_does_not_see_document_contents_of_users(): void
    {
        $user = User::factory()->create();
        $this->processedInvoice($user, ['supplier_inn' => '7707083893'], ['file_name' => 'секретный-договор.pdf']);

        $this->actingAs($this->admin)->get(route('admin.index'))
            ->assertDontSee('секретный-договор.pdf')
            ->assertDontSee('7707083893');
    }

    public function test_users_are_searched_by_name_or_email(): void
    {
        User::factory()->create(['name' => 'Петров Пётр', 'email' => 'petrov@example.com']);
        User::factory()->create(['name' => 'Сидоров Сидор', 'email' => 'sidorov@example.com']);

        $this->page()->set('search', 'PETROV')->assertSee('Петров Пётр')->assertDontSee('Сидоров Сидор');
        $this->page()->set('search', 'Сидор')->assertSee('sidorov@example.com')->assertDontSee('petrov@example.com');
    }

    public function test_user_is_blocked_with_sessions_ended_and_unblocked(): void
    {
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'user-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->page()->call('block', $user->id);

        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 'user-session']);
        $entry = AuditLog::where('action', 'user_blocked')->firstOrFail();
        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame($user->id, $entry->details['target_user_id']);

        $this->page()->call('unblock', $user->id);

        $this->assertTrue($user->fresh()->is_active);
        $this->assertTrue(AuditLog::where('action', 'user_unblocked')->exists());
    }

    public function test_admin_role_is_granted_and_revoked(): void
    {
        $user = User::factory()->create();

        $this->page()->call('grantAdmin', $user->id);
        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertTrue(AuditLog::where('action', 'admin_granted')->where('user_id', $this->admin->id)->exists());

        $this->page()->call('revokeAdmin', $user->id);
        $this->assertFalse($user->fresh()->isAdmin());
        $this->assertTrue(AuditLog::where('action', 'admin_revoked')->exists());
    }

    public function test_admin_cannot_block_or_demote_themselves(): void
    {
        $this->page()->call('block', $this->admin->id);
        $this->page()->call('revokeAdmin', $this->admin->id);

        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_admin_demoted_in_another_tab_loses_access_to_actions(): void
    {
        // два администратора снимают права друг с друга: второй запрос уже не от администратора
        $other = User::factory()->admin()->create();
        $stalePage = $this->page($other);

        $this->page()->call('revokeAdmin', $other->id);
        // запрос из старой вкладки второго администратора — уже от обычного пользователя
        $this->actingAs($other->fresh());
        $stalePage->call('revokeAdmin', $this->admin->id)->assertForbidden();

        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_last_active_admin_cannot_be_blocked_or_demoted(): void
    {
        // запрос от администратора, которого заблокировали, пока он работал: активный администратор — только $last
        $last = User::factory()->admin()->create();
        $this->admin->forceFill(['is_active' => false])->save();

        $this->page()->call('block', $last->id);
        $this->page()->call('revokeAdmin', $last->id);

        $this->assertTrue($last->fresh()->is_active);
        $this->assertTrue($last->fresh()->isAdmin());
    }

    public function test_blocked_admin_can_be_demoted(): void
    {
        $blocked = User::factory()->admin()->blocked()->create();

        $this->page()->call('revokeAdmin', $blocked->id);

        $this->assertFalse($blocked->fresh()->isAdmin());
    }

    public function test_blocked_user_cannot_be_made_admin(): void
    {
        $blocked = User::factory()->blocked()->create();

        $this->page()->call('grantAdmin', $blocked->id);

        $this->assertFalse($blocked->fresh()->isAdmin());
        $this->assertFalse(AuditLog::where('action', 'admin_granted')->exists());
    }

    public function test_repeated_action_without_change_is_not_audited(): void
    {
        $user = User::factory()->create();

        $this->page()->call('unblock', $user->id);
        $this->page()->call('revokeAdmin', $user->id);

        $this->assertSame(0, AuditLog::whereIn('action', ['user_unblocked', 'admin_revoked'])->count());
    }

    public function test_unknown_user_returns_not_found(): void
    {
        $this->page()->call('block', 999999)->assertNotFound();
    }

    public function test_non_admin_cannot_open_component(): void
    {
        $this->page(User::factory()->create())->assertForbidden();
    }
}
