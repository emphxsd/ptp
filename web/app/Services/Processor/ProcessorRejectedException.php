<?php

namespace App\Services\Processor;

use RuntimeException;

/**
 * Сервис отклонил документ или шаблон (ответ 4xx с кодом ошибки: PASSWORD_PROTECTED, UNSUPPORTED_FORMAT и т. п.).
 * Повтор не поможет. Сообщение сервиса не содержит данных документа — его можно показать пользователю.
 */
class ProcessorRejectedException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
