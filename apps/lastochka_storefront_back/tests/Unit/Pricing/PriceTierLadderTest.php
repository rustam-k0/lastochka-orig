<?php

namespace Tests\Unit\Pricing;

use App\Services\Pricing\PriceTierLadder;
use PHPUnit\Framework\TestCase;

class PriceTierLadderTest extends TestCase
{
    public function test_normalize_sorts_and_rounds(): void
    {
        $ladder = PriceTierLadder::normalize([
            ['min_quantity' => '5', 'price' => '199.004'],
            ['min_quantity' => 2, 'price' => 249],
        ]);

        $this->assertSame([
            ['min_quantity' => 2.0, 'price' => 249.0],
            ['min_quantity' => 5.0, 'price' => 199.0],
        ], $ladder);
    }

    public function test_normalize_reads_custom_price_key(): void
    {
        $ladder = PriceTierLadder::normalize([['min_quantity' => 2, 'value' => 249]], 'value');

        $this->assertSame([['min_quantity' => 2.0, 'price' => 249.0]], $ladder);
    }

    public function test_normalize_empty_input_is_empty_ladder(): void
    {
        $this->assertSame([], PriceTierLadder::normalize(null));
        $this->assertSame([], PriceTierLadder::normalize([]));
    }

    public function test_normalize_rejects_broken_ladder(): void
    {
        $this->assertNull(PriceTierLadder::normalize('oops'));
        $this->assertNull(PriceTierLadder::normalize([['min_quantity' => 2]]));
        $this->assertNull(PriceTierLadder::normalize([['min_quantity' => -1, 'price' => 10]]));
        $this->assertNull(PriceTierLadder::normalize([['min_quantity' => 2, 'price' => 0]]));
        $this->assertNull(PriceTierLadder::normalize([
            ['min_quantity' => 2, 'price' => 10],
            ['min_quantity' => 2.0, 'price' => 9],
        ]));
    }

    public function test_normalize_rejects_ladder_longer_than_limit(): void
    {
        $tiers = [];
        for ($i = 1; $i <= PriceTierLadder::MAX_TIERS + 1; $i++) {
            $tiers[] = ['min_quantity' => $i, 'price' => 1000 - $i];
        }

        $this->assertNull(PriceTierLadder::normalize($tiers));
        $this->assertCount(
            PriceTierLadder::MAX_TIERS,
            PriceTierLadder::normalize(array_slice($tiers, 0, PriceTierLadder::MAX_TIERS))
        );
    }

    public function test_normalize_rejects_values_out_of_column_range(): void
    {
        $this->assertNull(PriceTierLadder::normalize([['min_quantity' => 4607093991234, 'price' => 199]]));
        $this->assertNull(PriceTierLadder::normalize([['min_quantity' => 10000000, 'price' => 199]]));
        $this->assertNull(PriceTierLadder::normalize([['min_quantity' => 2, 'price' => 10000000000]]));
        $this->assertNull(PriceTierLadder::normalize([['min_quantity' => 2, 'price' => '1e12']]));

        $this->assertSame(
            [['min_quantity' => 9999999.999, 'price' => 9999999999.99]],
            PriceTierLadder::normalize([['min_quantity' => '9999999.999', 'price' => '9999999999.99']])
        );
    }

    public function test_strictly_decreasing(): void
    {
        $this->assertTrue(PriceTierLadder::isStrictlyDecreasing(PriceTierLadder::normalize([
            ['min_quantity' => 1, 'price' => 279], ['min_quantity' => 2, 'price' => 249],
        ])));
        $this->assertFalse(PriceTierLadder::isStrictlyDecreasing(PriceTierLadder::normalize([
            ['min_quantity' => 1, 'price' => 279], ['min_quantity' => 2, 'price' => 279],
        ])));
    }

    public function test_same(): void
    {
        $a = PriceTierLadder::normalize([['min_quantity' => 2, 'price' => 249]]);

        $this->assertTrue(PriceTierLadder::same($a, PriceTierLadder::normalize([['min_quantity' => '2.000', 'price' => '249.00']])));
        $this->assertFalse(PriceTierLadder::same($a, PriceTierLadder::normalize([['min_quantity' => 2, 'price' => 248.99]])));
        $this->assertFalse(PriceTierLadder::same($a, []));
    }

    public function test_to_portions_identity_for_piece_and_unquantized_weight(): void
    {
        $ladder = PriceTierLadder::normalize([['min_quantity' => 5, 'price' => 199]]);

        $this->assertSame($ladder, PriceTierLadder::toPortions($ladder, 1, 1));
        $this->assertSame($ladder, PriceTierLadder::toPortions($ladder, 1000, 1000));
    }

    public function test_to_portions_for_quantum(): void
    {
        $ladder = PriceTierLadder::normalize([['min_quantity' => 2, 'price' => 500]]);

        $this->assertSame(
            [['min_quantity' => 10.0, 'price' => 100.0]],
            PriceTierLadder::toPortions($ladder, 1000, 200)
        );
    }

    public function test_to_portions_keeps_kopecks(): void
    {
        // 594.55 ₽/кг × 0.3 кг = 178.365 → 178.37 (с ₽/г до 4 знаков выходило 178.35)
        $ladder = PriceTierLadder::normalize([['min_quantity' => 2, 'price' => 594.55]]);

        $this->assertSame(178.37, PriceTierLadder::toPortions($ladder, 1000, 300)[0]['price']);
    }
}
