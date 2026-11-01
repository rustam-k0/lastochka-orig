<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\Type;

/**
 * Единственное место перевода количеств в единицу остатка и обратно
 * (копия delivery_back App\Support\StockUnits — правило единиц одно на все модули).
 *
 * Три единицы у одного товара (прод, «Филе грудки» арт. 77203):
 *  - остаток remains.quantity — базовая единица 1С (base_unit_type_id): кг/л/шт,
 *    дробный (12.806 кг);
 *  - позиция заказа order_items.quantity — порции: info_weight единиц
 *    info_weight_type_id (3 порции по 300 г);
 *  - фактический вес actual_weight — наименьшая единица: граммы/мл (528 г).
 *
 * С фасовкой (LASTOCHKI-JAMI-11200) info_weight_type_id — единица ПОРЦИИ, а не
 * остатка: списывать из остатка порции или граммы без перевода нельзя (порции
 * 250 г списывались как килограммы, товар пропадал с витрины при живом остатке).
 */
final class StockUnits
{
    /** Остаток хранится с точностью DECIMAL(14,3). */
    private const STOCK_PRECISION = 3;

    /** Единица остатка: базовая единица 1С, иначе крупная единица семейства порции. */
    public static function stockUnit(Product $product): int
    {
        $base = (int) $product->base_unit_type_id;

        if ($base > 0) {
            return $base;
        }

        return match ((int) $product->info_weight_type_id) {
            Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_KILOGRAM => Type::INFO_WEIGHT_KILOGRAM,
            Type::INFO_WEIGHT_M_LITER, Type::INFO_WEIGHT_LITER => Type::INFO_WEIGHT_LITER,
            default => Type::INFO_WEIGHT_PIECE,
        };
    }

    /** Сколько наименьших единиц (г/мл/шт) в одной единице типа. */
    public static function sizeInSmallest(?int $unitTypeId): int
    {
        return match ((int) $unitTypeId) {
            Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_LITER => 1000,
            default => 1,
        };
    }

    /** Размер одной порции в наименьших единицах: 300 г → 300, 1 кг → 1000, шт → 1. */
    public static function portionInSmallest(Product $product): int
    {
        $unitSize = self::sizeInSmallest($product->info_weight_type_id);
        $infoWeight = (int) $product->info_weight;

        return $infoWeight > 0 ? $infoWeight * $unitSize : $unitSize;
    }

    /** Порции позиции → единица остатка. */
    public static function portionsToStock(Product $product, int|float $portions): float
    {
        return self::smallestToStock($product, $portions * self::portionInSmallest($product));
    }

    /** Граммы/мл/шт → единица остатка. */
    public static function smallestToStock(Product $product, int|float $amount): float
    {
        return round($amount / self::sizeInSmallest(self::stockUnit($product)), self::STOCK_PRECISION);
    }

    /**
     * Собранное по позиции → единица остатка. Весовой товар списывается по
     * фактическому весу, остальные — по порциям. Залипший actual_weight у
     * штучного (MID-246) игнорируется.
     */
    public static function collectedToStock(Product $product, ?int $actualWeight, ?int $portions): float
    {
        if ($actualWeight !== null && self::isWeightBased($product)) {
            return self::smallestToStock($product, $actualWeight);
        }

        return self::portionsToStock($product, (int) $portions);
    }

    /**
     * Остаток → единица порции (info_weight_type_id): в ней приложение сборщика
     * подписывает все количества товара. 12.806 кг при порции в граммах → 12806.
     */
    public static function stockToPortionUnit(Product $product, float $stock): float
    {
        $smallest = $stock * self::sizeInSmallest(self::stockUnit($product));

        return round($smallest / self::sizeInSmallest($product->info_weight_type_id), self::STOCK_PRECISION);
    }

    /** Весовой/объёмный товар: порция в г/кг/л/мл (как delivery_back Product::isWeightBased). */
    private static function isWeightBased(Product $product): bool
    {
        return in_array((int) $product->info_weight_type_id, [
            Type::INFO_WEIGHT_GRAM,
            Type::INFO_WEIGHT_KILOGRAM,
            Type::INFO_WEIGHT_LITER,
            Type::INFO_WEIGHT_M_LITER,
        ], true);
    }
}
