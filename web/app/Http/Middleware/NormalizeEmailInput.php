<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * E-mail в формах входа, регистрации и сброса пароля приводится к нижнему регистру без пробелов:
 * «Ivan@Example.com» и «ivan@example.com» — один пользователь (PostgreSQL сравнивает строки с учётом регистра).
 */
class NormalizeEmailInput
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = $request->input('email');
        if (is_string($email)) {
            $request->merge(['email' => mb_strtolower(trim($email))]);
        }

        return $next($request);
    }
}
