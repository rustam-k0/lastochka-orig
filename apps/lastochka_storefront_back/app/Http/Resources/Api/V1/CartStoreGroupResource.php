<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Support\WeightAggregator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CartStoreGroupResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     store: StoreResource,
     *     items: CartItemResource[],
     *     deliveryCost: float,
     *     assemblyCost: int,
     *     freeDeliveryFrom: int|null,
     *     nextDeliveryStep: array{fromAmount: int, price: int, amountLeft: float}|null,
     *     discount: float,
     *     promocode: PromocodeResource|null,
     *     bonus: array{isEnabled: bool, eligibleAmount: int, earnPercent: int, spendPercent: int, maxEarn: int, maxSpend: int, userBalance: int}|null,
     *     weightTotal: float|null,
     *     isHeavy: bool,
     *     availableFrom: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource['id'],
            'store' => (new StoreResource($this->resource['store']))->withDeliveryInfo([
                'deliveryPrice' => $this->resource['deliveryCost'],
                'minOrderAmount' => $this->resource['minOrderAmount'],
                'freeDeliveryFrom' => $this->resource['freeDeliveryFrom'] ?? null,
                'deliveryPriceRanges' => $this->resource['deliveryPriceRanges'] ?? [],
            ]),
            'items' => CartItemResource::collection($this->resource['items']),
            'deliveryCost' => (float) $this->resource['deliveryCost'],
            'assemblyCost' => (int) ($this->resource['assemblyCost'] ?? 0),
            'freeDeliveryFrom' => isset($this->resource['freeDeliveryFrom']) ? (int) $this->resource['freeDeliveryFrom'] : null,
            // Ближайшая ступень дешевле текущей доставки: для подсказки «добавьте ещё N ₽»
            'nextDeliveryStep' => DeliveryPriceRanges::nextStep($this->resource['nextDeliveryStep'] ?? null),
            'discount' => (float) $this->resource['discount'],
            'promocode' => $this->resource['promocode']
                ? new PromocodeResource($this->resource['promocode'])
                : null,
            'bonus' => $this->buildBonus(),
            'weightTotal' => WeightAggregator::toKg(WeightAggregator::sumGrams($this->resource['items'])),
            'isHeavy' => (bool) ($this->resource['isHeavy'] ?? false),
            'availableFrom' => $this->resource['availableFrom'] ?? null,
        ];
    }

    /**
     * @return array{isEnabled: bool, eligibleAmount: int, earnPercent: int, spendPercent: int, maxEarn: int, maxSpend: int, userBalance: int}|null
     */
    private function buildBonus(): ?array
    {
        $bonus = $this->resource['bonus'] ?? null;

        if (! is_array($bonus)) {
            return null;
        }

        return [
            'isEnabled' => (bool) ($bonus['isEnabled'] ?? false),
            'eligibleAmount' => (int) ($bonus['eligibleAmount'] ?? 0),
            'earnPercent' => (int) ($bonus['earnPercent'] ?? 0),
            'spendPercent' => (int) ($bonus['spendPercent'] ?? 0),
            'maxEarn' => (int) ($bonus['maxEarn'] ?? 0),
            'maxSpend' => (int) ($bonus['maxSpend'] ?? 0),
            'userBalance' => (int) ($bonus['userBalance'] ?? 0),
        ];
    }
}
