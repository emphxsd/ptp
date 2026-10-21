<?php

namespace App\Policies;

use App\Models\Template;
use App\Models\User;

/**
 * Системные шаблоны видят все, меняет только администратор.
 * Личный шаблон видит и меняет только владелец — администратор тоже не имеет к нему доступа.
 */
class TemplatePolicy
{
    public function view(User $user, Template $template): bool
    {
        return $template->is_system || $template->user_id === $user->id;
    }

    public function update(User $user, Template $template): bool
    {
        return $template->is_system ? $user->isAdmin() : $template->user_id === $user->id;
    }

    public function delete(User $user, Template $template): bool
    {
        return $this->update($user, $template);
    }

    public function createSystem(User $user): bool
    {
        return $user->isAdmin();
    }
}
