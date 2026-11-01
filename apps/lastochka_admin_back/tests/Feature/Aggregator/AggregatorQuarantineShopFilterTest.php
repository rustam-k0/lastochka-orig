<?php

namespace Tests\Feature\Aggregator;

use App\Models\AggregatorQuarantine;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Фильтр карантина по магазину (FOODLINK-11191): scopeFilterByShop,
 * прокинутый в запрос aggregatorQuarantine как shop_id.
 */
class AggregatorQuarantineShopFilterTest extends TestCase
{
    use DatabaseTransactions;

    /** Создать магазин с уникальным кодом. */
    private function makeShop(string $code): Shop
    {
        return Shop::create(['code' => $code, 'title' => 'Shop ' . $code]);
    }

    private function makeQuarantine(Shop $shop, string $title): AggregatorQuarantine
    {
        return AggregatorQuarantine::create([
            'shop_id' => $shop->id,
            'incoming_title' => $title,
            'reason' => AggregatorQuarantine::REASON_NO_EAN,
        ]);
    }

    public function test_filter_by_shop_returns_only_that_shop_records(): void
    {
        $shopA = $this->makeShop('flt_shop_a');
        $shopB = $this->makeShop('flt_shop_b');

        $recordA = $this->makeQuarantine($shopA, 'Товар А');
        $this->makeQuarantine($shopB, 'Товар Б');

        $found = AggregatorQuarantine::query()->filterByShop($shopA->id)->get();

        $this->assertTrue($found->contains('id', $recordA->id));
        $this->assertSame([$shopA->id], $found->pluck('shop_id')->unique()->all());
    }

    public function test_null_shop_returns_all_records(): void
    {
        $shopA = $this->makeShop('flt_shop_a');
        $shopB = $this->makeShop('flt_shop_b');

        $recordA = $this->makeQuarantine($shopA, 'Товар А');
        $recordB = $this->makeQuarantine($shopB, 'Товар Б');

        $found = AggregatorQuarantine::query()->filterByShop(null)->get();

        $this->assertTrue($found->contains('id', $recordA->id));
        $this->assertTrue($found->contains('id', $recordB->id));
    }

    public function test_shop_filter_combines_with_reason_and_resolved_scopes(): void
    {
        $shopA = $this->makeShop('flt_shop_a');
        $shopB = $this->makeShop('flt_shop_b');

        $noEanA = $this->makeQuarantine($shopA, 'Без штрихкода А');
        $this->makeQuarantine($shopB, 'Без штрихкода Б');
        AggregatorQuarantine::create([
            'shop_id' => $shopA->id,
            'incoming_title' => 'Разрешённый А',
            'reason' => AggregatorQuarantine::REASON_NO_EAN,
            'resolved_at' => now(),
        ]);

        $found = AggregatorQuarantine::query()
            ->reason(AggregatorQuarantine::REASON_NO_EAN)
            ->resolvedFilter(false)
            ->filterByShop($shopA->id)
            ->get();

        $this->assertSame([$noEanA->id], $found->pluck('id')->all());
    }
}
