<?php

namespace App\Services\Quantum;

use App\Models\Type;

/**
 * Семейства единиц измерения и конверсия в наименьшую единицу семейства.
 *
 * Семейства: масса (наим. ед. — грамм), объём (мл), штуки (шт).
 * Используется для квант-пересчёта: сырая цена нормализуется к цене за
 * наименьшую единицу, шаг кванта хранится в наименьшей единице семейства.
 */
final class UnitFamily
{
    public const MASS = 'mass';
    public const VOLUME = 'volume';
    public const PIECE = 'piece';

    /** info_weight_type_id → семейство, либо null если единица неизвестна. */
    public static function family(?int $unitTypeId): ?string
    {
        return match ($unitTypeId) {
            Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_KILOGRAM => self::MASS,
            Type::INFO_WEIGHT_LITER, Type::INFO_WEIGHT_M_LITER => self::VOLUME,
            Type::INFO_WEIGHT_PIECE => self::PIECE,
            default => null,
        };
    }

    /** Наименьшая единица семейства (info_weight_type_id). */
    public static function smallestUnit(string $family): int
    {
        return match ($family) {
            self::MASS => Type::INFO_WEIGHT_GRAM,
            self::VOLUME => Type::INFO_WEIGHT_M_LITER,
            default => Type::INFO_WEIGHT_PIECE,
        };
    }

    /** Размер единицы в наименьших единицах семейства (кг=1000 г, литр=1000 мл, остальные=1). */
    public static function sizeInSmallest(?int $unitTypeId): int
    {
        return match ($unitTypeId) {
            Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_LITER => 1000,
            default => 1,
        };
    }

    /**
     * Единицы, допустимые как единица кванта (наименьшие по каждому семейству).
     * Объём (мл) пока не выдаём — продажа по объёму не поддержана в purchase_back
     * (создание заказа). Семейство VOLUME в коде остаётся generic на будущее.
     */
    public static function allowedQuantumUnits(): array
    {
        return [Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_PIECE];
    }

    /** Совпадают ли семейства двух единиц. */
    public static function sameFamily(?int $a, ?int $b): bool
    {
        $fa = self::family($a);

        return $fa !== null && $fa === self::family($b);
    }
}
