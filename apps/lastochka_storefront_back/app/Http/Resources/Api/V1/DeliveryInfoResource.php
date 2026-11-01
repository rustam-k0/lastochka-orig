<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\Delivery\DeliveryInfoData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryInfoData
 */
final class DeliveryInfoResource extends JsonResource
{
    /**
     * Убрать обёртку "data" для этого ресурса.
     */
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $data = [
            'slots' => DeliverySlotResource::collection($this->slots),
            'minOrderAmount' => (int) $this->minOrderAmount,
            'freeDeliveryFrom' => $this->freeDeliveryFrom !== null ? (int) $this->freeDeliveryFrom : null,
            'deliveryPrice' => (int) $this->deliveryPrice,
            // Ступени стоимости доставки зоны; deliveryPrice выше: базовая цена зоны
            'deliveryPriceRanges' => DeliveryPriceRanges::ranges($this->resource->ladder()->ranges()),
        ];

        if ($this->deliveryType === 'yandex') {
            $data['availablePaymentMethods'] = ['card'];
        } else {
            $data['availablePaymentMethods'] = ['card', 'cash'];
        }

        if ($this->yandexDelivery) {
            $data['yandexDelivery'] = [
                'available' => $this->yandexDelivery->available,
                'price' => $this->yandexDelivery->price,
                'eta' => $this->yandexDelivery->eta,
                'description' => $this->yandexDelivery->description,
                'availableSlots' => $this->yandexDelivery->availableSlots,
                'maxDueHours' => $this->yandexDelivery->maxDueHours,
            ];
        }

        return $data;
    }
}
