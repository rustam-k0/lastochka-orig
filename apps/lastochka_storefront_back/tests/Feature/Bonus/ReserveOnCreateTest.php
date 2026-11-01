<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\BonusHold;
use App\Models\Order;
use App\Models\OrderBonus;
use App\Models\Store;
use App\Models\User;
use App\Services\Bonus\BonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReserveOnCreateTest extends TestCase
{
    use RefreshDatabase;

    private function makeSpendOrder(int $balance, int $itemTotal, int $percent, int $requested): array
    {
        $store = Store::factory()->create(['is_bonus_enabled' => true, 'bonus_spend_percent' => $percent]);
        $user = User::factory()->create(['bonus_balance' => $balance, 'bonus_reserved' => 0]);
        $order = Order::factory()->create(['user_id' => $user->id, 'store_id' => $store->id]);
        $order->items()->create([
            'product_id' => null, 'title' => 'p', 'sku' => 'TEST-SKU', 'quantity' => 1,
            'price' => $itemTotal, 'total' => $itemTotal, 'eligible_for_bonus' => true, 'status' => null,
        ]);
        OrderBonus::create([
            'order_id' => $order->id, 'type' => 'spend', 'percent' => $percent,
            'eligible_amount' => 0, 'amount' => 0, 'requested_amount' => $requested, 'created_at' => now(),
        ]);
        return [$user, $order];
    }

    public function test_reserves_spend_amount_on_create_without_changing_balance(): void
    {
        [$user, $order] = $this->makeSpendOrder(1000, 400, 50, 150);
        $svc = app(BonusService::class);
        $svc->recalculateMirror($order->fresh());
        $svc->reserveOnCreate($order->fresh());

        $user->refresh();
        $this->assertSame(1000, (int) $user->bonus_balance);
        $this->assertSame(150, (int) $user->bonus_reserved); // min(150, floor(400*50/100)=200)
        $this->assertSame(150, (int) BonusHold::where('order_id', $order->id)->where('status', 'held')->value('amount'));
    }

    public function test_reserve_on_create_is_idempotent(): void
    {
        [$user, $order] = $this->makeSpendOrder(1000, 400, 50, 150);
        $svc = app(BonusService::class);
        $svc->recalculateMirror($order->fresh());
        $svc->reserveOnCreate($order->fresh());
        $svc->reserveOnCreate($order->fresh());
        $this->assertSame(150, (int) $user->fresh()->bonus_reserved);
        $this->assertSame(1, BonusHold::where('order_id', $order->id)->count());
    }

    public function test_release_holds_frees_reservation(): void
    {
        [$user, $order] = $this->makeSpendOrder(1000, 400, 50, 150);
        $svc = app(BonusService::class);
        $svc->recalculateMirror($order->fresh());
        $svc->reserveOnCreate($order->fresh());
        $svc->releaseHolds($order->fresh());
        $this->assertSame(0, (int) $user->fresh()->bonus_reserved);
        $this->assertSame(1, BonusHold::where('order_id', $order->id)->where('status', 'released')->count());
    }
}
