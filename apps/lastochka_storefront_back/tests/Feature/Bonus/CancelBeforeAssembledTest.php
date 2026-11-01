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

class CancelBeforeAssembledTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Order}
     */
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

    public function test_cancel_before_assembled_releases_hold_without_phantom_credit(): void
    {
        [$user, $order] = $this->makeSpendOrder(1000, 400, 50, 150);
        $svc = app(BonusService::class);
        $svc->recalculateMirror($order->fresh());
        $svc->reserveOnCreate($order->fresh());   // reserved 150, balance still 1000

        $user->refresh();
        $this->assertSame(1000, (int) $user->bonus_balance);
        $this->assertSame(150, (int) $user->bonus_reserved);

        // Отмена до сборки: списания ещё не было, только резерв.
        $svc->refundAll($order->fresh());

        $user->refresh();
        // Никаких фантомных бонусов: баланс не должен стать 1150.
        $this->assertSame(1000, (int) $user->bonus_balance);
        $this->assertSame(0, (int) $user->bonus_reserved);
        $this->assertSame(
            0,
            BonusTransaction::where('order_id', $order->id)->where('type', 'refund')->count()
        );
        $this->assertSame(
            1,
            BonusHold::where('order_id', $order->id)->where('status', BonusHold::STATUS_RELEASED)->count()
        );
    }

    public function test_cancel_after_assembled_refunds_exactly_debited_amount_and_is_idempotent(): void
    {
        [$user, $order] = $this->makeSpendOrder(1000, 400, 50, 150);
        $svc = app(BonusService::class);
        $svc->recalculateMirror($order->fresh());
        $svc->reserveOnCreate($order->fresh());
        $svc->applyOnAssembled($order->fresh());   // фактическое списание 150 → баланс 850

        $user->refresh();
        $this->assertSame(850, (int) $user->bonus_balance);
        $this->assertSame(
            1,
            BonusTransaction::where('order_id', $order->id)
                ->where('type', 'spend')->where('source', 'online')->count()
        );

        // Отмена после сборки: возвращаем ровно списанное (150) → баланс 1000.
        $svc->refundAll($order->fresh());

        $user->refresh();
        $this->assertSame(1000, (int) $user->bonus_balance);
        $refundCount = BonusTransaction::where('order_id', $order->id)
            ->where('type', 'refund')->where('source', 'online')->count();
        $this->assertSame(1, $refundCount);
        $this->assertSame(
            150,
            (int) BonusTransaction::where('order_id', $order->id)
                ->where('type', 'refund')->where('source', 'online')->sum('amount')
        );

        // Идемпотентность: повторная отмена ничего не возвращает повторно.
        $svc->refundAll($order->fresh());

        $user->refresh();
        $this->assertSame(1000, (int) $user->bonus_balance);
        $this->assertSame(
            1,
            BonusTransaction::where('order_id', $order->id)
                ->where('type', 'refund')->where('source', 'online')->count()
        );
    }
}
