<?php

namespace App\Support;

/** Действия журнала (audit_log.action) и их подписи для раздела администратора. */
final class AuditActions
{
    public const LABELS = [
        'login' => 'Вход',
        'logout' => 'Выход',
        'login_failed' => 'Неудачная попытка входа',
        'login_blocked' => 'Попытка входа заблокированного',
        'registered' => 'Регистрация',
        'admin_granted' => 'Выданы права администратора',
        'admin_revoked' => 'Сняты права администратора',
        'user_blocked' => 'Пользователь заблокирован',
        'user_unblocked' => 'Пользователь разблокирован',
        'system_template_changed' => 'Изменение системного шаблона',
        'document_uploaded' => 'Загрузка документа',
        'document_downloaded' => 'Скачивание исходного файла',
        'document_exported' => 'Выгрузка результата',
        'documents_exported' => 'Выгрузка выборки',
        'value_corrected' => 'Исправление значения',
        'value_confirmed' => 'Подтверждение значения',
        'value_reverted' => 'Отмена исправления',
    ];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }

    /**
     * Подробности записи одной строкой: «ключ: значение» через запятую (экранирует Blade при выводе).
     *
     * @param  array<string, mixed>|null  $details
     */
    public static function details(?array $details): string
    {
        return collect($details ?? [])
            ->map(fn (mixed $value, string $key) => $key.': '.self::value($value))
            ->join(', ');
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'да' : 'нет',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
