<?php

declare(strict_types=1);

namespace App\Enums;

enum PromocodeType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    /**
     * Локализованное название.
     */
    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Процент',
            self::Fixed => 'Фиксированная сумма',
        };
    }
}
