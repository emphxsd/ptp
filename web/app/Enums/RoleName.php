<?php

namespace App\Enums;

enum RoleName: string
{
    case User = 'user';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Пользователь',
            self::Admin => 'Администратор',
        };
    }
}
