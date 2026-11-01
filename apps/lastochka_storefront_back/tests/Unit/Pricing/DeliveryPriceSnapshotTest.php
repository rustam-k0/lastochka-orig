<?php

namespace Tests\Unit\Pricing;

use App\Services\Pricing\DeliveryPriceLadder;
use App\Services\Pricing\DeliveryPriceSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * MID-280: снимок условий доставки в заказе.
 */
class DeliveryPriceSnapshotTest extends TestCase
{
    private function ladder(): DeliveryPriceLadder
    {
        return DeliveryPriceLadder::fromRanges(200, [
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ]);
    }

    public function test_price_goes_down_when_amount_grows(): void
    {
        $snapshot = DeliveryPriceSnapshot::forCheckout($this->ladder(), 100);

        $this->assertSame(0, $snapshot->priceFor(3200));
    }

    public function test_price_is_capped_by_checkout_price_when_amount_drops(): void
    {
        $snapshot = DeliveryPriceSnapshot::forCheckout($this->ladder(), 100);

        // Сумма упала ниже 1000: по лесенке 200, но не дороже оформления
        $this->assertSame(100, $snapshot->priceFor(900));
    }

    public function test_fixed_price_without_ranges_never_changes(): void
    {
        // СДЭК/Почта/самовывоз: ступеней нет, доставка = цена оформления
        $snapshot = DeliveryPriceSnapshot::forCheckout(DeliveryPriceLadder::fromRanges(350, []), 350);

        $this->assertSame(350, $snapshot->priceFor(0));
        $this->assertSame(350, $snapshot->priceFor(100000));
    }

    public function test_to_array_and_back(): void
    {
        $snapshot = DeliveryPriceSnapshot::forCheckout($this->ladder(), 100);
        $array = $snapshot->toArray();

        $this->assertSame([
            'base_price' => 200,
            'ranges' => [
                ['from_amount' => 1000, 'price' => 100],
                ['from_amount' => 3000, 'price' => 0],
            ],
            'checkout_price' => 100,
        ], $array);

        $restored = DeliveryPriceSnapshot::fromArray(json_encode($array));

        $this->assertNotNull($restored);
        $this->assertSame($array, $restored->toArray());
        $this->assertSame(100, $restored->checkoutPrice());
        $this->assertSame(200, $restored->ladder()->basePrice());
    }

    public function test_from_array_without_ranges_key_means_no_ranges(): void
    {
        $snapshot = DeliveryPriceSnapshot::fromArray(['base_price' => 0, 'checkout_price' => 0]);

        $this->assertNotNull($snapshot);
        $this->assertSame([], $snapshot->ladder()->ranges());
        $this->assertSame(0, $snapshot->priceFor(500));
    }

    public function test_from_array_rejects_broken_values(): void
    {
        foreach (self::brokenSnapshots() as $case => [$data]) {
            $this->assertNull(DeliveryPriceSnapshot::fromArray($data), $case);
        }
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function brokenSnapshots(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'broken json' => ['{oops'],
            'empty array' => [[]],
            'no checkout_price' => [['base_price' => 200, 'ranges' => []]],
            'no base_price' => [['checkout_price' => 200, 'ranges' => []]],
            'not numeric price' => [['base_price' => 'x', 'checkout_price' => 200]],
            'broken ranges' => [['base_price' => 200, 'checkout_price' => 200, 'ranges' => [['from_amount' => -1, 'price' => 0]]]],
        ];
    }
}
