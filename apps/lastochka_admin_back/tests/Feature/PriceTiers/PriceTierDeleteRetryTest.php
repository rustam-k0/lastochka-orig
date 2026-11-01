<?php

declare(strict_types=1);

namespace Tests\Feature\PriceTiers;

use App\Services\Pricing\PriceTierLadder;
use Illuminate\Support\Facades\DB;
use Tests\Support\PriceTierRetryTestCase;

/**
 * Снятие оффера (MID-308): delete() удаляет лесенку пары внутри своей
 * транзакции, поэтому при включённом модуле внешняя транзакция идёт с тремя
 * попытками.
 */
class PriceTierDeleteRetryTest extends PriceTierRetryTestCase
{
    public function test_delete_survives_deadlock_on_ladder_removal(): void
    {
        $this->enablePriceTiers();

        $this->tiers->save($this->product, $this->shop, 279.0, 5, PriceTierLadder::normalize([
            ['min_quantity' => 2, 'price' => 249],
        ]));

        $this->deadlockOnceOn('delete from `price_tiers`');

        $this->assertTrue($this->tiers->delete($this->product, $this->shop));

        $this->assertSame(2, $this->matchedQueries, 'DELETE лесенки выполнен дважды: сбой и повтор');
        $this->assertSame([], $this->tiers->ladderFor($this->product->id, $this->shop->id));
        $this->assertSame(0, DB::table('prices')->where('product_id', $this->product->id)->whereNull('deleted_at')->count());

        $zeroOffers = DB::table('event_outbox')
            ->where('entity_type', 'products')
            ->where('entity_id', $this->product->id)
            ->where('event_type', 'price_updated')
            ->get()
            ->filter(fn ($row) => (float) (json_decode($row->payload, true)['price'] ?? -1) === 0.0);

        $this->assertCount(1, $zeroOffers, 'нулевой оффер опубликован один раз: первая попытка откатилась');
    }
}
