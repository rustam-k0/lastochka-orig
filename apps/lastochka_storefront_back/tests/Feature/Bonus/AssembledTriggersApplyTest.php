<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\BonusTransaction;
use App\Models\Order;
use App\Models\OrderBonus;
use App\Models\Store;
use App\Models\User;
use App\Services\Bonus\BonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssembledTriggersApplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_applies_bonuses_when_order_becomes_assembled(): void
    {
        $store = Store::factory()->create(['is_bonus_enabled' => true, 'bonus_spend_percent' => 50]);
        $user = User::factory()->create(['bonus_balance' => 1000, 'bonus_reserved' => 0]);
        $order = Order::factory()->create([
            'user_id' => $user->id, 'store_id' => $store->id, 'status' => \App\Enums\OrderStatus::Assembling,
        ]);
        $order->items()->create([
            'product_id' => null, 'title' => 'p', 'sku' => 'TEST-SKU', 'quantity' => 1,
            'price' => 400, 'total' => 400, 'eligible_for_bonus' => true, 'status' => null,
        ]);
        \App\Models\OrderBonus::create([
            'order_id' => $order->id, 'type' => 'spend', 'percent' => 50,
            'eligible_amount' => 0, 'amount' => 0, 'requested_amount' => 150, 'created_at' => now(),
        ]);

        $svc = app(\App\Services\Bonus\BonusService::class);
        $svc->recalculateMirror($order->fresh());
        $svc->reserveOnCreate($order->fresh());     // reserved 150

        // Прогон входящего события "assembled" через реальный трансформер (без items → else-ветка recalc).
        app(\App\RabbitMQ\Transformers\OrderEventTransformer::class)
            ->afterUpdate($order->fresh(), ['status' => 'assembled']);

        $user->refresh();
        $this->assertSame(850, (int) $user->bonus_balance);   // списано 150 на сборке
        $this->assertSame(0, (int) $user->bonus_reserved);
        $tx = \App\Models\BonusTransaction::where('order_id', $order->id)->where('type', 'spend')->first();
        $this->assertNotNull($tx);
        $this->assertSame('online', $tx->source);
    }
}
