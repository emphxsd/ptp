<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_only_admin_can_open_audit_log(): void
    {
        $this->get(route('admin.audit'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.audit'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.audit'))->assertOk();
    }

    public function test_entries_are_shown_with_readable_actions_and_users(): void
    {
        $user = User::factory()->create(['email' => 'ivanov@example.com']);
        AuditLog::record('login_failed', $user, ['ip' => '10.0.0.7']);
        AuditLog::record('document_uploaded', $user, ['document_id' => 42, 'use_llm' => false]);
        AuditLog::record('login_failed', null, ['ip' => '10.0.0.8']);

        $this->actingAs($this->admin)->get(route('admin.audit'))
            ->assertSee('Неудачная попытка входа')
            ->assertSee('Загрузка документа')
            ->assertSee('ivanov@example.com')
            ->assertSee('10.0.0.7')
            ->assertSee('document_id: 42');
    }

    public function test_entries_are_filtered_by_action_user_and_period(): void
    {
        $ivanov = User::factory()->create(['email' => 'ivanov@example.com']);
        $petrov = User::factory()->create(['email' => 'petrov@example.com']);
        AuditLog::record('login', $ivanov, ['ip' => '10.0.0.1']);
        AuditLog::record('logout', $petrov, ['ip' => '10.0.0.2']);
        $old = AuditLog::record('login', $petrov, ['ip' => '10.0.0.3']);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $page = Livewire::actingAs($this->admin)->test('pages::admin.audit');
        $page->set('action', 'logout')->assertSee('10.0.0.2')->assertDontSee('10.0.0.1');

        $page = Livewire::actingAs($this->admin)->test('pages::admin.audit');
        $page->set('user', 'IVANOV')->assertSee('10.0.0.1')->assertDontSee('10.0.0.2');

        $page = Livewire::actingAs($this->admin)->test('pages::admin.audit');
        $page->set('from', now()->subDays(2)->toDateString())->assertSee('10.0.0.2')->assertDontSee('10.0.0.3');
    }

    public function test_details_are_escaped(): void
    {
        AuditLog::record('login_failed', null, ['ip' => '<script>alert(1)</script>']);

        $this->actingAs($this->admin)->get(route('admin.audit'))
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_array_values_in_query_string_do_not_break_page(): void
    {
        $this->actingAs($this->admin)->get('/admin/audit?action[]=login&user[]=x&from[]=2025-01-01')->assertOk();
        $this->actingAs($this->admin)->get('/admin?search[]=x')->assertOk();
    }

    public function test_unknown_action_filter_is_ignored(): void
    {
        AuditLog::record('login', $this->admin, ['ip' => '10.0.0.9']);

        Livewire::actingAs($this->admin)->test('pages::admin.audit')->set('action', 'drop_table')->assertSee('10.0.0.9');
    }
}
