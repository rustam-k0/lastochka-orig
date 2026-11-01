<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Order;

use App\Actions\Order\FinalizeClientOrderAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\Order\ClientOrderStatusAggregator;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit/feature tests для {@see \App\Actions\Order\FinalizeClientOrderAction} (P2.6).
 *
 * Action триггерится хуками после терминальных переходов sub-orders (cancel/delivered).
 * Цель — единоразовый consolidated capture/refund когда весь marketplace client-order в terminal state.
 *
 * См. docs/design-analysis/marketplace-model.md §5.1 (M.10=b).
 *
 * Изоляция от P2.1: PaymentService — finalclass без новых методов в main ветке. В тестах
 * мы подменяем FinalizeClientOrderAction на {@see SpyFinalizeClientOrderAction},
 * который перехватывает вызовы consolidated-методов PaymentService через protected hooks.
 */
final class FinalizeClientOrderActionTest extends TestCase
{
    use RefreshDatabase;

    private SpyFinalizeClientOrderAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new SpyFinalizeClientOrderAction(
            new ClientOrderStatusAggregator(),
            $this->app->make(PaymentService::class),
        );
    }

    public function test_no_op_when_client_order_has_no_sub_orders(): void
    {
        $this->action->execute((string) Str::uuid());

        $this->assertSame([], $this->action->calls);
    }

    public function test_captures_delivered_sum_and_refunds_cancelled_diff(): void
    {
        [$user, $store, $clientOrderId] = $this->seedClientOrder();

        Order::factory()->for($user)->for($store)->delivered()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 400,
            'held_amount' => 400.00,
        ]);
        Order::factory()->for($user)->for($store)->delivered()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 300,
            'held_amount' => 300.00,
        ]);
        Order::factory()->for($user)->for($store)->cancelled()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 500,
            'held_amount' => 500.00,
        ]);

        $this->action->execute($clientOrderId);

        $methods = array_column($this->action->calls, 'method');
        $this->assertSame(['captureClientOrder', 'refundClientOrder'], $methods);
        $this->assertSame(700.0, $this->action->calls[0]['amount'], 'capture = 400 + 300');
        $this->assertSame(500.0, $this->action->calls[1]['amount'], 'refund = 1200 hold - 700 captured');
    }

    public function test_cancels_consolidated_hold_when_all_sub_orders_cancelled(): void
    {
        [$user, $store, $clientOrderId] = $this->seedClientOrder();

        Order::factory()->for($user)->for($store)->cancelled()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 400,
            'held_amount' => 400.00,
        ]);
        Order::factory()->for($user)->for($store)->cancelled()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 300,
            'held_amount' => 300.00,
        ]);
        Order::factory()->for($user)->for($store)->cancelled()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 500,
            'held_amount' => 500.00,
        ]);

        $this->action->execute($clientOrderId);

        $this->assertCount(1, $this->action->calls);
        $this->assertSame('cancelConsolidatedHold', $this->action->calls[0]['method']);
        $this->assertSame($clientOrderId, $this->action->calls[0]['clientOrderId']);
        $this->assertArrayNotHasKey('amount', $this->action->calls[0]);

        $this->assertSame(3, Order::query()
            ->where('client_order_id', $clientOrderId)
            ->whereNotNull('client_order_finalized_at')
            ->count());
    }

    public function test_captures_full_amount_and_no_refund_when_all_sub_orders_delivered(): void
    {
        [$user, $store, $clientOrderId] = $this->seedClientOrder();

        Order::factory()->for($user)->for($store)->delivered()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 400,
            'held_amount' => 400.00,
        ]);
        Order::factory()->for($user)->for($store)->delivered()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 300,
            'held_amount' => 300.00,
        ]);
        Order::factory()->for($user)->for($store)->delivered()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 500,
            'held_amount' => 500.00,
        ]);

        $this->action->execute($clientOrderId);

        $this->assertCount(1, $this->action->calls, 'only one call expected: captureClientOrder');
        $this->assertSame('captureClientOrder', $this->action->calls[0]['method']);
        $this->assertSame($clientOrderId, $this->action->calls[0]['clientOrderId']);
        $this->assertSame(1200.0, $this->action->calls[0]['amount']);

        // Все sub-orders помечены finalized
        $finalizedCount = Order::query()
            ->where('client_order_id', $clientOrderId)
            ->whereNotNull('client_order_finalized_at')
            ->count();
        $this->assertSame(3, $finalizedCount);
    }

    public function test_idempotent_on_repeated_execute(): void
    {
        [$user, $store, $clientOrderId] = $this->seedClientOrder();

        Order::factory()->for($user)->for($store)->delivered()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 400,
            'held_amount' => 400.00,
        ]);
        Order::factory()->for($user)->for($store)->cancelled()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 300,
            'held_amount' => 300.00,
        ]);

        $this->action->execute($clientOrderId);
        $firstCallsCount = count($this->action->calls);
        $this->assertGreaterThan(0, $firstCallsCount, 'first run должен сделать payment ops');

        $firstFinalizedAt = Order::query()
            ->where('client_order_id', $clientOrderId)
            ->orderBy('id')
            ->first()
            ->client_order_finalized_at;
        $this->assertNotNull($firstFinalizedAt);

        // Повторный вызов — никаких новых payment ops
        $this->action->execute($clientOrderId);
        $this->assertCount($firstCallsCount, $this->action->calls, 'повторный вызов — no-op по payment ops');

        // client_order_finalized_at не должен меняться
        $secondFinalizedAt = Order::query()
            ->where('client_order_id', $clientOrderId)
            ->orderBy('id')
            ->first()
            ->client_order_finalized_at;
        $this->assertEquals($firstFinalizedAt, $secondFinalizedAt);
    }

    public function test_no_op_when_not_all_sub_orders_terminal(): void
    {
        [$user, $store, $clientOrderId] = $this->seedClientOrder();

        Order::factory()->for($user)->for($store)->delivered()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 400,
            'held_amount' => 400.00,
        ]);
        Order::factory()->for($user)->for($store)->pending()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 500,
            'held_amount' => 500.00,
        ]);

        $this->action->execute($clientOrderId);

        $this->assertSame([], $this->action->calls);

        // Sub-orders не помечены finalized — finalize ещё не срабатывает
        $this->assertSame(0, Order::query()
            ->where('client_order_id', $clientOrderId)
            ->whereNotNull('client_order_finalized_at')
            ->count());
    }

    public function test_marks_finalized_without_payment_ops_on_cash_payment(): void
    {
        [$user, $store, $clientOrderId] = $this->seedClientOrder();

        Order::factory()->for($user)->for($store)->delivered()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Cash,
            'total' => 400,
        ]);
        Order::factory()->for($user)->for($store)->cancelled()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Cash,
            'total' => 300,
        ]);

        $this->action->execute($clientOrderId);

        // Cash — никаких payment ops, но finalized отметка ставится
        $this->assertSame([], $this->action->calls);

        $this->assertSame(2, Order::query()
            ->where('client_order_id', $clientOrderId)
            ->whereNotNull('client_order_finalized_at')
            ->count());
    }

    public function test_cancels_hold_when_delivered_sum_equals_zero_on_refund_boundary(): void
    {
        // Проверяем floor-case: все sub-orders cancelled → cancelHold, а не refund на 0
        // (см. пороговый контроль `$refundAmount > 0.01`).
        [$user, $store, $clientOrderId] = $this->seedClientOrder();

        Order::factory()->for($user)->for($store)->cancelled()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 1000,
            'held_amount' => 1000.00,
        ]);

        $this->action->execute($clientOrderId);

        $this->assertCount(1, $this->action->calls);
        $this->assertSame('cancelConsolidatedHold', $this->action->calls[0]['method']);
    }

    // ================================================================
    //  Helpers
    // ================================================================

    /**
     * @return array{0: User, 1: Store, 2: string}
     */
    private function seedClientOrder(): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $clientOrderId = (string) Str::uuid();

        return [$user, $store, $clientOrderId];
    }
}
