<?php

declare(strict_types=1);

namespace App\DTOs\Delivery;

use App\DTOs\Delivery\YandexDeliveryInfoData;
use App\Services\Delivery\ZoneDeliveryLadder;
use App\Services\Pricing\DeliveryPriceLadder;

final readonly class DeliveryInfoData
{
    /**
     * @param  DeliverySlotData[]  $slots
     */
    public function __construct(
        public array $slots,
        public int $minOrderAmount,
        public ?int $freeDeliveryFrom,
        public int $deliveryPrice,
        public string $deliveryType = 'own',
        public ?YandexDeliveryInfoData $yandexDelivery = null,
        // Зонная стоимость сборки из slots-api. null = у зоны нет своей цены
        // ЛИБО пакет slots-api ещё не задеплоен → потребитель падает на
        // магазинную $store->assembly_price.
        public ?int $assemblyPrice = null,
        // Ступени стоимости доставки зоны из slots-api (MID-280): [{from_amount, price}].
        // [] = у зоны нет ступеней ЛИБО пакет slots-api ещё не задеплоен → ladder()
        // строит лесенку из freeDeliveryFrom (прежний порог).
        public array $deliveryPriceRanges = [],
    ) {}

    /**
     * Лесенка стоимости доставки зоны (MID-280).
     */
    public function ladder(): DeliveryPriceLadder
    {
        return ZoneDeliveryLadder::make($this->deliveryPrice, $this->deliveryPriceRanges, $this->freeDeliveryFrom);
    }

    /**
     * Создать из ответа API DeliverySystem.
     */
    public static function fromApiResponse(array $data): self
    {
        $slots = array_map(
            fn (array $slot) => DeliverySlotData::fromApiResponse($slot),
            $data['slots'] ?? [],
        );

        return new self(
            slots: $slots,
            minOrderAmount: (int) $data['min_order_price'],
            freeDeliveryFrom: isset($data['shipping_free_from']) ? (int) $data['shipping_free_from'] : null,
            deliveryPrice: (int) $data['shipping_price'],
            deliveryType: $data['delivery_type'] ?? 'own',
            assemblyPrice: isset($data['assembly_price']) ? (int) $data['assembly_price'] : null,
            deliveryPriceRanges: DeliveryPriceLadder::normalize($data['shipping_price_ranges'] ?? null) ?? [],
        );
    }
}
