<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\DTOs\Delivery\DeliveryInfoData;
use App\DTOs\Delivery\DeliverySlotData;
use App\DTOs\Delivery\YandexDeliveryInfoData;
use App\Exceptions\DeliverySlotUnavailableException;
use App\Exceptions\DeliverySystemException;
use App\Exceptions\DeliveryZoneNotFoundException;
use App\Models\Address;
use App\Models\Store;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\IntegrationConfigService;
use App\Services\YandexDelivery\YandexGoClientInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

final class DeliveryService
{
    public function __construct(
        private DeliveryClientInterface $client,
        private YandexGoClientInterface $yandexClient,
    ) {}

    /**
     * Получить информацию о доставке с кешированием и fallback.
     *
     * @throws DeliverySystemException
     * @throws DeliveryZoneNotFoundException
     */
    public function getDeliveryInfo(Store $store, Address $address, string $date, array $products = []): DeliveryInfoData
    {
        $cacheKey = $this->cacheKey($store->code, $address->latitude, $address->longitude, $date, $products);

        try {
            $info = $this->client->getSlots(
                $store->code,
                (float) $address->latitude,
                (float) $address->longitude,
                $date,
                $products,
            );

            Cache::put($cacheKey, $info, now()->addMinutes(30));
        } catch (DeliveryZoneNotFoundException $e) {
            // Ошибка зоны — пробрасываем сразу, не используем кеш
            throw $e;
        } catch (DeliverySystemException $e) {
            $cached = Cache::get($cacheKey);

            if ($cached) {
                $info = $cached;
            } else {
                throw $e;
            }
        }

        if ($info->deliveryType === 'yandex') {
            $info = $this->enrichWithYandexDelivery($store, $address, $date, $info);
        }

        return $info;
    }

    /**
     * Стоимость доставки для отображения в корзине/превью — согласована с расчётом
     * при создании заказа (CreateOrderAction). Бесплатная доставка от порога; для
     * Яндекс-доставки — реальная цена `checkPrice` (тот же вызов, что в
     * CreateOrderAction), а не базовая цена зоны и не gated `yandexDelivery.available`
     * (она зависит от delivery-methods, который бывает строже, чем фактический
     * checkPrice). Иначе корзина показывает одно, а в заказе списывается другое.
     * При ошибке Яндекса — fallback на базовую цену зоны. ALADDIN-11465.
     * Своя доставка — цена ступени зоны (MID-280); у Яндекса платных ступеней
     * нет, из лесенки действует только бесплатная.
     */
    public function resolveDisplayedDeliveryCost(Store $store, Address $address, DeliveryInfoData $info, float $subtotal): float
    {
        $freeFrom = $info->ladder()->freeFrom();

        if ($freeFrom !== null && $subtotal >= $freeFrom) {
            return 0.0;
        }

        if ($info->deliveryType === 'yandex') {
            try {
                $storeCoords = [(float) $store->longitude, (float) $store->latitude];
                $addressCoords = [(float) $address->longitude, (float) $address->latitude];
                $skipDoorToDoor = \App\Services\YandexDelivery\YandexGoClient::shouldSkipDoorToDoor($address->house_number);

                return (float) $this->yandexClient->checkPrice($storeCoords, $addressCoords, $skipDoorToDoor)->priceRounded();
            } catch (\Throwable $e) {
                return (float) $info->deliveryPrice;
            }
        }

        return (float) $info->ladder()->priceFor($subtotal);
    }

    /**
     * Валидировать слот доставки (проверить, что он доступен).
     *
     * @throws DeliverySlotUnavailableException
     * @throws DeliverySystemException
     */
    public function validateSlot(Store $store, Address $address, int $slotId, array $products = []): DeliverySlotData
    {
        // Получаем актуальные слоты на дату (с теми же products, что видел клиент,
        // иначе слот, недоступный из-за времени сборки, прошёл бы валидацию)
        $date = Carbon::createFromTimestamp($slotId, config('app.timezone'))->format('Y-m-d');
        $info = $this->getDeliveryInfo($store, $address, $date, $products);

        // Проверяем, что слот доступен и возвращаем если найден
        $slot = collect($info->slots)
            ->first(fn (DeliverySlotData $s) => $s->id === $slotId);

        if (! $slot) {
            throw new DeliverySlotUnavailableException();
        }

        return $slot;
    }

    private function enrichWithYandexDelivery(
        Store $store,
        Address $address,
        string $date,
        DeliveryInfoData $info,
    ): DeliveryInfoData {
        if (! $this->isYandexEnabled()) {
            return $info;
        }

        $maxDueHours = config('yandex-delivery.max_due_hours', 4);
        $cutoff = now()->addHours($maxDueHours);
        $filteredSlots = array_values(array_filter(
            $info->slots,
            fn (DeliverySlotData $slot) => $slot->startAt->lte($cutoff),
        ));

        $storeCoords = [(float) $store->longitude, (float) $store->latitude];
        $addressCoords = [(float) $address->longitude, (float) $address->latitude];

        $methods = $this->yandexClient->getDeliveryMethods($storeCoords, $addressCoords);
        if (! $methods->expressAllowed) {
            return new DeliveryInfoData(
                slots: $filteredSlots,
                minOrderAmount: $info->minOrderAmount,
                freeDeliveryFrom: $info->freeDeliveryFrom,
                deliveryPrice: $info->deliveryPrice,
                deliveryType: $info->deliveryType,
                yandexDelivery: YandexDeliveryInfoData::unavailable(),
                assemblyPrice: $info->assemblyPrice,
                deliveryPriceRanges: $info->deliveryPriceRanges,
            );
        }

        try {
            $skipDoorToDoor = \App\Services\YandexDelivery\YandexGoClient::shouldSkipDoorToDoor($address->house_number);
            $priceData = $this->yandexClient->checkPrice($storeCoords, $addressCoords, $skipDoorToDoor);
        } catch (\Throwable $e) {
            return new DeliveryInfoData(
                slots: $filteredSlots,
                minOrderAmount: $info->minOrderAmount,
                freeDeliveryFrom: $info->freeDeliveryFrom,
                deliveryPrice: $info->deliveryPrice,
                deliveryType: $info->deliveryType,
                yandexDelivery: YandexDeliveryInfoData::unavailable(),
                assemblyPrice: $info->assemblyPrice,
                deliveryPriceRanges: $info->deliveryPriceRanges,
            );
        }

        $availableSlots = $filteredSlots;

        if (empty($availableSlots)) {
            return new DeliveryInfoData(
                slots: [],
                minOrderAmount: $info->minOrderAmount,
                freeDeliveryFrom: $info->freeDeliveryFrom,
                deliveryPrice: $priceData->priceRounded(),
                deliveryType: $info->deliveryType,
                yandexDelivery: YandexDeliveryInfoData::unavailable(),
                assemblyPrice: $info->assemblyPrice,
                deliveryPriceRanges: $info->deliveryPriceRanges,
            );
        }

        $yandexInfo = new YandexDeliveryInfoData(
            available: true,
            price: $priceData->priceRounded(),
            eta: $priceData->eta,
            description: 'Доставка Яндекс Go',
            availableSlots: array_map(fn (DeliverySlotData $s) => [
                'slotId' => $s->id,
                'start' => $s->startAt->format('H:i'),
                'end' => $s->endAt->format('H:i'),
            ], $availableSlots),
            maxDueHours: $maxDueHours,
        );

        return new DeliveryInfoData(
            slots: $availableSlots,
            minOrderAmount: $info->minOrderAmount,
            freeDeliveryFrom: $info->freeDeliveryFrom,
            deliveryPrice: $priceData->priceRounded(),
            deliveryType: $info->deliveryType,
            yandexDelivery: $yandexInfo,
            assemblyPrice: $info->assemblyPrice,
            deliveryPriceRanges: $info->deliveryPriceRanges,
        );
    }

    private function isYandexEnabled(): bool
    {
        $config = IntegrationConfigService::get('delivery');

        return $config && $config->is_active;
    }

    private function cacheKey(string $storeCode, mixed $latitude, mixed $longitude, string $date, array $products = []): string
    {
        // Слоты зависят от состава корзины (время сборки) — без хеша products
        // смена корзины отдавала бы протухшие слоты до конца TTL.
        // v2 (MID-280): в кэше лежит сериализованный readonly-DTO, у объектов до
        // выкладки свойство deliveryPriceRanges не инициализировано.
        return sprintf(
            'delivery_slots:v2:%s:%s:%s:%s:%s',
            $storeCode,
            $latitude,
            $longitude,
            $date,
            md5(json_encode($products)),
        );
    }
}
