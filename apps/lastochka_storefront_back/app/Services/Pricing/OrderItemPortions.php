<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\MeasurementUnit;
use App\Models\OrderItem;
use App\Models\Product;

/**
 * Порции позиции заказа для выбора ступени цены (MID-308).
 *
 * Зеркало расчёта порций в OrderEventTransformer::buildItems(), но с
 * округлением вместо отбрасывания дробной части: после сборки весовая позиция
 * хранит фактический вес, и недовес в пределах шага ступень не срывает
 * (порции = округлённый фактический вес / шаг).
 */
final class OrderItemPortions
{
    public static function of(OrderItem $item): float
    {
        return self::fromStored(
            (int) $item->quantity,
            $item->measurement_unit?->value,
            $item->product
        );
    }

    /**
     * @param  int  $quantity  order_items.quantity (граммы/мл у весовых, штуки у штучных)
     * @param  string|null  $storageUnit  order_items.measurement_unit
     */
    public static function fromStored(int $quantity, ?string $storageUnit, ?Product $product): float
    {
        $step = (int) ($product?->quantity_step ?? 1);
        $productUnit = $product?->measurement_unit?->value ?? 'piece';
        $storageUnit ??= $productUnit;

        if ($step > 1) {
            return max(1.0, round($quantity / $step));
        }

        if (in_array($storageUnit, ['gram', 'milliliter'], true)
            && in_array($productUnit, ['kilogram', 'liter'], true)) {
            return max(1.0, round($quantity / 1000));
        }

        return (float) $quantity;
    }

    /**
     * Количество корзины в единице хранения позиции заказа: то же правило,
     * что CreateOrderAction::storedQuantity() (кг без кванта: ×1000).
     */
    public static function storedFromCart(int $cartQuantity, ?Product $product): int
    {
        $step = (int) ($product?->quantity_step ?? 1);

        if ($step <= 1 && $product?->measurement_unit === MeasurementUnit::Kilogram) {
            return $cartQuantity * 1000;
        }

        return $cartQuantity;
    }
}
