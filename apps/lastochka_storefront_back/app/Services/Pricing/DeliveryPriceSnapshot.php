<?php

namespace App\Services\Pricing;

/**
 * Снимок условий доставки в заказе (MID-280): лесенка зоны на момент оформления
 * и доставка, посчитанная при оформлении. После сборки и возврата у двери доставка
 * считается по фактической сумме товаров, но не дороже цены оформления. Чистая
 * функция; копии побайтно одинаковы в storefront_back и delivery_back.
 */
final class DeliveryPriceSnapshot
{
    private DeliveryPriceLadder $ladder;

    private int $checkoutPrice;

    private function __construct(DeliveryPriceLadder $ladder, int $checkoutPrice)
    {
        $this->ladder = $ladder;
        $this->checkoutPrice = max(0, $checkoutPrice);
    }

    public static function forCheckout(DeliveryPriceLadder $ladder, int $checkoutPrice): self
    {
        return new self($ladder, $checkoutPrice);
    }

    /**
     * @param  mixed  $data  значение колонки orders.delivery_price_snapshot или ключа события (массив либо JSON-строка)
     */
    public static function fromArray($data): ?self
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (! is_array($data)
            || ! array_key_exists('base_price', $data)
            || ! array_key_exists('checkout_price', $data)
            || ! is_numeric($data['base_price'])
            || ! is_numeric($data['checkout_price'])
        ) {
            return null;
        }

        $ranges = DeliveryPriceLadder::normalize($data['ranges'] ?? []);

        if ($ranges === null) {
            return null;
        }

        return new self(
            DeliveryPriceLadder::fromRanges((int) round((float) $data['base_price']), $ranges),
            (int) round((float) $data['checkout_price'])
        );
    }

    /**
     * Доставка для фактической суммы товаров (до промокода и бонусов), не дороже цены оформления.
     */
    public function priceFor(float $amount): int
    {
        return min($this->ladder->priceFor($amount), $this->checkoutPrice);
    }

    public function ladder(): DeliveryPriceLadder
    {
        return $this->ladder;
    }

    public function checkoutPrice(): int
    {
        return $this->checkoutPrice;
    }

    /**
     * @return array{base_price: int, ranges: array<int, array{from_amount: int, price: int}>, checkout_price: int}
     */
    public function toArray(): array
    {
        return $this->ladder->toArray() + ['checkout_price' => $this->checkoutPrice];
    }
}
