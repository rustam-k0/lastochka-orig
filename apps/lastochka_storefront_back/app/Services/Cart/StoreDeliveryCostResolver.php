<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\DTOs\Cart\CartStoreGroupData;
use App\Models\Address;
use App\Models\Store;
use App\Services\Delivery\CartProductsMapper;
use App\Services\Delivery\DeliveryService;
use App\Services\Store\AvailableStoreService;
use Illuminate\Support\Collection;

/**
 * Стоимость доставки магазина для позиций маркетплейсной корзины: один расчёт
 * для корзины (ClientCartResource) и сводки доставки (CartController::deliverySummary).
 *
 * Яндекс-доставка — реальная цена checkPrice (как при создании заказа), иначе
 * лесенка зоны по адресу клиента или лесенка магазина без адреса (MID-280).
 */
final class StoreDeliveryCostResolver
{
    public function __construct(
        private readonly CartCalculator $calculator,
        private readonly DeliveryService $deliveryService,
        private readonly AvailableStoreService $availableStoreService,
        private readonly CartProductsMapper $cartProductsMapper,
    ) {}

    public function resolve(Store $store, Collection $storeItems, ?Address $address): float
    {
        $groupData = new CartStoreGroupData(
            store: $store,
            items: $storeItems,
            promocode: null,
        );

        $fullInfo = null;
        if ($address) {
            try {
                // Дата = сегодня: Яндекс-доставка активна на текущую дату.
                $fullInfo = $this->deliveryService->getDeliveryInfo(
                    $store,
                    $address,
                    now()->format('Y-m-d'),
                    $this->cartProductsMapper->map($storeItems),
                );
            } catch (\Throwable $e) {
                $fullInfo = null;
            }
        }

        if ($fullInfo && $fullInfo->deliveryType === 'yandex') {
            return $this->deliveryService->resolveDisplayedDeliveryCost(
                $store,
                $address,
                $fullInfo,
                $this->calculator->calculateSubtotalWithSupplements($groupData),
            );
        }

        $deliveryInfo = $address
            ? $this->availableStoreService->getDeliveryInfo($address, $store)
            : null;

        return (float) $this->calculator->calculateDeliveryCost($groupData, $deliveryInfo);
    }
}
