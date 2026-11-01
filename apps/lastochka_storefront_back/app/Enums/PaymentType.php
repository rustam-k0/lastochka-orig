<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentType: string
{
    case Cash = 'cash';
    case Card = 'card';

    /**
     * Локализованное название.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Наличные',
            self::Card => 'Картой',
        };
    }
}
