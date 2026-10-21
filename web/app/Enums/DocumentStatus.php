<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Загружен',
            self::Processing => 'В обработке',
            self::Done => 'Готов',
            self::Failed => 'Ошибка',
        };
    }
}
