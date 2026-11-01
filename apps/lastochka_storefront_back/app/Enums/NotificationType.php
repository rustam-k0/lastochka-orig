<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationType: string
{
    case Order = 'order';
    case Promo = 'promo';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Заказы',
            self::Promo => 'Акции',
        };
    }
}
