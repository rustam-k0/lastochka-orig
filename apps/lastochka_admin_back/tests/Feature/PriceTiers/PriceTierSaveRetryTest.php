<?php

declare(strict_types=1);

namespace Tests\Feature\PriceTiers;

use App\Models\Shop;
use App\Services\Pricing\PriceTierLadder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PriceTierRetryTestCase;

/**
 * Запись лесенки из мутаций (MID-308): save() и applyToAll() держат лесенку
 * внутри своей транзакции, поэтому три попытки задаёт именно внешняя
 * транзакция. Без лесенки число попыток прежнее (одна).
 */
class PriceTierSaveRetryTest extends PriceTierRetryTestCase
{
    /** @return array<int, array{min_quantity: float, price: float}> */
    private function ladder(): array
    {
        return PriceTierLadder::normalize([['min_quantity' => 2, 'price' => 249]]);
    }

    private function outboxCount(string $eventType): int
    {
        return DB::table('event_outbox')
            ->where('entity_type', 'products')
            ->where('entity_id', $this->product->id)
            ->where('event_type', $eventType)
            ->count();
    }

    public function test_save_with_ladder_survives_deadlock_without_duplicates(): void
    {
        $this->deadlockOnceOn('insert into `price_tiers`');

        $priceRow = $this->tiers->save($this->product, $this->shop, 279.0, 5, $this->ladder());

        $this->assertSame(279.0, (float) $priceRow->value);
        $this->assertSame(2, $this->matchedQueries, 'INSERT лесенки выполнен дважды: сбой и повтор');
        $this->assertSame(1, DB::table('prices')->where('product_id', $this->product->id)->count());
        $this->assertSame($this->ladder(), $this->tiers->ladderFor($this->product->id, $this->shop->id));
        $this->assertSame(1, $this->outboxCount('price_updated'), 'события первой попытки откатились вместе с транзакцией');
        $this->assertSame(1, $this->outboxCount('stock_updated'));
    }

    public function test_save_without_ladder_keeps_single_attempt(): void
    {
        $this->deadlockOnceOn('insert into `prices`');

        try {
            $this->tiers->save($this->product, $this->shop, 279.0, 5);
            $this->fail('Без лесенки транзакция не повторяется: deadlock обязан выйти наружу, как до фичи.');
        } catch (QueryException $e) {
            $this->assertSame(1, $this->matchedQueries);
        }

        $this->assertSame(0, DB::table('prices')->where('product_id', $this->product->id)->count());
    }

    public function test_apply_to_all_with_ladder_survives_deadlock_and_counts_shops_once(): void
    {
        $shopsWithCode = Shop::query()->whereNotNull('code')->where('code', '!=', '')->count();

        $this->deadlockOnceOn('insert into `price_tiers`');

        $processed = $this->tiers->applyToAll($this->product, 279.0, 3, $this->ladder());

        $this->assertSame($shopsWithCode, $processed, 'повтор транзакции чанка не удваивает счётчик магазинов');
        $this->assertSame($shopsWithCode, DB::table('prices')->where('product_id', $this->product->id)->count());
        $this->assertSame($this->ladder(), $this->tiers->ladderFor($this->product->id, $this->shop->id));
        $this->assertSame($shopsWithCode, $this->outboxCount('price_updated'));
    }
}
