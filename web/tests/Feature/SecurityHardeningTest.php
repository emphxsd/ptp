<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/** Исправления по итогам ревью шага 1: лимиты запросов, e-mail без учёта регистра, защита полей, журнал. */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_rate_limited_by_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('register.store'), [])->assertSessionHasErrors();
        }

        $this->post(route('register.store'), [])->assertStatus(429);
    }

    public function test_password_reset_requests_are_rate_limited_by_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.email'), ['email' => "nobody{$i}@example.com"]);
        }

        $this->post(route('password.email'), ['email' => 'nobody@example.com'])->assertStatus(429);
    }

    public function test_failed_logins_with_different_emails_are_limited_by_ip(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post(route('login.store'), ['email' => "user{$i}@example.com", 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['email' => 'last@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_password_reset_does_not_reveal_whether_email_exists(): void
    {
        $this->post(route('password.email'), ['email' => 'nobody@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('passwords.sent'));
    }

    public function test_email_is_case_insensitive(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Иван',
            'email' => '  Ivan@Example.COM ',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();
        $this->post(route('logout'));

        $this->assertDatabaseHas('users', ['email' => 'ivan@example.com']);

        $this->post(route('register.store'), [
            'name' => 'Двойник',
            'email' => 'IVAN@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('email');

        $this->post(route('login.store'), ['email' => 'IVAN@EXAMPLE.com', 'password' => 'password']);
        $this->assertAuthenticated();
    }

    public function test_block_flag_and_role_are_not_mass_assignable(): void
    {
        $user = User::create(['name' => 'А', 'email' => 'a@example.com', 'password' => 'password', 'is_active' => false]);

        $this->assertTrue($user->is_active);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_new_document_has_default_status_in_memory(): void
    {
        $document = new Document;

        $this->assertSame(DocumentStatus::Uploaded, $document->status);
    }

    public function test_email_change_requires_current_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('email', 'new@example.com')
            ->call('updateProfileInformation')
            ->assertHasErrors('current_password');
        $this->assertNotSame('new@example.com', $user->fresh()->email);

        Livewire::test('pages::settings.profile')
            ->set('email', 'new@example.com')
            ->set('current_password', 'password')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();
        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    public function test_password_change_ends_other_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([[$user->id, 'other-device'], [$other->id, 'someone-else']] as [$userId, $id]) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time(),
            ]);
        }
        $this->actingAs($user);

        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'someone-else']);
    }

    public function test_last_admin_cannot_delete_own_account(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors('password');

        $this->assertNotNull($admin->fresh());
    }

    public function test_blocked_login_attempt_is_logged(): void
    {
        $user = User::factory()->blocked()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertTrue(AuditLog::where('user_id', $user->id)->where('action', 'login_blocked')->exists());
    }

    public function test_old_audit_entries_are_pruned(): void
    {
        $old = AuditLog::record('login');
        $old->forceFill(['created_at' => now()->subDays(config('audit.retention_days') + 1)])->save();
        $recent = AuditLog::record('login');

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

        $this->assertNull(AuditLog::find($old->id));
        $this->assertNotNull(AuditLog::find($recent->id));
    }

    public function test_foreign_keys_are_indexed(): void
    {
        $indexed = fn (string $table, string $column) => collect(Schema::getIndexes($table))
            ->contains(fn (array $index) => ($index['columns'][0] ?? null) === $column);

        foreach ([
            ['templates', 'user_id'], ['documents', 'user_id'], ['documents', 'template_id'],
            ['extraction_results', 'document_id'], ['extracted_values', 'result_id'], ['extracted_values', 'field_id'],
            ['exports', 'result_id'], ['audit_log', 'user_id'],
        ] as [$table, $column]) {
            $this->assertTrue($indexed($table, $column), "нет индекса {$table}.{$column}");
        }
    }
}
