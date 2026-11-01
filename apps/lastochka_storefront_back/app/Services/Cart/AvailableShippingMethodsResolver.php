<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\Cart;
use App\Services\Settings\ModuleConfigService;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;

/**
 * Резолвер списка доступных способов доставки для корзины.
 *
 * Доступность считается как пересечение per-магазин: метод доступен ↔
 * включён модуль ↕ интеграция активна У КАЖДОГО уникального store_id корзины.
 *
 * Скрытый маркетплейс не раскрывает конкретные store_id в reason'ах —
 * клиент видит только «Часть товаров корзины недоступна для X».
 */
final class AvailableShippingMethodsResolver
{
    public function __construct(
        private readonly ModuleConfigService $moduleConfig,
        private readonly SlotsApiClientInterface $slotsApi,
    ) {
    }

    /**
     * @return list<array{method: string, label: string, available: bool, reason: string|null}>
     */
    public function resolve(?Cart $cart): array
    {
        $cdekModuleEnabled = $this->moduleConfig->isCdekEnabled();
        $rpModuleEnabled = $this->moduleConfig->isRussianPostEnabled();

        $cdekAvailable = $cdekModuleEnabled;
        $rpAvailable = $rpModuleEnabled;
        $cdekReason = $cdekModuleEnabled ? null : 'Модуль выключен';
        $rpReason = $rpModuleEnabled ? null : 'Модуль выключен';

        $hasPickupOnly = false;

        if ($cart && $cart->relationLoaded('items') ? $cart->items->isNotEmpty() : ($cart && $cart->items()->exists())) {
            $items = $cart->relationLoaded('items')
                ? $cart->items
                : $cart->items()->get(['id', 'store_id', 'product_id']);

            // pickup_only обязателен в выборке: без колонки в select
            // isPickupOnly() увидел бы null и «Не доставлять» не сработал.
            $items->loadMissing('product:id,pickup_only');

            $hasPickupOnly = $items->contains(fn ($item) => (bool) $item->product?->isPickupOnly());

            $storeIds = $items
                ->pluck('store_id')
                ->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values();

            if ($cdekModuleEnabled || $rpModuleEnabled) {
                foreach ($storeIds as $storeId) {
                    $configs = $this->slotsApi->getShopIntegrationConfigs(
                        $storeId,
                        ['delivery_cdek', 'delivery_russian_post'],
                    );

                    $byType = collect($configs)->keyBy('integration_type');

                    if ($cdekAvailable && ! ($byType['delivery_cdek']['is_active'] ?? false)) {
                        $cdekAvailable = false;
                        $cdekReason = 'Часть товаров корзины недоступна для СДЭК';
                    }

                    if ($rpAvailable && ! ($byType['delivery_russian_post']['is_active'] ?? false)) {
                        $rpAvailable = false;
                        $rpReason = 'Часть товаров корзины недоступна для Почты России';
                    }

                    if (! $cdekAvailable && ! $rpAvailable) {
                        break;
                    }
                }
            }
        }

        $courierAvailable = true;
        $courierReason = null;

        if ($hasPickupOnly) {
            $pickupOnlyReason = 'В заказе есть товар, доступный только для самовывоза.';
            $courierAvailable = false;
            $courierReason = $pickupOnlyReason;
            $cdekAvailable = false;
            $cdekReason = $pickupOnlyReason;
            $rpAvailable = false;
            $rpReason = $pickupOnlyReason;
        }

        return [
            [
                'method' => 'own_courier',
                'label' => 'Доставка курьером',
                'available' => $courierAvailable,
                'reason' => $courierReason,
            ],
            [
                'method' => 'pickup',
                'label' => 'Самовывоз из магазина',
                'available' => true,
                'reason' => null,
            ],
            [
                'method' => 'cdek',
                'label' => 'ПВЗ СДЭК',
                'available' => $cdekAvailable,
                'reason' => $cdekReason,
            ],
            [
                'method' => 'russian_post',
                'label' => 'ОПС Почты России',
                'available' => $rpAvailable,
                'reason' => $rpReason,
            ],
        ];
    }
}
