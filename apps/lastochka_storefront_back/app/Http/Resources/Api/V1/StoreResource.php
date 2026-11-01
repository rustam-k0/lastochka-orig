<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Store
 */
final class StoreResource extends JsonResource
{
    /**
     * deliveryPriceRanges — ступени [{from_amount, price}]; нет ключа
     * (вызов без адреса) — ступени магазина из модели.
     *
     * @var array{deliveryPrice: int, minOrderAmount: int, freeDeliveryFrom?: int|null, deliveryPriceRanges?: array<int, array{from_amount: int, price: int}>}|null
     */
    private ?array $deliveryInfo = null;

    private bool $showPickupInfo = false;

    private ?float $distanceKm = null;

    /**
     * @param  array{deliveryPrice: int, minOrderAmount: int, freeDeliveryFrom?: int|null, deliveryPriceRanges?: array<int, array{from_amount: int, price: int}>}|null  $deliveryInfo
     */
    public function withDeliveryInfo(?array $deliveryInfo): self
    {
        $this->deliveryInfo = $deliveryInfo;

        return $this;
    }

    /**
     * Расстояние от точки запроса до магазина, км.
     */
    public function withDistance(float $distanceKm): self
    {
        $this->distanceKm = $distanceKm;

        return $this;
    }

    public function withPickupInfo(): self
    {
        $this->showPickupInfo = true;

        return $this;
    }

    public function toArray(Request $request): array
    {

        // Используем deliveryInfo если доступно (с адресом), иначе из модели
        $minOrderAmount = $this->deliveryInfo !== null
            ? (int) $this->deliveryInfo['minOrderAmount']
            : $this->min_order_amount;
        $deliveryPrice = $this->deliveryInfo !== null
            ? (int) $this->deliveryInfo['deliveryPrice']
            : $this->delivery_price;
        $freeDeliveryFrom = $this->deliveryInfo !== null
            ? ($this->deliveryInfo['freeDeliveryFrom'] ?? null)
            : $this->free_delivery_from;

        $data = [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'workingHours' => $this->working_hours
                ? new WorkingHoursResource($this->working_hours)
                : null,
            'isActive' => (bool) $this->is_active,
            'isDefault' => (bool) $this->is_default,
            'type' => (string) ($this->type ?? 'shop'),
            'image' => ImageResource::fromPath($this->image_path, $this->id),
            'rating' => $this->rating ? (float) $this->rating : null,
            'isB2b' => $this->when(
                app(ModuleConfigService::class)->isB2bEnabled(),
                fn (): bool => (bool) $this->is_b2b,
            ),
            'city' => $this->whenLoaded('city', fn () => [
                'id' => $this->city->id,
                'name' => $this->city->name,
            ]),
            'isPickupEnabled' => (bool) $this->is_pickup_enabled,
            'pickupPaymentMethods' => $this->pickup_payment_methods ?? ['card'],
            'address' => $this->address,
            'description' => $this->description,
            'latitude' => $this->latitude ? (float) $this->latitude : null,
            'longitude' => $this->longitude ? (float) $this->longitude : null,
            'minPickupOrderAmount' => $this->min_pickup_order_amount ?? $this->min_order_amount ?? 0,
            'customCheckboxes' => (object) ($this->custom_checkboxes ?? []),
        ];

        // Добавляем deliveryInfo всегда, если установлен через withDeliveryInfo
        if ($this->deliveryInfo !== null) {
            $data['deliveryInfo'] = [
                'deliveryPrice' => $deliveryPrice,
                'minOrderAmount' => $minOrderAmount,
                'freeDeliveryFrom' => $freeDeliveryFrom,
                // Ступени стоимости доставки: по адресу ступени зоны, без адреса ступени магазина
                'deliveryPriceRanges' => DeliveryPriceRanges::ranges(
                    array_key_exists('deliveryPriceRanges', $this->deliveryInfo)
                        ? $this->deliveryInfo['deliveryPriceRanges']
                        : $this->resource->deliveryLadder()->ranges()
                ),
            ];
        }

        if ($this->distanceKm !== null) {
            $data['distanceKm'] = round($this->distanceKm, 3);
        }

        if ($this->showPickupInfo) {
            $data['isPickupEnabled'] = (bool) $this->is_pickup_enabled;
            $data['pickupPaymentMethods'] = $this->pickup_payment_methods ?? ['card'];
            $data['minOrderAmount'] = $this->min_pickup_order_amount ?? $this->min_order_amount ?? 0;
        }

        return $data;
    }
}
