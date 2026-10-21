<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_logout_are_logged(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('logout'));

        $this->assertSame(
            ['login', 'logout'],
            AuditLog::where('user_id', $user->id)->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_failed_login_is_logged_without_password(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

        $entry = AuditLog::where('action', 'login_failed')->firstOrFail();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertStringNotContainsString('wrong-password', json_encode($entry->details));
    }

    public function test_registration_is_logged(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Петров Пётр',
            'email' => 'petrov@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'petrov@example.com')->firstOrFail();
        $this->assertTrue(AuditLog::where('user_id', $user->id)->where('action', 'registered')->exists());
    }

    public function test_entry_keeps_ip_and_survives_user_deletion(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $user->delete();

        $entry = AuditLog::where('action', 'login')->firstOrFail();
        $this->assertNull($entry->user_id);
        $this->assertSame('127.0.0.1', $entry->details['ip']);
    }
}
