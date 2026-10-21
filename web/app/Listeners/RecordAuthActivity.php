<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;

/** Вход, выход, неудачные попытки и регистрация попадают в журнал действий (audit_log). */
class RecordAuthActivity
{
    public function handleLogin(Login $event): void
    {
        AuditLog::record('login', $this->user($event->user), $this->context());
    }

    public function handleLogout(Logout $event): void
    {
        AuditLog::record('logout', $this->user($event->user), $this->context());
    }

    public function handleFailed(Failed $event): void
    {
        // пароль и введённый e-mail не сохраняются; пользователь — если такой e-mail существует
        $user = $this->user($event->user)
            ?? User::where('email', (string) ($event->credentials['email'] ?? ''))->first();
        AuditLog::record('login_failed', $user, $this->context());
    }

    public function handleRegistered(Registered $event): void
    {
        AuditLog::record('registered', $this->user($event->user), $this->context());
    }

    private function user(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }

    /** @return array<string, string|null> */
    private function context(): array
    {
        return ['ip' => request()->ip()];
    }
}
