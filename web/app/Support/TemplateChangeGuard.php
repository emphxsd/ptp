<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Template;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/** Общее для страниц шаблонов: ограничение частоты изменений и журнал изменений системных шаблонов. */
final class TemplateChangeGuard
{
    public const WRITES_PER_MINUTE = 60;

    /** false — пользователь меняет шаблоны слишком часто (копирование в цикле и т. п.); сообщение уже показано. */
    public static function allowWrite(): bool
    {
        $key = 'template-writes:'.Auth::id();
        if (RateLimiter::tooManyAttempts($key, self::WRITES_PER_MINUTE)) {
            Flux::toast(variant: 'danger', text: 'Слишком много изменений подряд. Подождите минуту.');

            return false;
        }
        RateLimiter::hit($key);

        return true;
    }

    /** Системный шаблон влияет на всех пользователей — его изменения пишутся в журнал действий. */
    public static function auditSystem(Template $template, string $change): void
    {
        if ($template->is_system) {
            AuditLog::record('system_template_changed', Auth::user(), [
                'template_id' => $template->id,
                'change' => $change,
            ]);
        }
    }
}
