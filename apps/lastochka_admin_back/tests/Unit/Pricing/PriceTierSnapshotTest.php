<?php

namespace Tests\Unit\Pricing;

use App\Services\Pricing\PriceTierSnapshot;
use PHPUnit\Framework\TestCase;

class PriceTierSnapshotTest extends TestCase
{
    private function fromOne(): PriceTierSnapshot
    {
        return PriceTierSnapshot::build(269.0, 309.0, false, [
            ['min_quantity' => 5, 'price' => 199],
            ['min_quantity' => 1, 'price' => 279],
            ['min_quantity' => 2, 'price' => 249],
        ]);
    }

    private function fromTwo(): PriceTierSnapshot
    {
        return PriceTierSnapshot::build(269.0, 309.0, false, [
            ['min_quantity' => 2, 'price' => 249],
            ['min_quantity' => 5, 'price' => 199],
        ]);
    }

    public function test_first_tier_is_regular_price_and_eligible(): void
    {
        $quote = $this->fromOne()->quote(1);

        $this->assertSame(279.0, $quote->unitPrice);
        $this->assertNull($quote->unitPriceOld);
        $this->assertTrue($quote->isEligible);
        $this->assertTrue($quote->tierApplied);
    }

    public function test_cheaper_tier_is_discounted_against_first_tier(): void
    {
        $quote = $this->fromOne()->quote(3);

        $this->assertSame(249.0, $quote->unitPrice);
        $this->assertSame(279.0, $quote->unitPriceOld);
        $this->assertFalse($quote->isEligible);
    }

    public function test_threshold_boundary_is_inclusive(): void
    {
        $this->assertSame(249.0, $this->fromOne()->quote(4.999)->unitPrice);
        $this->assertSame(199.0, $this->fromOne()->quote(5)->unitPrice);
        $this->assertSame(199.0, $this->fromOne()->quote(40)->unitPrice);
    }

    public function test_quantity_below_first_threshold_falls_back_to_base(): void
    {
        $quote = $this->fromTwo()->quote(1);

        $this->assertSame(269.0, $quote->unitPrice);
        $this->assertSame(309.0, $quote->unitPriceOld);
        $this->assertFalse($quote->isEligible);
        $this->assertFalse($quote->tierApplied);
    }

    public function test_ladder_starting_above_one_uses_base_price_as_reference(): void
    {
        $quote = $this->fromTwo()->quote(2);

        $this->assertSame(249.0, $quote->unitPrice);
        $this->assertSame(309.0, $quote->unitPriceOld);
        $this->assertFalse($quote->isEligible);
    }

    public function test_reference_without_base_discount_is_base_price(): void
    {
        $snapshot = PriceTierSnapshot::build(300.0, null, true, [['min_quantity' => 3, 'price' => 250]]);

        $this->assertSame(300.0, $snapshot->quote(3)->unitPriceOld);
        $this->assertTrue($snapshot->quote(2)->isEligible);
        $this->assertNull($snapshot->quote(2)->unitPriceOld);
    }

    public function test_non_monotonic_tier_above_reference_is_not_a_discount(): void
    {
        $snapshot = PriceTierSnapshot::build(100.0, null, true, [
            ['min_quantity' => 1, 'price' => 100],
            ['min_quantity' => 3, 'price' => 120],
        ]);

        $quote = $snapshot->quote(3);

        $this->assertSame(120.0, $quote->unitPrice);
        $this->assertNull($quote->unitPriceOld);
        $this->assertTrue($quote->isEligible);
    }

    public function test_fractional_threshold(): void
    {
        $snapshot = PriceTierSnapshot::build(100.0, null, true, [['min_quantity' => 12.5, 'price' => 90]]);

        $this->assertSame(100.0, $snapshot->quote(12)->unitPrice);
        $this->assertSame(90.0, $snapshot->quote(13)->unitPrice);
    }

    public function test_empty_or_broken_ladder_gives_no_snapshot(): void
    {
        $this->assertNull(PriceTierSnapshot::build(100.0, null, true, []));
        $this->assertNull(PriceTierSnapshot::build(100.0, null, true, [['min_quantity' => 0, 'price' => 90]]));
        $this->assertNull(PriceTierSnapshot::fromArray(null));
        $this->assertNull(PriceTierSnapshot::fromArray('not json'));
        $this->assertNull(PriceTierSnapshot::fromArray(['base_price' => 100]));
    }

    public function test_round_trip_through_array_and_json(): void
    {
        $array = $this->fromOne()->toArray();

        $this->assertSame(269.0, $array['base_price']);
        $this->assertSame(309.0, $array['base_price_old']);
        $this->assertFalse($array['base_is_eligible']);
        $this->assertSame([1.0, 2.0, 5.0], array_column($array['tiers'], 'min_quantity'));

        $restored = PriceTierSnapshot::fromArray(json_encode($array));

        $this->assertSame($array, $restored->toArray());
        $this->assertSame(269.0, $restored->basePrice());
    }
}
