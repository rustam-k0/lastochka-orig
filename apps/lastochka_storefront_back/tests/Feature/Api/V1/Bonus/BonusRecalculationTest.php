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
 * Покрытие бага Platrum 9760: пересчёт бонусов после сборки.
 *
 * НОВАЯ модель: при сборке (assembled) мирорные поля пересчитываются
 * (recalculateMirror) по актуальным items, после чего applyOnAssembled
 * конвертирует резерв в фактическое списание (spend) и начисляет earn.
 * Проверяет все сценарии изменения корзины сборщиком:
 *   - увеличение / уменьшение quantity (earn / spend)
 *   - замена товара (item.status = replaced)
 *   - отмена товара (item.status = cancelled)
 *   - полная отмена заказа после изменений
 *
 * Все проверки идут через реальный поток RabbitMQ event
 * (OrderEventTransformer::afterUpdate), чтобы тест покрывал не только
 * BonusService изолированно, но и его интеграцию в обработчик assembly.
 */
final class BonusRecalculationTest extends TestCase
{
    use RefreshDatabase;

    private function store(int $earn = 5, int $spend = 10): Store
    {
        return Store::factory()->create([
            'code' => 'TEST',
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => $earn,
            'bonus_spend_percent' => $spend,
        ]);
    }

    private function buildOrderWithItem(
        User $user,
        Store $store,
        Product $product,
        int $itemPrice,
        int $itemQuantity,
        string $bonusType,
        int $percent,
        int $requestedAmount,
        int $bonusEarned,
        int $bonusSpent
    ): array {
        $subtotal = $itemPrice * $itemQuantity;
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => $subtotal,
            'discount' => 0,
            'delivery_cost' => 0,
            'total' => $subtotal - $bonusSpent,
            'items_count' => 1,
            'eligible_amount' => $subtotal,
            'bonus_earned' => $bonusEarned,
            'bonus_spent' => $bonusSpent,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => $product->title ?? 'Товар',
            'price' => $itemPrice,
            'quantity' => $itemQuantity,
            'total' => $subtotal,
            'eligible_for_bonus' => true,
        ]);

        OrderBonus::create([
            'order_id' => $order->id,
            'type' => $bonusType,
            'percent' => $percent,
            'eligible_amount' => $subtotal,
            'amount' => $bonusType === 'earn' ? $bonusEarned : $bonusSpent,
            'requested_amount' => $requestedAmount,
            'created_at' => now(),
        ]);

        return [$order->fresh(['bonus', 'items']), $item];
    }

    /**
     * Вспомогательный хелпер: строит payload RabbitMQ event-а сборки с обновлённым quantity.
     */
    private function assemblyEvent(
        Store $store,
        Order $order,
        array $items,
        string $status = 'assembled'
    ): array {
        return [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $order->id,
            'source' => 'delivery',
            'shop_code' => $store->code,
            'status' => $status,
            'items' => $items,
        ];
    }

    // ==================== EARN ====================

    public function test_earn_bonus_grows_when_assembler_increases_quantity(): void
    {
        // Бизнес-сценарий из 9760: клиент заказал 1 кг (1000 ₽), сборщик положил 2 кг.
        // При delivered должно быть начислено 40 бонусов (2% от 2000), а не 20 (2% от 1000).
        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->store(earn: 2);
        $product = Product::factory()->for($store)->create(['price' => 1000, 'price_old' => null]);

        [$order, $item] = $this->buildOrderWithItem(
            user: $user, store: $store, product: $product,
            itemPrice: 1000, itemQuantity: 1,
            bonusType: 'earn', percent: 2, requestedAmount: 0,
            bonusEarned: 20, bonusSpent: 0,
        );

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent($store, $order, [[
            'id' => (string) $item->id,
            'source' => config('rabbitmq-events.source'),
            'product_id' => (string) $product->id,
            'product_source' => config('rabbitmq-events.source'),
            'product_name' => $item->title,
            'quantity' => 2,
            'unit_price' => 1000.0,
            'unit_price_effective' => 1000.0,
            'line_subtotal' => 2000.0,
            'line_total' => 2000.0,
            'item_status' => 'confirmed',
        ]]));

        $order->refresh();
        $this->assertEquals(2000, $order->eligible_amount, 'eligible_amount пересчитан по актуальным items');
        $this->assertSame(40, $order->bonus_earned, 'bonus_earned пересчитан: 2% от 2000');
        $this->assertSame(0, $order->bonus_spent);

        $this->assertDatabaseHas('order_bonuses', [
            'order_id' => $order->id,
            'type' => 'earn',
            'eligible_amount' => 2000,
            'amount' => 40,
            'requested_amount' => 0,
        ]);

        // НОВАЯ модель: earn начисляется по факту сборки (assembled), не при delivered.
        $this->assertSame(40, (int) User::find($user->id)->bonus_balance);
        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'earn',
            'amount' => 40,
        ]);

        // delivered приходит позже → safety-net идемпотентен: повторного начисления нет.
        (new OrderEventTransformer)->afterUpdate($order->fresh(), [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $order->id,
            'source' => 'delivery',
            'shop_code' => $store->code,
            'status' => 'delivered',
            'items' => [],
        ]);

        $this->assertSame(40, (int) User::find($user->id)->bonus_balance);
        $this->assertSame(1, BonusTransaction::where('order_id', $order->id)
            ->where('type', 'earn')->count());
    }

    public function test_earn_bonus_shrinks_when_assembler_decreases_quantity(): void
    {
        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->store(earn: 5);
        $product = Product::factory()->for($store)->create(['price' => 1000, 'price_old' => null]);

        [$order, $item] = $this->buildOrderWithItem(
            user: $user, store: $store, product: $product,
            itemPrice: 1000, itemQuantity: 4,
            bonusType: 'earn', percent: 5, requestedAmount: 0,
            bonusEarned: 200, bonusSpent: 0,
        );

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent($store, $order, [[
            'id' => (string) $item->id,
            'source' => config('rabbitmq-events.source'),
            'product_id' => (string) $product->id,
            'product_source' => config('rabbitmq-events.source'),
            'product_name' => $item->title,
            'quantity' => 2,
            'unit_price' => 1000.0,
            'unit_price_effective' => 1000.0,
            'line_total' => 2000.0,
            'item_status' => 'confirmed',
        ]]));

        $order->refresh();
        $this->assertEquals(2000, $order->eligible_amount);
        $this->assertSame(100, $order->bonus_earned, '5% от 2000');

        // earn начислен по факту сборки.
        $this->assertSame(100, (int) User::find($user->id)->bonus_balance);
    }

    // ==================== SPEND ====================

    public function test_spend_bonus_refunded_when_assembler_decreases_quantity(): void
    {
        // НОВАЯ модель: клиент запросил 200, eligible был 2000 (10% = 200) → зарезервировано 200.
        // Сборщик уменьшил до 1 кг → eligible=1000, max=100. Новый spent=100.
        // На сборке списывается ровно ПЕРЕСЧИТАННОЕ значение (100), а не запрошенное 200.
        $user = User::factory()->create(['bonus_balance' => 500, 'bonus_reserved' => 0]);
        $store = $this->store(spend: 10);
        $product = Product::factory()->for($store)->create(['price' => 1000, 'price_old' => null]);

        [$order, $item] = $this->buildOrderWithItem(
            user: $user, store: $store, product: $product,
            itemPrice: 1000, itemQuantity: 2,
            bonusType: 'spend', percent: 10, requestedAmount: 200,
            bonusEarned: 0, bonusSpent: 200,
        );

        // Резерв при создании: hold 200, reserved 200, баланс не тронут.
        app(BonusService::class)->reserveOnCreate($order->fresh());
        $this->assertSame(500, (int) User::find($user->id)->bonus_balance);
        $this->assertSame(200, (int) User::find($user->id)->bonus_reserved);

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent($store, $order, [[
            'id' => (string) $item->id,
            'source' => config('rabbitmq-events.source'),
            'product_id' => (string) $product->id,
            'product_source' => config('rabbitmq-events.source'),
            'product_name' => $item->title,
            'quantity' => 1,
            'unit_price' => 1000.0,
            'unit_price_effective' => 1000.0,
            'line_total' => 1000.0,
            'item_status' => 'confirmed',
        ]]));

        $order->refresh();
        $this->assertEquals(1000, $order->eligible_amount);
        $this->assertSame(100, $order->bonus_spent, 'min(requested=200, max=100) = 100');

        // На сборке списано фактически 100 (а не 200) → баланс 500 - 100 = 400, резерв освобождён.
        $this->assertSame(400, (int) User::find($user->id)->bonus_balance, '500 - 100 списано на сборке');
        $this->assertSame(0, (int) User::find($user->id)->bonus_reserved);
        $this->assertSame(1, BonusHold::where('order_id', $order->id)
            ->where('status', BonusHold::STATUS_CONVERTED)->count());

        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'spend',
            'source' => 'online',
            'amount' => 100,
        ]);
    }

    public function test_spend_bonus_restored_when_assembler_raises_quantity_back(): void
    {
        // НОВАЯ модель: клиент запросил 200, eligible=1000 (10% = 100) → зарезервировано 100.
        // Сборщик увеличил qty → eligible=3000 (max=300). Новый spent=min(200, 300)=200.
        // На сборке списывается пересчитанные 200 (до запрошенного потолка).
        $user = User::factory()->create(['bonus_balance' => 500, 'bonus_reserved' => 0]);
        $store = $this->store(spend: 10);
        $product = Product::factory()->for($store)->create(['price' => 1000, 'price_old' => null]);

        [$order, $item] = $this->buildOrderWithItem(
            user: $user, store: $store, product: $product,
            itemPrice: 1000, itemQuantity: 1,
            bonusType: 'spend', percent: 10, requestedAmount: 200,
            bonusEarned: 0, bonusSpent: 100,
        );

        // Резерв при создании: hold 100, reserved 100.
        app(BonusService::class)->reserveOnCreate($order->fresh());

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent($store, $order, [[
            'id' => (string) $item->id,
            'source' => config('rabbitmq-events.source'),
            'product_id' => (string) $product->id,
            'product_source' => config('rabbitmq-events.source'),
            'product_name' => $item->title,
            'quantity' => 3,
            'unit_price' => 1000.0,
            'unit_price_effective' => 1000.0,
            'line_total' => 3000.0,
            'item_status' => 'confirmed',
        ]]));

        $order->refresh();
        $this->assertEquals(3000, $order->eligible_amount);
        $this->assertSame(200, $order->bonus_spent, 'min(requested=200, max=300) = 200');

        // На сборке списано фактически 200 → баланс 500 - 200 = 300.
        $this->assertSame(300, (int) User::find($user->id)->bonus_balance, '500 - 200 списано на сборке');
        $this->assertSame(0, (int) User::find($user->id)->bonus_reserved);

        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'spend',
            'source' => 'online',
            'amount' => 200,
            'description' => "Списание за заказ #{$order->id}",
        ]);
    }

    // ==================== REPLACE / CANCEL ITEM ====================

    public function test_eligible_excludes_replaced_items(): void
    {
        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->store(earn: 5);
        $product = Product::factory()->for($store)->create(['price' => 1000, 'price_old' => null]);

        [$order, $item] = $this->buildOrderWithItem(
            user: $user, store: $store, product: $product,
            itemPrice: 1000, itemQuantity: 2,
            bonusType: 'earn', percent: 5, requestedAmount: 0,
            bonusEarned: 100, bonusSpent: 0,
        );

        // Исходный item помечается как replaced, а на его место приходит новый item (added).
        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent($store, $order, [
            [
                'id' => (string) $item->id,
                'source' => config('rabbitmq-events.source'),
                'product_id' => (string) $product->id,
                'product_source' => config('rabbitmq-events.source'),
                'product_name' => $item->title,
                'quantity' => 2000, // в граммах — replaced items передаются в граммах
                'unit_price' => 1000.0,
                'unit_price_effective' => 1000.0,
                'line_total' => 0.0,
                'item_status' => 'replaced',
            ],
            [
                'id' => 'new_item_id',
                'source' => 'delivery',
                'product_id' => (string) $product->id,
                'product_source' => config('rabbitmq-events.source'),
                'product_name' => 'Замена',
                'quantity' => 1,
                'unit_price' => 1500.0,
                'unit_price_effective' => 1500.0,
                'line_total' => 1500.0,
                'item_status' => 'added',
            ],
        ]));

        $order->refresh();
        $this->assertEquals(1500, $order->eligible_amount, 'только активный новый item в eligible');
        $this->assertSame(75, $order->bonus_earned, '5% от 1500');
    }

    public function test_eligible_excludes_cancelled_items(): void
    {
        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->store(earn: 5);
        $product = Product::factory()->for($store)->create(['price' => 1000, 'price_old' => null]);

        [$order, $item] = $this->buildOrderWithItem(
            user: $user, store: $store, product: $product,
            itemPrice: 1000, itemQuantity: 2,
            bonusType: 'earn', percent: 5, requestedAmount: 0,
            bonusEarned: 100, bonusSpent: 0,
        );

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent($store, $order, [[
            'id' => (string) $item->id,
            'source' => config('rabbitmq-events.source'),
            'product_id' => (string) $product->id,
            'product_source' => config('rabbitmq-events.source'),
            'product_name' => $item->title,
            'quantity' => 2,
            'unit_price' => 1000.0,
            'unit_price_effective' => 1000.0,
            'line_total' => 2000.0,
            'item_status' => 'cancelled',
        ]]));

        $order->refresh();
        $this->assertEquals(0, $order->eligible_amount);
        $this->assertSame(0, $order->bonus_earned);
    }

    // ==================== CANCEL ORDER ====================

    public function test_cancel_order_refunds_recalculated_spent(): void
    {
        // НОВАЯ модель: на сборке spent пересчитан c 200 до 100 и фактически списан (online).
        // При полной отмене заказа возвращается актуально списанный остаток (100),
        // а не исходный snapshot 200.
        $user = User::factory()->create(['bonus_balance' => 500, 'bonus_reserved' => 0]);
        $store = $this->store(spend: 10);
        $product = Product::factory()->for($store)->create(['price' => 1000, 'price_old' => null]);

        [$order, $item] = $this->buildOrderWithItem(
            user: $user, store: $store, product: $product,
            itemPrice: 1000, itemQuantity: 2,
            bonusType: 'spend', percent: 10, requestedAmount: 200,
            bonusEarned: 0, bonusSpent: 200,
        );

        // Резерв 200 при создании.
        app(BonusService::class)->reserveOnCreate($order->fresh());

        // Сборка уменьшает до 1 кг → eligible=1000, spent=100, на сборке списано 100.
        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent($store, $order, [[
            'id' => (string) $item->id,
            'source' => config('rabbitmq-events.source'),
            'product_id' => (string) $product->id,
            'product_source' => config('rabbitmq-events.source'),
            'product_name' => $item->title,
            'quantity' => 1,
            'unit_price' => 1000.0,
            'unit_price_effective' => 1000.0,
            'line_total' => 1000.0,
            'item_status' => 'confirmed',
        ]]));

        $this->assertSame(400, (int) User::find($user->id)->bonus_balance, '500 - 100 списано на сборке');

        // Полная отмена через RabbitMQ со status=cancelled.
        (new OrderEventTransformer)->afterUpdate($order->fresh(), [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $order->id,
            'source' => 'delivery',
            'shop_code' => $store->code,
            'status' => 'cancelled',
            'items' => [],
        ]);

        $order->refresh();
        $this->assertSame(0, $order->bonus_spent);
        $this->assertSame(500, (int) User::find($user->id)->bonus_balance, '400 + 100 refund фактически списанного');

        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'refund',
            'source' => 'online',
            'amount' => 100,
        ]);
    }

    public function test_cancel_after_delivered_cancels_earned_credit(): void
    {
        // Сценарий: заказ доставлен (earn начислен), потом отмена — credit отменяется.
        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->store(earn: 5);
        $product = Product::factory()->for($store)->create(['price' => 1000, 'price_old' => null]);

        [$order, $item] = $this->buildOrderWithItem(
            user: $user, store: $store, product: $product,
            itemPrice: 1000, itemQuantity: 2,
            bonusType: 'earn', percent: 5, requestedAmount: 0,
            bonusEarned: 100, bonusSpent: 0,
        );

        // Доставка → earn applied
        $transformer = new OrderEventTransformer;
        $transformer->afterUpdate($order, [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $order->id,
            'source' => 'delivery',
            'shop_code' => $store->code,
            'status' => 'delivered',
            'items' => [],
        ]);

        $this->assertSame(100, (int) User::find($user->id)->bonus_balance);

        // Устанавливаем статус delivered в БД (afterUpdate не сам обновляет Order.status)
        $order->update(['status' => OrderStatus::Delivered]);

        // Отмена после delivered
        $transformer->afterUpdate($order, [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $order->id,
            'source' => 'delivery',
            'shop_code' => $store->code,
            'status' => 'cancelled',
            'items' => [],
        ]);

        $this->assertSame(0, (int) User::find($user->id)->bonus_balance, 'начисление отменено');
        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'cancel',
            'amount' => 100,
        ]);
    }

    // ==================== BonusService (прямые вызовы) ====================

    public function test_recalculate_noop_when_no_order_bonus_snapshot(): void
    {
        // Если заказ создан без бонусной активности (нет OrderBonus) — recalculateMirror ничего не делает.
        $user = User::factory()->create(['bonus_balance' => 100]);
        $store = $this->store();
        $product = Product::factory()->for($store)->create(['price' => 1000, 'price_old' => null]);

        $order = Order::factory()->for($user)->for($store)->create([
            'subtotal' => 1000,
            'bonus_earned' => 0,
            'bonus_spent' => 0,
            'eligible_amount' => 0,
        ]);

        OrderItem::factory()->for($order)->for($product)->create([
            'price' => 1000, 'quantity' => 1, 'total' => 1000,
            'eligible_for_bonus' => true,
        ]);

        app(BonusService::class)->recalculateMirror($order);

        $order->refresh();
        $this->assertEquals(0, $order->eligible_amount);
        $this->assertSame(0, $order->bonus_earned);
        $this->assertSame(0, $order->bonus_spent);
        $this->assertSame(100, (int) User::find($user->id)->bonus_balance);
    }
}
