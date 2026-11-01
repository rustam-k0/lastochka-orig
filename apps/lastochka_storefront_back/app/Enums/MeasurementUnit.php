<?php

declare(strict_types=1);

namespace App\Enums;

enum MeasurementUnit: string
{
    case Piece = 'piece';
    case Gram = 'gram';
    case Kilogram = 'kilogram';
    case Liter = 'liter';
    case Milliliter = 'milliliter';

    public function label(): string
    {
        return match ($this) {
            self::Piece => 'шт',
            self::Gram => 'гр',
            self::Kilogram => 'кг',
            self::Liter => 'л',
            self::Milliliter => 'мл',
        };
    }
}
