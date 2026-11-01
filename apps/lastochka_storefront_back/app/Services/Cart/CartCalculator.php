<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\DTOs\Cart\CartStoreGroupData;
use App\Enums\PromocodeType;
use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\Promocode;
use App\Services\Delivery\ZoneDeliveryLadder;
use App\Services\Discount\ItemDiscountResolver;
use App\Services\Pricing\DeliveryPriceLadder;
use Illuminate\Support\Collection;

final class CartCalculator
{
    /**
     * Рассчитать скидку по store-scoped промокоду.
     * Промокод применяется только к товарам БЕЗ скидки (без price_old).
     * Добавки НЕ участвуют в промокоде.
     */
    public function calculateDiscount(CartStoreGroupData $group): float
    {
        if (! $group->promocode) {
            return 0;
        }

        return $this->calculateDiscountForItems($group->items, $group->promocode);
    }

    /**
     * Рассчитать скидку для произвольного набора items по заданному промокоду.
     * Используется как для store-scoped групп, так и для platform-wide
     * промокода, когда items — вся корзина пользователя.
     */
    public function calculateDiscountForItems(Collection $items, Promocode $promocode): float
    {
        $eligibleSubtotal = round((float) $items
            ->filter(fn ($item) => $item->product
                && ! $this->hasDiscount($item)
                && $promocode->matchesProduct($item->product))
            ->sum(fn ($item) => $item->price * $item->portions), 2);

        if ($eligibleSubtotal <= 0) {
            return 0;
        }

        return match ($promocode->type) {
            PromocodeType::Percent => round($eligibleSubtotal * $promocode->value / 100, 2),
            PromocodeType::Fixed => round(min((float) $promocode->value, $eligibleSubtotal), 2),
        };
    }

    /**
     * Рассчитать стоимость доставки по лесенке {@see deliveryLadder()} (MID-280).
     * Сумма С добавками (до промокода и бонусов) выбирает ступень.
     *
     * @param  array{deliveryPrice: int, minOrderAmount: int, freeDeliveryFrom: int|null, deliveryPriceRanges?: array}|null  $deliveryInfo
     */
    public function calculateDeliveryCost(CartStoreGroupData $group, ?array $deliveryInfo = null): float
    {
        return (float) $this->deliveryLadder($group, $deliveryInfo)
            ->priceFor($this->calculateSubtotalWithSupplements($group));
    }

    /**
     * Лесенка стоимости доставки группы (MID-280).
     *
     * Если передан $deliveryInfo (из AvailableStoreService по адресу клиента),
     * цена, порог и ступени берутся из него — это зона-специфичные данные от
     * slots-api. Иначе используется лесенка магазина из Store.
     *
     * Ступеней у зоны нет и своего порога нет ($deliveryInfo['freeDeliveryFrom']
     * === null) — запасной порог берётся из stores.free_delivery_from, как и в
     * CreateOrderAction::deliveryLadder() (фикс-раунд 1: без этого корзина и
     * заказ у магазина с несколькими зонами расходились в цене).
     *
     * @param  array{deliveryPrice: int, minOrderAmount: int, freeDeliveryFrom: int|null, deliveryPriceRanges?: array}|null  $deliveryInfo
     */
    public function deliveryLadder(CartStoreGroupData $group, ?array $deliveryInfo = null): DeliveryPriceLadder
    {
        if ($deliveryInfo !== null) {
            return ZoneDeliveryLadder::make(
                (int) ($deliveryInfo['deliveryPrice'] ?? 0),
                $deliveryInfo['deliveryPriceRanges'] ?? null,
                $deliveryInfo['freeDeliveryFrom'] ?? $group->store->free_delivery_from,
            );
        }

        return $group->store->deliveryLadder();
    }

    /**
     * Рассчитать сумму добавок для CartItem.
     */
    public function calculateItemSupplementsTotal($cartItem): int
    {
        if (! $cartItem->relationLoaded('supplements')) {
            return 0;
        }

        return $cartItem->supplements->sum(function ($cartItemSupplement) {
            return $cartItemSupplement->relationLoaded('values')
                ? $cartItemSupplement->values->sum('price')
                : 0;
        });
    }

    /**
     * Рассчитать subtotal с учётом добавок.
     *
     * Учитываются:
     *  - виртуальные добавки (CartItemSupplement.values) × portions
     *  - товарные добавки из каталога (дочерние CartItem, parent_id IS NOT NULL):
     *    сумма price × portions каждого дочернего
     */
    public function calculateSubtotalWithSupplements(CartStoreGroupData $group): float
    {
        return round((float) $group->items->sum(function ($item) {
            $supplementsPrice = $this->calculateItemSupplementsTotal($item);

            $catalogSupplementsPrice = $item->relationLoaded('childItems')
                ? (float) $item->childItems->sum(fn ($child) => $child->price * $child->portions)
                : 0.0;

            return ($item->price + $supplementsPrice) * $item->portions + $catalogSupplementsPrice;
        }), 2);
    }

    /**
     * Проверяет, есть ли у позиции скидка (ручная, скидка оффера магазина
     * в сетевом каталоге или акционная) — единый источник для корзины и
     * заказа, {@see ItemDiscountResolver}.
     *
     * Резолвер тянем из контейнера, а не через конструктор: калькулятор
     * создают и напрямую (`new CartCalculator`).
     */
    private function hasDiscount(CartItem|OrderItem $item): bool
    {
        return app(ItemDiscountResolver::class)->hasDiscount($item);
    }
}
