<?php

namespace Tests\Unit\Pricing;

use App\Services\Pricing\DeliveryPriceLadder;
use PHPUnit\Framework\TestCase;

/**
 * MID-280: лесенка стоимости доставки от суммы заказа.
 */
class DeliveryPriceLadderTest extends TestCase
{
    private function taskExample(): DeliveryPriceLadder
    {
        // Пример из задачи: цена зоны 200, от 1000 → 100, от 3000 → бесплатно
        return DeliveryPriceLadder::fromRanges(200, [
            ['from_amount' => 3000, 'price' => 0],
            ['from_amount' => 1000, 'price' => 100],
        ]);
    }

    public function test_price_for_follows_ranges_with_inclusive_lower_bound(): void
    {
        $ladder = $this->taskExample();

        $this->assertSame(200, $ladder->priceFor(0));
        $this->assertSame(200, $ladder->priceFor(999.99));
        $this->assertSame(100, $ladder->priceFor(1000));
        $this->assertSame(100, $ladder->priceFor(2999.99));
        $this->assertSame(0, $ladder->priceFor(3000));
        $this->assertSame(0, $ladder->priceFor(100000));
    }

    public function test_price_for_rounds_amount_to_kopecks(): void
    {
        // 999.999 после округления до копеек = 1000.00 → ступень «от 1000»
        $this->assertSame(100, $this->taskExample()->priceFor(999.999));
        $this->assertSame(200, $this->taskExample()->priceFor(999.994));
    }

    public function test_ranges_are_sorted_by_from_amount(): void
    {
        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $this->taskExample()->ranges());
    }

    public function test_no_ranges_means_base_price(): void
    {
        $ladder = DeliveryPriceLadder::fromRanges(150, []);

        $this->assertSame(150, $ladder->priceFor(0));
        $this->assertSame(150, $ladder->priceFor(50000));
        $this->assertNull($ladder->nextStep(100));
        $this->assertNull($ladder->freeFrom());
    }

    public function test_from_free_delivery_from_reproduces_current_threshold(): void
    {
        $ladder = DeliveryPriceLadder::fromFreeDeliveryFrom(150, 1000);

        $this->assertSame(150, $ladder->priceFor(999.99));
        $this->assertSame(0, $ladder->priceFor(1000));
        $this->assertSame(1000, $ladder->freeFrom());
        $this->assertSame([['from_amount' => 1000, 'price' => 0]], $ladder->ranges());
    }

    public function test_from_free_delivery_from_without_threshold_has_no_ranges(): void
    {
        $ladder = DeliveryPriceLadder::fromFreeDeliveryFrom(150, null);

        $this->assertSame([], $ladder->ranges());
        $this->assertSame(150, $ladder->priceFor(100000));
    }

    public function test_zero_threshold_means_always_free(): void
    {
        // Витрина сейчас трактует free_delivery_from = 0 как «всегда бесплатно»
        $ladder = DeliveryPriceLadder::fromFreeDeliveryFrom(150, 0);

        $this->assertSame(0, $ladder->priceFor(0));
        $this->assertSame(0, $ladder->freeFrom());
    }

    public function test_next_step_points_to_nearest_cheaper_range(): void
    {
        $ladder = $this->taskExample();

        $this->assertSame(['from_amount' => 1000, 'price' => 100, 'amount_left' => 340.0], $ladder->nextStep(660));
        $this->assertSame(['from_amount' => 3000, 'price' => 0, 'amount_left' => 1499.5], $ladder->nextStep(1500.5));
        $this->assertNull($ladder->nextStep(3000));
    }

    public function test_next_step_skips_ranges_with_the_same_price(): void
    {
        $ladder = DeliveryPriceLadder::fromRanges(200, [
            ['from_amount' => 500, 'price' => 200],
            ['from_amount' => 1000, 'price' => 100],
        ]);

        $this->assertSame(['from_amount' => 1000, 'price' => 100, 'amount_left' => 700.0], $ladder->nextStep(300));
    }

    public function test_free_from_returns_zero_price_range(): void
    {
        $this->assertSame(3000, $this->taskExample()->freeFrom());
    }

    public function test_to_array(): void
    {
        $this->assertSame([
            'base_price' => 200,
            'ranges' => [
                ['from_amount' => 1000, 'price' => 100],
                ['from_amount' => 3000, 'price' => 0],
            ],
        ], $this->taskExample()->toArray());
    }

    public function test_normalize_accepts_json_string_and_numeric_strings(): void
    {
        $this->assertSame(
            [['from_amount' => 1000, 'price' => 100]],
            DeliveryPriceLadder::normalize('[{"from_amount":"1000","price":100.0}]')
        );
        $this->assertSame([], DeliveryPriceLadder::normalize(null));
        $this->assertSame([], DeliveryPriceLadder::normalize(''));
        $this->assertSame([], DeliveryPriceLadder::normalize('[]'));
    }

    public function test_normalize_rejects_broken_ranges(): void
    {
        foreach (self::brokenRanges() as $case => [$ranges]) {
            $this->assertNull(DeliveryPriceLadder::normalize($ranges), $case);
        }
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function brokenRanges(): array
    {
        return [
            'not an array' => [42],
            'broken json' => ['{oops'],
            'row is not an array' => [[5]],
            'missing price' => [[['from_amount' => 1000]]],
            'missing from_amount' => [[['price' => 100]]],
            'negative price' => [[['from_amount' => 1000, 'price' => -1]]],
            'fractional from_amount' => [[['from_amount' => 999.5, 'price' => 100]]],
            'not numeric' => [[['from_amount' => 'abc', 'price' => 100]]],
            'boolean' => [[['from_amount' => true, 'price' => 100]]],
            'too big' => [[['from_amount' => 2147483648, 'price' => 100]]],
            'duplicate from_amount' => [[['from_amount' => 1000, 'price' => 100], ['from_amount' => 1000, 'price' => 50]]],
            'too many ranges' => [array_map(fn (int $i) => ['from_amount' => $i * 100, 'price' => 1], range(1, 21))],
        ];
    }

    public function test_broken_ranges_fall_back_to_base_price(): void
    {
        $ladder = DeliveryPriceLadder::fromRanges(200, [['from_amount' => -5, 'price' => 0]]);

        $this->assertSame([], $ladder->ranges());
        $this->assertSame(200, $ladder->priceFor(10000));
    }

    public function test_negative_base_price_is_clamped_to_zero(): void
    {
        $this->assertSame(0, DeliveryPriceLadder::fromRanges(-10, [])->priceFor(100));
    }
}
