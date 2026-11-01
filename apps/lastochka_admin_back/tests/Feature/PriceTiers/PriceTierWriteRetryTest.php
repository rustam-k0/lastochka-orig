<?php

declare(strict_types=1);

namespace Tests\Feature\PriceTiers;

use App\Services\Pricing\PriceTierLadder;
use Illuminate\Support\Facades\DB;
use Tests\Support\PriceTierRetryTestCase;

/**
 * Запись лесенки идёт в DB::transaction с тремя попытками (MID-308): гонка
 * консьюмера цен 1С и мутации по одной паре (товар, магазин) даёт deadlock,
 * его переживаем повтором.
 */
class PriceTierWriteRetryTest extends PriceTierRetryTestCase
{
    public function test_sync_survives_deadlock_and_writes_ladder_once(): void
    {
        $this->deadlockOnceOn('insert into `price_tiers`');

        $changed = $this->tiers->syncPriceTiers($this->product->id, $this->shop->id, PriceTierLadder::normalize([
            ['min_quantity' => 2, 'price' => 249],
            ['min_quantity' => 5, 'price' => 199],
        ]));

        $this->assertTrue($changed);
        $this->assertSame(2, $this->matchedQueries, 'INSERT выполнен дважды: сбой и повтор');
        $this->assertSame(2, DB::table('price_tiers')->where('product_id', $this->product->id)->count());
        $this->assertSame([
            ['min_quantity' => 2.0, 'price' => 249.0],
            ['min_quantity' => 5.0, 'price' => 199.0],
        ], $this->tiers->ladderFor($this->product->id, $this->shop->id));
    }

    public function test_retry_does_not_lose_previous_ladder_when_replacing(): void
    {
        $this->tiers->syncPriceTiers($this->product->id, $this->shop->id, PriceTierLadder::normalize([
            ['min_quantity' => 3, 'price' => 100],
        ]));

        $this->deadlockOnceOn('delete from `price_tiers`');

        $this->tiers->syncPriceTiers($this->product->id, $this->shop->id, PriceTierLadder::normalize([
            ['min_quantity' => 4, 'price' => 90],
        ]));

        $this->assertSame(
            [['min_quantity' => 4.0, 'price' => 90.0]],
            $this->tiers->ladderFor($this->product->id, $this->shop->id)
        );
    }
}
