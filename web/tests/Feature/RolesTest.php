<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_admin_roles_exist_after_migrations(): void
    {
        $this->assertEqualsCanonicalizing(['user', 'admin'], Role::pluck('name')->map->value->all());
    }

    public function test_registered_user_gets_user_role_and_is_active(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Иванов Иван',
            'email' => 'ivanov@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'ivanov@example.com')->firstOrFail();
        $this->assertSame(RoleName::User, $user->role->name);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->isAdmin());
    }

    public function test_role_cannot_be_set_through_registration_form(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Хакер',
            'email' => 'hacker@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => Role::idFor(RoleName::Admin),
        ]);

        $this->assertFalse(User::where('email', 'hacker@example.com')->firstOrFail()->isAdmin());
    }

    public function test_admin_area_requires_admin_role(): void
    {
        $this->get(route('admin.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get(route('admin.index'))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertOk();
    }

    public function test_blocked_user_cannot_log_in(): void
    {
        $user = User::factory()->blocked()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_blocked_user_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
