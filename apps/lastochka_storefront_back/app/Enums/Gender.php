<?php

declare(strict_types=1);

namespace App\Enums;

enum Gender: string
{
    case Male = 'male';
    case Female = 'female';

    /**
     * Локализованное название.
     */
    public function label(): string
    {
        return match ($this) {
            self::Male => 'Мужской',
            self::Female => 'Женский',
        };
    }
}
