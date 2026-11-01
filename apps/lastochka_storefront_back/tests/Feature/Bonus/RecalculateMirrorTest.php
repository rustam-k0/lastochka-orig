<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\Order;
use App\Models\OrderBonus;
use App\Models\Store;
use App\Models\User;
use App\Services\Bonus\BonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecalculateMirrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_mirror_fields_without_touching_balance(): void
    {
        $store = Store::factory()->create(['is_bonus_enabled' => true, 'bonus_spend_percent' => 50]);
        $user = User::factory()->create(['bonus_balance' => 1000]);
        $order = Order::factory()->create(['user_id' => $user->id, 'store_id' => $store->id]);

        $order->items()->create([
            'product_id' => null,
            'title' => 'p',
            'sku' => 'TEST-SKU',
            'quantity' => 1,
            'price' => 400,
            'total' => 400,
            'eligible_for_bonus' => true,
            'status' => null,
        ]);

        OrderBonus::create([
            'order_id' => $order->id,
            'type' => 'spend',
            'percent' => 50,
            'eligible_amount' => 0,
            'amount' => 0,
            'requested_amount' => 999,
            'created_at' => now(),
        ]);

        app(BonusService::class)->recalculateMirror($order->fresh());

        $order->refresh();
        $this->assertSame(400.0, (float) $order->eligible_amount);
        $this->assertSame(200, (int) $order->bonus_spent); // min(999, floor(400*50/100))
        $this->assertSame(1000, (int) $user->fresh()->bonus_balance); // balance untouched
    }
}
