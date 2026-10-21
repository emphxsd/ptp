<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Первый администратор создаётся из консоли: пароль вводится скрыто и нигде не хранится в открытом виде.
 * Если пользователь с таким e-mail уже есть, ему выдаётся роль администратора (пароль не меняется).
 */
class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {--email= : E-mail администратора} {--name= : ФИО}';

    protected $description = 'Создать администратора или выдать роль администратора существующему пользователю';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('E-mail'))));
        if (Validator::make(['email' => $email], ['email' => 'required|email|max:255'])->fails()) {
            $this->error('Некорректный e-mail.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user === null) {
            $user = $this->createUser($email);
            if ($user === null) {
                return self::FAILURE;
            }
        }

        $user->forceFill(['role_id' => Role::idFor(RoleName::Admin), 'is_active' => true])->save();
        AuditLog::record('admin_granted', $user, ['source' => 'console']);
        $this->info("Администратор: {$user->email}");

        return self::SUCCESS;
    }

    private function createUser(string $email): ?User
    {
        $name = (string) ($this->option('name') ?: $this->ask('ФИО'));
        $password = (string) $this->secret('Пароль');
        $confirmation = (string) $this->secret('Повторите пароль');

        // та же политика паролей, что при регистрации на сайте (Password::defaults в AppServiceProvider)
        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', Password::defaults(), 'confirmed']],
        );
        if ($validator->fails()) {
            $this->error(implode(' ', $validator->errors()->all()));

            return null;
        }
        if (trim($name) === '') {
            $this->error('Укажите ФИО.');

            return null;
        }

        return User::create(['name' => trim($name), 'email' => $email, 'password' => $password]);
    }
}
