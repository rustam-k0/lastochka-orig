<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\BonusHold;
use App\Models\BonusTransaction;
use App\Models\Order;
use App\Models\OrderBonus;
use App\Models\Store;
use App\Models\User;
use App\Services\Bonus\BonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyOnAssembledTest extends TestCase
{
    use RefreshDatabase;

    public function test_applies_spend_and_earn_on_assembled_converting_holds(): void
    {
        $store = Store::factory()->create(['is_bonus_enabled' => true, 'bonus_spend_percent' => 50]);
        $user = User::factory()->create(['bonus_balance' => 1000, 'bonus_reserved' => 0]);
        $order = Order::factory()->create(['user_id' => $user->id, 'store_id' => $store->id]);
        $order->items()->create([
            'product_id' => null, 'title' => 'p', 'sku' => 'TEST-SKU', 'quantity' => 1,
            'price' => 400, 'total' => 400, 'eligible_for_bonus' => true, 'status' => null,
        ]);
        OrderBonus::create([
            'order_id' => $order->id, 'type' => 'spend', 'percent' => 50,
            'eligible_amount' => 0, 'amount' => 0, 'requested_amount' => 150, 'created_at' => now(),
        ]);

        $svc = app(BonusService::class);
        $svc->recalculateMirror($order->fresh());
        $svc->reserveOnCreate($order->fresh());   // reserved 150
        $svc->applyOnAssembled($order->fresh());

        $user->refresh();
        $this->assertSame(850, (int) $user->bonus_balance);    // 1000 - 150
        $this->assertSame(0, (int) $user->bonus_reserved);
        $this->assertSame(1, BonusHold::where('order_id', $order->id)->where('status', 'converted')->count());

        $tx = BonusTransaction::where('order_id', $order->id)->where('type', 'spend')->first();
        $this->assertSame('online', $tx->source);
        $this->assertNotNull($tx->operation_id);
    }

    public function test_apply_on_assembled_is_idempotent(): void
    {
        $store = Store::factory()->create(['is_bonus_enabled' => true, 'bonus_spend_percent' => 50]);
        $user = User::factory()->create(['bonus_balance' => 1000]);
        $order = Order::factory()->create(['user_id' => $user->id, 'store_id' => $store->id]);
        $order->items()->create([
            'product_id' => null, 'title' => 'p', 'sku' => 'TEST-SKU', 'quantity' => 1,
            'price' => 400, 'total' => 400, 'eligible_for_bonus' => true, 'status' => null,
        ]);
        OrderBonus::create([
            'order_id' => $order->id, 'type' => 'spend', 'percent' => 50,
            'eligible_amount' => 0, 'amount' => 0, 'requested_amount' => 150, 'created_at' => now(),
        ]);
        $svc = app(BonusService::class);
        $svc->recalculateMirror($order->fresh());
        $svc->reserveOnCreate($order->fresh());
        $svc->applyOnAssembled($order->fresh());
        $svc->applyOnAssembled($order->fresh());
        $this->assertSame(850, (int) $user->fresh()->bonus_balance);
        $this->assertSame(1, BonusTransaction::where('order_id', $order->id)->where('type', 'spend')->count());
    }
}
