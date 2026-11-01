<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Bonus;

use App\Enums\OrderStatus;
use App\Models\BonusHold;
use App\Models\BonusTransaction;
use App\Models\Order;
use App\Models\OrderBonus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Bonus\BonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Проверка идемпотентности BonusService: повторные вызовы с тем же состоянием
 * не создают лишних BonusTransaction и не двигают баланс.
 *
 * Идемпотентность обеспечивается дельтой (new - current): если ничего
 * не поменялось — дельта = 0, никаких записей.
 */
final class BonusIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrderWithBonus(
        string $type,
        int $percent,
        int $requested,
        int $eligibleInitial,
        int $bonusBalance,
        int $initialAmount
    ): array {
        $user = User::factory()->create(['bonus_balance' => $bonusBalance]);
        $store = Store::factory()->create([
            'code' => 'TEST',
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => $type === 'earn' ? $percent : 5,
            'bonus_spend_percent' => $type === 'spend' ? $percent : 10,
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $product = Product::factory()->for($store)->create([
            'price' => $eligibleInitial,
            'price_old' => null,
        ]);

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => $eligibleInitial,
            'discount' => 0,
            'delivery_cost' => 0,
            'total' => $eligibleInitial - ($type === 'spend' ? $initialAmount : 0),
            'items_count' => 1,
            'eligible_amount' => $eligibleInitial,
            'bonus_earned' => $type === 'earn' ? $initialAmount : 0,
            'bonus_spent' => $type === 'spend' ? $initialAmount : 0,
        ]);

        OrderItem::factory()->for($order)->for($product)->create([
            'price' => $eligibleInitial,
            'quantity' => 1,
            'total' => $eligibleInitial,
            'eligible_for_bonus' => true,
        ]);

        OrderBonus::create([
            'order_id' => $order->id,
            'type' => $type,
            'percent' => $percent,
            'eligible_amount' => $eligibleInitial,
            'amount' => $initialAmount,
            'requested_amount' => $requested,
            'created_at' => now(),
        ]);

        return [$user, $store, $order->fresh(['bonus', 'items'])];
    }

    public function test_recalculate_mirror_is_noop_when_state_unchanged_spend(): void
    {
        [$user, $store, $order] = $this->makeOrderWithBonus(
            type: 'spend', percent: 10, requested: 100,
            eligibleInitial: 1000, bonusBalance: 200, initialAmount: 100,
        );

        // Первый пересчёт зеркала
        app(BonusService::class)->recalculateMirror($order);
        $balanceAfter1 = (int) User::find($user->id)->bonus_balance;
        $txCount1 = BonusTransaction::where('order_id', $order->id)->count();

        // Второй пересчёт — ничего не изменилось в items
        app(BonusService::class)->recalculateMirror($order->fresh());
        $balanceAfter2 = (int) User::find($user->id)->bonus_balance;
        $txCount2 = BonusTransaction::where('order_id', $order->id)->count();

        // recalculateMirror НИКОГДА не трогает баланс/транзакции — зеркало чистая функция.
        $this->assertSame(200, $balanceAfter1, 'баланс не тронут зеркальным пересчётом');
        $this->assertSame($balanceAfter1, $balanceAfter2, 'баланс не изменился между двумя recalc');
        $this->assertSame(0, $txCount1, 'зеркальный пересчёт не создаёт BonusTransaction');
        $this->assertSame($txCount1, $txCount2, 'новых BonusTransaction не создано');
    }

    public function test_apply_on_assembled_earn_is_idempotent(): void
    {
        [$user, $store, $order] = $this->makeOrderWithBonus(
            type: 'earn', percent: 5, requested: 0,
            eligibleInitial: 1000, bonusBalance: 0, initialAmount: 50,
        );

        // Начисление earn — по факту сборки (applyOnAssembled), дельтой и идемпотентно.
        app(BonusService::class)->applyOnAssembled($order);
        $balance1 = (int) User::find($user->id)->bonus_balance;

        app(BonusService::class)->applyOnAssembled($order->fresh());
        $balance2 = (int) User::find($user->id)->bonus_balance;

        $this->assertSame(50, $balance1);
        $this->assertSame(50, $balance2, 'повторный вызов не начисляет второй раз');
        $this->assertSame(1, BonusTransaction::where('order_id', $order->id)
            ->where('type', 'earn')->count());
    }

    public function test_refund_all_is_idempotent(): void
    {
        [$user, $store, $order] = $this->makeOrderWithBonus(
            type: 'spend', percent: 10, requested: 100,
            eligibleInitial: 1000, bonusBalance: 500, initialAmount: 100,
        );

        // Резерв при создании + фактическое списание на сборке (online spend tx).
        $svc = app(BonusService::class);
        $svc->reserveOnCreate($order->fresh());     // hold 100, reserved 100, balance 500
        $svc->applyOnAssembled($order->fresh());    // debit 100 → balance 400
        $this->assertSame(400, (int) User::find($user->id)->bonus_balance);

        // Первый refund возвращает фактически списанные 100.
        $svc->refundAll($order->fresh());
        $balance1 = (int) User::find($user->id)->bonus_balance;

        // Второй — net online spent уже 0 → noop.
        $svc->refundAll($order->fresh());
        $balance2 = (int) User::find($user->id)->bonus_balance;

        $this->assertSame(500, $balance1, '400 + 100 refund');
        $this->assertSame(500, $balance2);
        $this->assertSame(1, BonusTransaction::where('order_id', $order->id)
            ->where('type', 'refund')->count());
        $this->assertSame(1, BonusHold::where('order_id', $order->id)
            ->where('status', BonusHold::STATUS_CONVERTED)->count());
    }

    public function test_repeated_assembly_event_does_not_double_adjust(): void
    {
        [$user, $store, $order] = $this->makeOrderWithBonus(
            type: 'earn', percent: 5, requested: 0,
            eligibleInitial: 1000, bonusBalance: 0, initialAmount: 50,
        );
        $item = $order->items->first();

        $payload = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $order->id,
            'source' => 'delivery',
            'shop_code' => $store->code,
            'status' => 'assembled',
            'items' => [[
                'id' => (string) $item->id,
                'source' => config('rabbitmq-events.source'),
                'product_id' => (string) $item->product_id,
                'product_source' => config('rabbitmq-events.source'),
                'product_name' => $item->title,
                'quantity' => 2,
                'unit_price' => 1000.0,
                'unit_price_effective' => 1000.0,
                'line_total' => 2000.0,
                'item_status' => 'confirmed',
            ]],
        ];

        $transformer = new OrderEventTransformer;
        $transformer->afterUpdate($order, $payload);
        $order->refresh();
        $this->assertSame(100, $order->bonus_earned);

        // Повторный event с теми же данными — должен быть no-op в плане транзакций.
        // (items sync всё равно выполнится, но bonus dela = 0.)
        $txCountBefore = BonusTransaction::where('order_id', $order->id)->count();
        $transformer->afterUpdate($order->fresh(), $payload);
        $txCountAfter = BonusTransaction::where('order_id', $order->id)->count();

        $this->assertSame($txCountBefore, $txCountAfter, 'повторный event не создаёт новых транзакций');
        $order->refresh();
        $this->assertSame(100, $order->bonus_earned, 'состояние стабильно');
    }
}
