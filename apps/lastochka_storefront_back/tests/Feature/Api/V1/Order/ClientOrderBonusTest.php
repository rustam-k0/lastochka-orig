<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Actions\Order\CancelOrderAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\BonusTransaction;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderBonus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Bonus\BonusService;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P2.4 — Marketplace bonus aggregation (M.14=a).
 *
 * Покрывает:
 *  - handleDeliveredSubOrder(): aggregated earn когда все sub-orders delivered
 *  - refundBonusesForCancelledOrder skip: внешняя отмена sub-order'а НЕ триггерит refund
 *    в marketplace режиме (ждёт CancelClientOrderAction / P2.6 finalize)
 *  - CancelClientOrderAction: aggregated refund одной транзакцией на client_order
 *  - CancelOrderAction::cancelSubOrder: stock возвращается, bonuses нетронуты
 *  - DELETE /v1/client-orders/{uuid}: aggregated refund + subs cancelled
 *  - Standard mode regression: CancelOrderAction по-прежнему refund'ит бонусы per-order
 *  - Auth + marketplace.mode middleware на DELETE
 */
final class ClientOrderBonusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ==================== Helpers ====================

    private function enableMarketplaceMode(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'marketplace_mode' => ['type' => 'select', 'value' => 'marketplace'],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ],
        );
        app(ModuleConfigService::class)->invalidateCache();
    }

    private function storeWithBonus(int $earnPercent = 5, int $spendPercent = 10): Store
    {
        return Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => $earnPercent,
            'bonus_spend_percent' => $spendPercent,
        ]);
    }

    /**
     * Создаёт sub-order с привязкой к client_order_id + одним OrderItem'ом,
     * чтобы releaseProducts не упал при cancel.
     */
    private function subOrder(
        User $user,
        Store $store,
        string $clientOrderId,
        OrderStatus $status,
        int $bonusEarned = 0,
        int $bonusSpent = 0,
    ): Order {
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 1000,
            'quantity' => 10,
        ]);

        $order = Order::factory()->for($user)->for($store)->create([
            'client_order_id' => $clientOrderId,
            'status' => $status,
            'bonus_earned' => $bonusEarned,
            'bonus_spent' => $bonusSpent,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 1000,
        ]);

        return $order;
    }

    /**
     * Эмулирует пост-сборочное фактическое списание бонусов по заказу
     * (BonusService::applyOnAssembled создаёт такую же spend-транзакцию).
     */
    private function seedSpendTransaction(User $user, Order $order, int $amount): void
    {
        BonusTransaction::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'spend',
            'amount' => $amount,
            'balance_after' => (int) $user->bonus_balance,
            'operation_id' => Str::uuid()->toString(),
            'source' => 'online',
            'description' => "Списание за заказ #{$order->id} (тестовый seed)",
            'created_at' => now(),
        ]);
    }

    private function fireDeliveredEvent(Order $order): void
    {
        $transformer = new OrderEventTransformer;
        $transformer->afterUpdate($order, [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $order->id,
            'source' => 'delivery',
            'shop_code' => $order->store->code,
            'status' => 'delivered',
            'grand_total' => (float) $order->total,
            'items' => [],
        ]);
    }

    private function fireCancelledEvent(Order $order): void
    {
        $transformer = new OrderEventTransformer;
        $transformer->afterUpdate($order, [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $order->id,
            'source' => 'delivery',
            'shop_code' => $order->store->code,
            'status' => 'cancelled',
            'grand_total' => (float) $order->total,
            'items' => [],
        ]);
    }

    // ==================== Aggregated earn ====================

    public function test_aggregated_earn_when_all_sub_orders_delivered(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create(['bonus_balance' => 0]);
        $store1 = $this->storeWithBonus();
        $store2 = $this->storeWithBonus();
        $clientOrderId = (string) Str::uuid();

        $sub1 = $this->subOrder($user, $store1, $clientOrderId, OrderStatus::InDelivery, bonusEarned: 100);
        $sub2 = $this->subOrder($user, $store2, $clientOrderId, OrderStatus::InDelivery, bonusEarned: 150);

        // 1) Первый sub-order становится delivered → aggregator видит что второй ещё in-delivery → no earn
        $sub1->update(['status' => OrderStatus::Delivered]);
        $this->fireDeliveredEvent($sub1->fresh());

        $this->assertDatabaseMissing('bonus_transactions', [
            'client_order_id' => $clientOrderId,
            'type' => 'earn',
        ]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'bonus_balance' => 0]);

        // 2) Второй sub-order тоже delivered → aggregated earn одной транзакцией
        $sub2->update(['status' => OrderStatus::Delivered]);
        $this->fireDeliveredEvent($sub2->fresh());

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 250, // 100 + 150
        ]);

        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => null,
            'client_order_id' => $clientOrderId,
            'type' => 'earn',
            'amount' => 250,
            'balance_after' => 250,
        ]);

        // Ровно одна earn-транзакция для client_order
        $this->assertSame(
            1,
            BonusTransaction::where('client_order_id', $clientOrderId)->where('type', 'earn')->count(),
        );

        // Нет per-order earn транзакций
        $this->assertDatabaseMissing('bonus_transactions', [
            'order_id' => $sub1->id,
            'type' => 'earn',
        ]);
        $this->assertDatabaseMissing('bonus_transactions', [
            'order_id' => $sub2->id,
            'type' => 'earn',
        ]);
    }

    public function test_aggregated_earn_is_idempotent(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->storeWithBonus();
        $clientOrderId = (string) Str::uuid();

        $sub1 = $this->subOrder($user, $store, $clientOrderId, OrderStatus::Delivered, bonusEarned: 200);
        $sub2 = $this->subOrder($user, $store, $clientOrderId, OrderStatus::Delivered, bonusEarned: 100);

        // Первый вызов — создаёт транзакцию
        $this->fireDeliveredEvent($sub1->fresh());
        // Повторный вызов (того же sub-order'а) — не должен дублировать
        $this->fireDeliveredEvent($sub1->fresh());
        // Вызов от второго sub-order'а — тоже не должен дублировать
        $this->fireDeliveredEvent($sub2->fresh());

        $this->assertSame(
            1,
            BonusTransaction::where('client_order_id', $clientOrderId)->where('type', 'earn')->count(),
        );

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 300, // 200 + 100 начислено один раз
        ]);
    }

    public function test_no_earn_when_sub_order_delivered_but_another_still_in_progress(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->storeWithBonus();
        $clientOrderId = (string) Str::uuid();

        $sub1 = $this->subOrder($user, $store, $clientOrderId, OrderStatus::Delivered, bonusEarned: 100);
        $this->subOrder($user, $store, $clientOrderId, OrderStatus::Assembling, bonusEarned: 100);

        $this->fireDeliveredEvent($sub1->fresh());

        $this->assertDatabaseMissing('bonus_transactions', [
            'client_order_id' => $clientOrderId,
            'type' => 'earn',
        ]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'bonus_balance' => 0]);
    }

    public function test_no_earn_when_total_earned_is_zero(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->storeWithBonus();
        $clientOrderId = (string) Str::uuid();

        $sub1 = $this->subOrder($user, $store, $clientOrderId, OrderStatus::Delivered, bonusEarned: 0);
        $sub2 = $this->subOrder($user, $store, $clientOrderId, OrderStatus::Delivered, bonusEarned: 0);

        $this->fireDeliveredEvent($sub1->fresh());
        $this->fireDeliveredEvent($sub2->fresh());

        $this->assertSame(0, BonusTransaction::where('client_order_id', $clientOrderId)->count());
        $this->assertDatabaseHas('users', ['id' => $user->id, 'bonus_balance' => 0]);
    }

    // ==================== External cancel in marketplace ====================

    public function test_external_cancel_of_sub_order_does_not_refund_bonuses(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create(['bonus_balance' => 500]);
        $store = $this->storeWithBonus();
        $clientOrderId = (string) Str::uuid();

        $sub1 = $this->subOrder($user, $store, $clientOrderId, OrderStatus::InDelivery, bonusSpent: 100);
        $this->subOrder($user, $store, $clientOrderId, OrderStatus::InDelivery, bonusSpent: 100);

        // Имитация внешней отмены sub-order'а (например из delivery_back через RabbitMQ).
        // В marketplace режиме handler'ы должны пропустить refund — агрегация произойдёт
        // позже в CancelClientOrderAction или P2.6 FinalizeClientOrderAction.
        $sub1->update(['status' => OrderStatus::Cancelled]);
        $this->fireCancelledEvent($sub1->fresh());

        $this->assertDatabaseHas('users', ['id' => $user->id, 'bonus_balance' => 500]);
        $this->assertSame(0, BonusTransaction::where('client_order_id', $clientOrderId)->count());
        $this->assertDatabaseMissing('bonus_transactions', [
            'order_id' => $sub1->id,
            'type' => 'refund',
        ]);
    }

    public function test_cancel_sub_order_action_does_not_touch_bonuses(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create(['bonus_balance' => 500]);
        $store = $this->storeWithBonus();
        $clientOrderId = (string) Str::uuid();

        $sub1 = $this->subOrder($user, $store, $clientOrderId, OrderStatus::Pending, bonusSpent: 100);
        $this->subOrder($user, $store, $clientOrderId, OrderStatus::Pending, bonusSpent: 100);

        // Ручной вызов CancelOrderAction на одном sub-order — cancelSubOrder path
        app(CancelOrderAction::class)->execute($sub1);

        $sub1->refresh();
        $this->assertSame(OrderStatus::Cancelled, $sub1->status);

        // Бонусы НЕ возвращены — ждём либо DELETE /v1/client-orders либо P2.6 finalize
        $this->assertDatabaseHas('users', ['id' => $user->id, 'bonus_balance' => 500]);
        $this->assertSame(0, BonusTransaction::where('client_order_id', $clientOrderId)->count());
        $this->assertDatabaseMissing('bonus_transactions', [
            'order_id' => $sub1->id,
            'type' => 'refund',
        ]);
    }

    // ==================== CancelClientOrderAction / DELETE endpoint ====================

    public function test_delete_client_order_cancels_all_sub_orders_and_refunds_aggregated(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create(['bonus_balance' => 300]); // уже было списано 200
        $store1 = $this->storeWithBonus();
        $store2 = $this->storeWithBonus();
        $clientOrderId = (string) Str::uuid();

        $sub1 = $this->subOrder($user, $store1, $clientOrderId, OrderStatus::Pending, bonusSpent: 120);
        $sub2 = $this->subOrder($user, $store2, $clientOrderId, OrderStatus::Pending, bonusSpent: 80);
        // «Уже было списано» = существуют РЕАЛЬНЫЕ spend-транзакции (пост-сборочное
        // списание). Refund при отмене считается по ним, а не по зеркальному
        // orders.bonus_spent (до сборки то поле означает лишь резерв).
        $this->seedSpendTransaction($user, $sub1, 120);
        $this->seedSpendTransaction($user, $sub2, 80);

        $response = $this->actingAs($user)->deleteJson("/api/v1/client-orders/{$clientOrderId}");

        $response->assertOk()->assertJson([
            'status' => 'cancelled',
            'clientOrderId' => $clientOrderId,
        ]);

        // Оба sub-order'а отменены
        $this->assertSame(OrderStatus::Cancelled->value, $sub1->fresh()->status->value);
        $this->assertSame(OrderStatus::Cancelled->value, $sub2->fresh()->status->value);

        // Баланс вырос на суммарные 200 (120 + 80)
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 500, // 300 + 200
        ]);

        // Одна агрегированная refund-транзакция на client_order
        $this->assertSame(
            1,
            BonusTransaction::where('client_order_id', $clientOrderId)->where('type', 'refund')->count(),
        );
        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => null,
            'client_order_id' => $clientOrderId,
            'type' => 'refund',
            'amount' => 200,
            'balance_after' => 500,
        ]);

        // Никаких per-order refund транзакций
        $this->assertDatabaseMissing('bonus_transactions', [
            'order_id' => $sub1->id,
            'type' => 'refund',
        ]);
        $this->assertDatabaseMissing('bonus_transactions', [
            'order_id' => $sub2->id,
            'type' => 'refund',
        ]);
    }

    public function test_delete_client_order_is_idempotent(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create(['bonus_balance' => 300]);
        $store = $this->storeWithBonus();
        $clientOrderId = (string) Str::uuid();

        $subA = $this->subOrder($user, $store, $clientOrderId, OrderStatus::Pending, bonusSpent: 120);
        $subB = $this->subOrder($user, $store, $clientOrderId, OrderStatus::Pending, bonusSpent: 80);
        $this->seedSpendTransaction($user, $subA, 120);
        $this->seedSpendTransaction($user, $subB, 80);

        $this->actingAs($user)->deleteJson("/api/v1/client-orders/{$clientOrderId}")->assertOk();
        $this->actingAs($user)->deleteJson("/api/v1/client-orders/{$clientOrderId}")->assertOk();

        // Баланс не увеличился дважды
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 500,
        ]);

        // Ровно одна refund-транзакция
        $this->assertSame(
            1,
            BonusTransaction::where('client_order_id', $clientOrderId)->where('type', 'refund')->count(),
        );
    }

    public function test_delete_client_order_with_no_spent_bonuses(): void
    {
        $this->enableMarketplaceMode();

        $user = User::factory()->create(['bonus_balance' => 100]);
        $store = $this->storeWithBonus();
        $clientOrderId = (string) Str::uuid();

        $sub1 = $this->subOrder($user, $store, $clientOrderId, OrderStatus::Pending, bonusSpent: 0);

        $this->actingAs($user)->deleteJson("/api/v1/client-orders/{$clientOrderId}")->assertOk();

        $this->assertSame(OrderStatus::Cancelled->value, $sub1->fresh()->status->value);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'bonus_balance' => 100]);
        $this->assertSame(0, BonusTransaction::where('client_order_id', $clientOrderId)->count());
    }

    public function test_delete_client_order_requires_auth(): void
    {
        $this->enableMarketplaceMode();

        $this->deleteJson('/api/v1/client-orders/00000000-0000-0000-0000-000000000000')
            ->assertUnauthorized();
    }

    public function test_delete_client_order_returns_404_in_standard_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/api/v1/client-orders/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    public function test_delete_client_order_returns_404_for_unknown_id(): void
    {
        $this->enableMarketplaceMode();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/api/v1/client-orders/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    public function test_delete_client_order_does_not_affect_other_users_orders(): void
    {
        $this->enableMarketplaceMode();

        $owner = User::factory()->create(['bonus_balance' => 100]);
        $stranger = User::factory()->create(['bonus_balance' => 100]);
        $store = $this->storeWithBonus();
        $clientOrderId = (string) Str::uuid();

        $this->subOrder($owner, $store, $clientOrderId, OrderStatus::Pending, bonusSpent: 50);

        $this->actingAs($stranger)
            ->deleteJson("/api/v1/client-orders/{$clientOrderId}")
            ->assertNotFound();

        // Заказ владельца не затронут
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'bonus_balance' => 100]);
        $this->assertSame(0, BonusTransaction::where('client_order_id', $clientOrderId)->count());
    }

    // ==================== Standard mode regression ====================

    public function test_standard_mode_cancel_still_refunds_per_order(): void
    {
        // В standard режиме (marketplace не включён) CancelOrderAction по-прежнему
        // per-order refund. НОВАЯ модель: refund опирается на фактическое списание
        // на сборке (online spend tx), а не на orders.bonus_spent snapshot.
        $user = User::factory()->create(['bonus_balance' => 500, 'bonus_reserved' => 0]);
        $store = $this->storeWithBonus(spendPercent: 20);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 1000, 'price_old' => null, 'quantity' => 5,
        ]);

        $order = Order::factory()->pending()->for($user)->for($store)->create([
            'subtotal' => 1000,
            'eligible_amount' => 1000,
            'bonus_earned' => 0,
            'bonus_spent' => 200,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id, 'quantity' => 1, 'price' => 1000,
            'total' => 1000, 'eligible_for_bonus' => true,
        ]);
        OrderBonus::create([
            'order_id' => $order->id, 'type' => 'spend', 'percent' => 20,
            'eligible_amount' => 1000, 'amount' => 200, 'requested_amount' => 200, 'created_at' => now(),
        ]);

        // Резерв при создании + фактическое списание на сборке.
        $svc = app(BonusService::class);
        $svc->reserveOnCreate($order->fresh());   // hold 200, reserved 200, balance 500
        $svc->applyOnAssembled($order->fresh());  // debit 200 → balance 300

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/cancel");
        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 500, // 300 + 200 refund
        ]);
        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'refund',
            'amount' => 200,
        ]);
    }

    public function test_standard_mode_delivered_still_credits_per_order(): void
    {
        // В standard режиме handleDeliveredStandard должен срабатывать как раньше.
        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->storeWithBonus();
        $order = Order::factory()->for($user)->for($store)->create([
            'bonus_earned' => 250,
            'bonus_spent' => 0,
            'status' => OrderStatus::InDelivery,
        ]);

        $this->fireDeliveredEvent($order);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'bonus_balance' => 250]);
        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'earn',
            'amount' => 250,
        ]);
    }
}
