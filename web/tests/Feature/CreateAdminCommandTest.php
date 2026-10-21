<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_admin_with_hidden_password_prompt(): void
    {
        $this->artisan('app:create-admin', ['--email' => 'admin@example.com', '--name' => 'Администратор'])
            ->expectsQuestion('Пароль', 'secret-password-1')
            ->expectsQuestion('Повторите пароль', 'secret-password-1')
            ->assertSuccessful();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Hash::check('secret-password-1', $admin->password));
    }

    public function test_existing_user_is_promoted_without_changing_password(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->artisan('app:create-admin', ['--email' => 'user@example.com'])->assertSuccessful();

        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_short_or_mismatched_password_is_rejected(): void
    {
        $this->artisan('app:create-admin', ['--email' => 'admin@example.com', '--name' => 'Админ'])
            ->expectsQuestion('Пароль', 'short')
            ->expectsQuestion('Повторите пароль', 'other')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }
}
