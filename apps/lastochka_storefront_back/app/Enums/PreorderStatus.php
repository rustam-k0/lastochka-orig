<?php

declare(strict_types=1);

namespace App\Enums;

enum PreorderStatus: string
{
    case New = 'new';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::Completed => 'Выполнена',
            self::Cancelled => 'Отменена',
        };
    }
}
