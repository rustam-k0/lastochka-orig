<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\Cart\CartStoreGroupData;
use App\Models\Address;
use App\Models\Cart;
use App\Services\Cart\CartCalculator;
use App\Services\Discount\ItemDiscountResolver;
use App\Services\Store\AvailableStoreService;
use App\Support\WeightAggregator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin Cart
 */
final class CartResource extends JsonResource
{
    public static $wrap = null;

    private ?Address $address = null;

    public function withAddress(?Address $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $storeGroups = $this->buildStoreGroups();

        return [
            'storeGroups' => CartStoreGroupResource::collection($storeGroups),
            'weightTotal' => WeightAggregator::toKg(WeightAggregator::sumGrams($this->items->whereNull('parent_id'))),
            'isHeavy' => collect($storeGroups)->contains(fn (array $g) => $g['isHeavy'] ?? false),
            'availableShippingMethods' => app(\App\Services\Cart\AvailableShippingMethodsResolver::class)
                ->resolve($this->resource),
        ];
    }

    /**
     * Группирует items по магазинам и добавляет расчёты.
     */
    private function buildStoreGroups(): array
    {
        $calculator = app(CartCalculator::class);
        $availableStoreService = app(AvailableStoreService::class);
        $heavyResolver = app(\App\Support\HeavyOrderResolver::class);
        $assemblyEnabled = app(\App\Services\Settings\ModuleConfigService::class)->isAssemblyCostEnabled();
        $groups = [];

        $address = $this->address;
        // Только корневые позиции: дочерние (добавки из каталога) сворачиваются в supplements[].
        $itemsByStore = $this->items->whereNull('parent_id')->groupBy('store_id');

        foreach ($itemsByStore as $storeId => $items) {
            $store = $items->first()->store;

            $promocode = $this->promocodes
                ->where('store_id', $storeId)
                ->first()
                ?->promocode;

            $groupData = new CartStoreGroupData(
                store: $store,
                items: $items,
                promocode: $promocode,
            );

            // Получаем цены доставки (зависит от метода)
            $shippingMethod = request()->query('shippingMethod', 'courier');

            // Зонная стоимость сборки из slots-api (DeliveryInfoData::assemblyPrice).
            // Есть только у курьерской/Яндекс-доставки с адресом; при самовывозе
            // или без адреса зонного контекста нет → используется магазинная
            // $store->assembly_price (см. assemblyCost в $groups[] ниже).
            $zoneAssemblyPrice = null;

            // Лесенка стоимости доставки группы: ступени и ближайшая ступень
            // для подсказки клиенту. У самовывоза доставки нет — лесенки тоже.
            $deliveryLadder = null;

            if ($shippingMethod === 'pickup') {
                $deliveryCost = 0;
                $minOrderAmount = $store->min_pickup_order_amount ?? $store->min_order_amount ?? 0;
                $freeDeliveryFrom = null;
            } else {
                // Для Яндекс-доставки показываем реальную цену checkPrice (как при
                // создании заказа), а не базовую цену зоны — иначе корзина и заказ
                // расходятся. Обычная доставка — прежняя логика. ALADDIN-11465.
                // Дата = сегодня: Яндекс-доставка (express, день-в-день) активна на
                // текущую дату; на завтра purchase_back отдаёт delivery_type=own.
                $deliveryService = app(\App\Services\Delivery\DeliveryService::class);
                $fullInfo = null;
                if ($address) {
                    try {
                        $fullInfo = $deliveryService->getDeliveryInfo(
                            $store,
                            $address,
                            now()->format('Y-m-d'),
                            app(\App\Services\Delivery\CartProductsMapper::class)->map($items),
                        );
                    } catch (\Throwable $e) {
                        $fullInfo = null;
                    }
                }

                // Зонная цена сборки доступна для обоих типов курьерской доставки
                // (own/yandex) — берём из DeliveryInfoData до ветвления по типу.
                $zoneAssemblyPrice = $fullInfo?->assemblyPrice;

                if ($fullInfo && $fullInfo->deliveryType === 'yandex') {
                    $deliveryCost = $deliveryService->resolveDisplayedDeliveryCost(
                        $store,
                        $address,
                        $fullInfo,
                        $calculator->calculateSubtotalWithSupplements($groupData),
                    );
                    $minOrderAmount = $fullInfo->minOrderAmount;
                    $freeDeliveryFrom = $fullInfo->freeDeliveryFrom;
                    $deliveryLadder = $fullInfo->ladder();
                } else {
                    $deliveryInfo = $address
                        ? $availableStoreService->getDeliveryInfo($address, $store)
                        : null;

                    $deliveryCost = (float) $calculator->calculateDeliveryCost($groupData, $deliveryInfo);
                    $minOrderAmount = $deliveryInfo
                        ? $deliveryInfo['minOrderAmount']
                        : ($store->min_order_amount ?? 0);
                    $freeDeliveryFrom = $deliveryInfo
                        ? ($deliveryInfo['freeDeliveryFrom'] ?? null)
                        : $store->free_delivery_from;
                    $deliveryLadder = $calculator->deliveryLadder($groupData, $deliveryInfo);
                }
            }

            $bonusInfo = null;
            if ($store->is_bonus_enabled) {
                $user = Auth::user();
                $userBalance = $user?->available_bonus_balance ?? 0;
                $hasPromocode = (bool) $promocode;
                // eligible — позиции без скидки; источник скидки единый с
                // заказом ({@see ItemDiscountResolver}): в сетевом каталоге
                // это снапшот скидки оффера магазина покупки.
                $itemDiscount = app(ItemDiscountResolver::class);
                $eligibleAmount = $hasPromocode
                    ? 0
                    : (int) $groupData->items
                        ->filter(fn ($item) => $item->product && ! $itemDiscount->hasDiscount($item))
                        ->sum(fn ($item) => $item->price * $item->portions);

                $earnPercent = (int) ($store->bonus_earn_percent ?? 0);
                $spendPercent = (int) ($store->bonus_spend_percent ?? 0);
                $maxEarn = $eligibleAmount > 0 ? (int) floor($eligibleAmount * $earnPercent / 100) : 0;
                $maxSpend = $eligibleAmount > 0
                    ? min((int) floor($eligibleAmount * $spendPercent / 100), $userBalance)
                    : 0;

                $bonusInfo = [
                    'isEnabled' => true,
                    'eligibleAmount' => $eligibleAmount,
                    'earnPercent' => $earnPercent,
                    'spendPercent' => $spendPercent,
                    'maxEarn' => $maxEarn,
                    'maxSpend' => $maxSpend,
                    'userBalance' => $userBalance,
                ];
            }

            $groups[] = [
                'id' => (int) $storeId,
                'store' => $store,
                'items' => $items,
                'deliveryCost' => (float) $deliveryCost,
                'assemblyCost' => $assemblyEnabled ? (int) ($zoneAssemblyPrice ?? $store->assembly_price ?? 0) : 0,
                'minOrderAmount' => (float) $minOrderAmount,
                'freeDeliveryFrom' => $freeDeliveryFrom,
                'deliveryPriceRanges' => $deliveryLadder?->ranges() ?? [],
                'nextDeliveryStep' => $deliveryLadder?->nextStep($calculator->calculateSubtotalWithSupplements($groupData)),
                'discount' => (float) $calculator->calculateDiscount($groupData),
                'promocode' => $promocode,
                'bonus' => $bonusInfo,
                'isHeavy' => $heavyResolver->isHeavyForItems($store, $items),
                // Готовность считаем по ПОЛНОМУ множеству позиций магазина
                // (включая дочерние добавки, parent_id != null) — иначе
                // availableFrom в корзине разойдётся с валидацией слота при
                // создании заказа (там добавки учитываются всегда).
                'availableFrom' => app(\App\Services\ReadyTime\ReadyTimeResolver::class)
                    ->forCartItems($this->items->where('store_id', $storeId))?->toIso8601String(),
            ];
        }

        return $groups;
    }
}
