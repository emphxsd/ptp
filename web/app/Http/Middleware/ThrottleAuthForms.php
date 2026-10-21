<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ограничение частоты для форм Fortify, у которых в пакете нет лимита: регистрация и запрос сброса пароля
 * (массовое создание учётных записей, рассылка писем). Лимиты описаны в FortifyServiceProvider.
 */
class ThrottleAuthForms
{
    private const LIMITERS = [
        'register.store' => 'register',
        'password.email' => 'password-reset-link',
    ];

    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limiter = self::LIMITERS[$request->route()?->getName() ?? ''] ?? null;
        if ($limiter === null || ! $request->isMethod('POST')) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, $limiter);
    }
}
