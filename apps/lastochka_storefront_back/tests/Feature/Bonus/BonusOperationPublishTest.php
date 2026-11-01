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

class BonusOperationPublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_bonus_operation_outbox_row_on_assembled_spend(): void
    {
        $store = Store::factory()->create(['is_bonus_enabled' => true, 'bonus_spend_percent' => 50]);
        $user = User::factory()->create(['bonus_balance' => 1000, 'loyalty_id' => 'LTY-X']);
        $order = Order::factory()->create(['user_id' => $user->id, 'store_id' => $store->id, 'external_id' => 'EXT-9']);
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

        $row = \Comrades\RabbitMQEvents\Models\EventOutbox::where('routing_key', 'bonus_operation.created')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('LTY-X', $row->payload['loyalty_id']);
        $this->assertSame('spend', $row->payload['type']);
        $this->assertSame(150, $row->payload['amount']);
        $this->assertSame('online', $row->payload['operation_source']);
    }
}
