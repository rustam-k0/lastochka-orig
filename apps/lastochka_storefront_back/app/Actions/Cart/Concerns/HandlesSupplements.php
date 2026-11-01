<?php

declare(strict_types=1);

namespace App\Actions\Cart\Concerns;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSupplement;
use App\Models\SupplementValue;
use App\Services\Cart\CartStoreOffers;

trait HandlesSupplements
{
    /**
     * Привести supplements к виду [{supplementId, values:[{valueId, quantity}]}].
     *
     * @param  array<mixed>|null  $supplements
     * @return array<array{supplementId:int, values:array<array{valueId:int, quantity:int}>}>
     */
    private function normalizeSupplements(?array $supplements): array
    {
        if (! $supplements) {
            return [];
        }

        return collect($supplements)->map(function ($item) {
            if (! empty($item['valueQuantities']) && is_array($item['valueQuantities'])) {
                $values = array_map(fn ($v) => [
                    'valueId' => (int) $v['valueId'],
                    'quantity' => max(1, (int) $v['quantity']),
                ], $item['valueQuantities']);
            } else {
                $ids = $item['valueIds'] ?? $item['values'] ?? [];
                $values = array_map(fn ($id) => ['valueId' => (int) $id, 'quantity' => 1], $ids);
            }

            return ['supplementId' => (int) $item['supplementId'], 'values' => $values];
        })->all();
    }

    /**
     * Разделить значения на виртуальные и товарные (по product_id).
     *
     * @param  array<array{supplementId:int, values:array<array{valueId:int, quantity:int}>}>  $normalized
     * @return array{virtual: array<array{supplementId:int, valueIds:int[]}>, catalog: array<array{valueId:int, quantity:int, value:SupplementValue}>}
     */
    private function splitSupplements(array $normalized): array
    {
        $allValueIds = collect($normalized)->flatMap(fn ($s) => array_column($s['values'], 'valueId'))->unique();
        $loaded = SupplementValue::with('product')->findMany($allValueIds)->keyBy('id');

        $virtual = [];
        $catalog = [];
        foreach ($normalized as $s) {
            $virtualIds = [];
            foreach ($s['values'] as $sel) {
                $sv = $loaded->get($sel['valueId']);
                if ($sv && $sv->isFromCatalog()) {
                    $catalog[] = ['valueId' => $sv->id, 'quantity' => $sel['quantity'], 'value' => $sv];
                } else {
                    $virtualIds[] = $sel['valueId'];
                }
            }
            if ($virtualIds !== []) {
                $virtual[] = ['supplementId' => $s['supplementId'], 'valueIds' => $virtualIds];
            }
        }

        return ['virtual' => $virtual, 'catalog' => $catalog];
    }

    /**
     * Создать дочерние позиции для товарных добавок.
     * Количество дочерней позиции = выбранное_на_единицу × portions основного товара.
     *
     * Сетевой каталог: товарная добавка покупается в том же магазине, что и
     * основная позиция, поэтому store_id наследуется от родителя, а цена
     * снапшотится из оффера этого магазина (у глобальной карточки добавки
     * своя цена нулевая). Нет оффера — 422, как и для основного товара:
     * иначе дочерняя позиция уехала бы в заказ с нулевой ценой.
     *
     * Вместе с ценой снапшотится и цена ДО скидки (price_old): по ней
     * промокод и бонусы понимают, что добавку магазин продаёт со скидкой
     * ({@see \App\Services\Discount\ItemDiscountResolver}). Без снапшота на
     * создании оформление заказа сразу после добавления (без выборки корзины,
     * которая переписала бы price_old) считало бы скидочную добавку eligible.
     *
     * @param  array<array{valueId:int, quantity:int, value:SupplementValue}>  $catalog
     */
    private function createCatalogChildren(Cart $cart, CartItem $parent, array $catalog, int $portions): void
    {
        $storeOffers = app(CartStoreOffers::class);
        $network = $storeOffers->isNetworkMode();

        foreach ($catalog as $entry) {
            $value = $entry['value'];
            $storeId = $network
                ? (int) $parent->store_id
                : ($value->product?->store_id ?? $parent->store_id);
            $priceOld = null;

            if ($network && $value->product !== null) {
                $offer = $storeOffers->offerFor((int) $value->product->id, $storeId);

                if ($offer === null) {
                    throw new \Illuminate\Validation\ValidationException(
                        validator: validator([], []),
                        response: response()->json([
                            'message' => "Добавка «{$value->title}» недоступна в выбранном магазине",
                            'errors' => ['supplements' => ["Добавка «{$value->title}» недоступна в выбранном магазине"]],
                        ], 422)
                    );
                }

                $storeOffers->attachOffer($value->product, $offer);

                // Только когда цена дочерней позиции реально пришла из оффера:
                // у значения со своей ценой ({@see SupplementValue::effectivePrice()})
                // цена магазина не применяется, значит и скидки магазина у неё нет.
                if ($value->price === null) {
                    $priceOld = $value->product->getOriginalPrice();
                }
            }

            $cart->items()->create([
                'parent_id' => $parent->id,
                'source_supplement_value_id' => $value->id,
                'product_id' => $value->product_id,
                'store_id' => $storeId,
                'quantity' => $entry['quantity'] * max(1, $portions),
                'price' => $value->effectivePrice(),
                'price_old' => $priceOld,
            ]);
        }
    }

    /**
     * Создать записи CartItemSupplement с привязкой values.
     *
     * @param  array<array{supplementId: int, values: int[]}>  $supplements
     */
    private function createCartItemSupplements(CartItem $cartItem, array $supplements): void
    {
        foreach ($supplements as $supplementData) {
            $cartItemSupplement = CartItemSupplement::create([
                'cart_item_id' => $cartItem->id,
                'supplement_id' => $supplementData['supplementId'],
            ]);

            $valueIds = $supplementData['valueIds'] ?? $supplementData['values'] ?? [];
            $cartItemSupplement->values()->attach($valueIds);
        }
    }

    /**
     * Ключ набора добавок существующей позиции (виртуальные + товарные через
     * дочерние позиции). Формат тот же, что у ключа запроса на добавление:
     * позиции с одинаковым ключом — одна и та же позиция, их можно слить.
     *
     * Требует загруженных связей `product`, `supplements.values`, `childItems`.
     */
    private function buildSupplementsKeyFromCartItem(CartItem $cartItem): string
    {
        $vKey = $cartItem->supplements
            ->sortBy('supplement_id')
            ->map(fn ($cis) => $cis->supplement_id.':'.$cis->values->pluck('id')->sort()->implode(','))
            ->implode('|');

        $portions = max(1, $cartItem->getPortionsAttribute());
        $cKey = $cartItem->childItems
            ->sortBy('source_supplement_value_id')
            ->map(function ($child) use ($portions) {
                $perUnit = (int) round($child->quantity / $portions);

                return '#'.$child->product_id.':'.$perUnit;
            })
            ->implode('|');

        return trim($vKey.'||'.$cKey, '|');
    }
}
