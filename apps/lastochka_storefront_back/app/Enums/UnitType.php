<?php

declare(strict_types=1);

namespace App\Enums;

enum UnitType: string
{
    case Piece = 'piece';
    case Kg = 'kg';

    public function label(): string
    {
        return match ($this) {
            self::Piece => 'Штука',
            self::Kg => 'Килограмм',
        };
    }
}
