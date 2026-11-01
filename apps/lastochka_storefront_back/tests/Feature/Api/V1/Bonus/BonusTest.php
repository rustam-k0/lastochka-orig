<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Bonus;

use App\Enums\OrderStatus;
use App\Enums\PromocodeType;
use App\Models\Address;
use App\Models\BonusHold;
use App\Models\BonusTransaction;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartPromocode;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderBonus;
use App\Models\OrderItem;
use App\Models\Promocode;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Bonus\BonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BonusTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Helpers ====================

    /**
     * Создать магазин с включёнными бонусами.
     */
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
     * Наполнить корзину одним товаром без скидки.
     */
    private function fillCart(User $user, Store $store, int $price = 1000, int $qty = 1): Product
    {
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => $price,
            'price_old' => null,
            'quantity' => 50,
        ]);
        $cart = Cart::firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => $qty,
            'price' => $price,
        ]);

        return $product;
    }

    /**
     * Сэмулировать фактическое списание баллов на сборке (online spend tx).
     * В новой модели refund при отмене опирается на наличие такой транзакции,
     * а не на orders.bonus_spent. balance_after = текущий (пост-списание) баланс.
     */
    private function seedOnlineSpend(User $user, Order $order, int $amount): void
    {
        BonusTransaction::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'spend',
            'amount' => $amount,
            'balance_after' => (int) $user->bonus_balance,
            'operation_id' => (string) Str::uuid(),
            'source' => 'online',
            'description' => "Списание за заказ #{$order->id}",
            'created_at' => now(),
        ]);
    }

    // ==================== Cart ====================

    public function testCartShowsBonusInfo(): void
    {
        $user = User::factory()->create(['bonus_balance' => 500]);
        $store = $this->storeWithBonus(earnPercent: 5, spendPercent: 10);
        $this->fillCart($user, $store, price: 2000);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $bonus = $response->json('storeGroups.0.bonus');

        $this->assertNotNull($bonus);
        $this->assertTrue($bonus['isEnabled']);
        $this->assertEquals(2000, $bonus['eligibleAmount']);
        $this->assertEquals(5, $bonus['earnPercent']);
        $this->assertEquals(10, $bonus['spendPercent']);
        $this->assertEquals(100, $bonus['maxEarn']);   // floor(2000 * 5 / 100)
        $this->assertEquals(200, $bonus['maxSpend']);  // min(floor(2000 * 10 / 100), 500)
        $this->assertEquals(500, $bonus['userBalance']);
    }

    public function testCartBonusUnavailableWithPromocode(): void
    {
        $user = User::factory()->create(['bonus_balance' => 1000]);
        $store = $this->storeWithBonus();
        $this->fillCart($user, $store, price: 2000);

        $promocode = Promocode::factory()->create([
            'code' => 'PROMO10',
            'type' => PromocodeType::Percent,
            'value' => 10,
            'store_id' => null,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $cart = Cart::where('user_id', $user->id)->first();
        CartPromocode::create([
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $bonus = $response->json('storeGroups.0.bonus');

        $this->assertNotNull($bonus);
        $this->assertEquals(0, $bonus['eligibleAmount']);
        $this->assertEquals(0, $bonus['maxEarn']);
        $this->assertEquals(0, $bonus['maxSpend']);
    }

    public function test_cart_and_history_exclude_reserved_bonuses(): void
    {
        $user = User::factory()->create(['bonus_balance' => 500, 'bonus_reserved' => 450]);
        $store = $this->storeWithBonus(earnPercent: 5, spendPercent: 10);
        $this->fillCart($user, $store, price: 2000);

        $this->actingAs($user)->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('storeGroups.0.bonus.userBalance', 50)
            ->assertJsonPath('storeGroups.0.bonus.maxSpend', 50);
        $this->getJson('/api/v1/bonuses')->assertOk()->assertJsonPath('balance', 50);

        $user->update(['bonus_reserved' => 0]);
        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('storeGroups.0.bonus.userBalance', 500)
            ->assertJsonPath('storeGroups.0.bonus.maxSpend', 200);
    }

    public function testCartBonusUnavailableAllDiscountedItems(): void
    {
        $user = User::factory()->create(['bonus_balance' => 1000]);
        $store = $this->storeWithBonus();

        // Товар со скидкой (price_old установлена)
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 800,
            'price_old' => 1000,
            'quantity' => 10,
        ]);
        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 800,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $bonus = $response->json('storeGroups.0.bonus');

        $this->assertNotNull($bonus);
        $this->assertEquals(0, $bonus['eligibleAmount']);
        $this->assertEquals(0, $bonus['maxEarn']);
        $this->assertEquals(0, $bonus['maxSpend']);
    }

    // ==================== Create Order ====================

    public function testCreateOrderWithBonusEarn(): void
    {
        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->storeWithBonus(earnPercent: 5, spendPercent: 10);
        $address = Address::factory()->active()->for($user)->create();
        $this->fillCart($user, $store, price: 1000);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'bonusAction' => 'earn',
        ]);

        $response->assertCreated();
        $orderId = $response->json('orderId');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'bonus_earned' => 50,  // floor(1000 * 5 / 100)
            'bonus_spent' => 0,
        ]);

        $this->assertDatabaseHas('order_bonuses', [
            'order_id' => $orderId,
            'type' => 'earn',
            'percent' => 5,
            'eligible_amount' => 1000,
            'amount' => 50,
        ]);

        // Баланс не изменился — начисление только при delivered
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 0,
        ]);
    }

    public function testCreateOrderWithBonusSpend(): void
    {
        $user = User::factory()->create(['bonus_balance' => 500]);
        $store = $this->storeWithBonus(earnPercent: 5, spendPercent: 20);
        $address = Address::factory()->active()->for($user)->create();
        $this->fillCart($user, $store, price: 1000);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 200,
        ]);

        $response->assertCreated();
        $orderId = $response->json('orderId');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'bonus_earned' => 0,
            'bonus_spent' => 200,
        ]);

        $this->assertDatabaseHas('order_bonuses', [
            'order_id' => $orderId,
            'type' => 'spend',
            'percent' => 20,
            'eligible_amount' => 1000,
            'amount' => 200,
        ]);
    }

    public function testBonusSpendReducesTotal(): void
    {
        $user = User::factory()->create(['bonus_balance' => 500]);
        $store = $this->storeWithBonus(earnPercent: 5, spendPercent: 30);
        $address = Address::factory()->active()->for($user)->create();
        $this->fillCart($user, $store, price: 1000);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 150,
        ]);

        $response->assertCreated();
        $orderId = $response->json('orderId');

        // total = subtotal(1000) - discount(0) - bonus_spent(150) + delivery(200) = 1050
        // FakeDeliveryClient возвращает deliveryPrice=200
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'subtotal' => 1000,
            'discount' => 0,
            'bonus_spent' => 150,
            'delivery_cost' => 200,
            'total' => 1050,
        ]);
    }

    public function testBonusSpendDeductsBalance(): void
    {
        // НОВАЯ модель: при создании заказа баллы РЕЗЕРВИРУЮТСЯ (hold + bonus_reserved),
        // фактическое списание и spend-транзакция появляются только при сборке (assembled).
        $user = User::factory()->create(['bonus_balance' => 500]);
        $store = $this->storeWithBonus(earnPercent: 5, spendPercent: 30);
        $address = Address::factory()->active()->for($user)->create();
        $this->fillCart($user, $store, price: 1000);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 200,
        ])->assertCreated();
        $orderId = $response->json('orderId');

        // После создания: баланс НЕ списан, баллы зарезервированы (hold + bonus_reserved).
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 500, // не изменился
            'bonus_reserved' => 200, // зарезервировано
        ]);
        $this->assertDatabaseHas('bonus_holds', [
            'order_id' => $orderId,
            'status' => BonusHold::STATUS_HELD,
            'amount' => 200,
        ]);
        // spend-транзакции при создании НЕТ
        $this->assertDatabaseMissing('bonus_transactions', [
            'order_id' => $orderId,
            'type' => 'spend',
        ]);

        // Сборка (assembled) → резерв конвертируется в фактическое списание.
        app(BonusService::class)->applyOnAssembled(Order::find($orderId));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 300, // 500 - 200
            'bonus_reserved' => 0,  // резерв освобождён
        ]);
        $this->assertDatabaseHas('bonus_holds', [
            'order_id' => $orderId,
            'status' => BonusHold::STATUS_CONVERTED,
        ]);
        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => $orderId,
            'type' => 'spend',
            'source' => 'online',
            'amount' => 200,
            'balance_after' => 300,
        ]);
    }

    // ==================== Delivered / Earn ====================

    public function testBonusEarnAfterDelivery(): void
    {
        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->storeWithBonus();
        $order = Order::factory()->for($user)->for($store)->create([
            'bonus_earned' => 250,
            'bonus_spent' => 0,
            'status' => OrderStatus::InDelivery,
        ]);

        // Имитируем входящее RabbitMQ-событие со статусом delivered (реальный путь начисления бонусов)
        $transformer = new OrderEventTransformer;
        $transformer->afterUpdate($order, [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $order->id,
            'source' => 'delivery',
            'shop_code' => $store->code,
            'status' => 'delivered',
            'grand_total' => (float) $order->total,
            'items' => [],
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 250,
        ]);

        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'earn',
            'amount' => 250,
            'balance_after' => 250,
        ]);
    }

    public function testBonusEarnNotBeforeDelivery(): void
    {
        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->storeWithBonus();
        $order = Order::factory()->for($user)->for($store)->create([
            'bonus_earned' => 250,
            'bonus_spent' => 0,
            'status' => OrderStatus::Pending,
        ]);

        // Смена статуса на assembling — бонусы не начисляются
        $order->update(['status' => OrderStatus::Assembling]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'bonus_balance' => 0]);
        $this->assertDatabaseMissing('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
        ]);
    }

    // ==================== Cancel / Refund ====================

    public function testBonusRefundOnCancel(): void
    {
        // НОВАЯ модель: списание происходит на сборке (online spend tx). Отмена после сборки
        // возвращает ровно фактически списанную сумму. Предусловие — заказ прошёл assembled.
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

        // Резерв при создании + фактическое списание при сборке.
        $svc = app(BonusService::class);
        $svc->reserveOnCreate($order->fresh());   // hold 200, reserved 200, balance 500
        $svc->applyOnAssembled($order->fresh());  // debit 200 → balance 300, online spend tx
        $this->assertSame(300, (int) User::find($user->id)->bonus_balance);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/cancel");

        $response->assertOk();

        // Баланс возвращён: 300 + 200 refund
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 500,
        ]);

        // Транзакция refund создана
        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'refund',
            'amount' => 200,
            'balance_after' => 500,
        ]);
    }

    // ==================== Validations ====================

    public function testCannotSpendMoreThanBalance(): void
    {
        $user = User::factory()->create(['bonus_balance' => 100]);
        $store = $this->storeWithBonus(earnPercent: 5, spendPercent: 50);
        $address = Address::factory()->active()->for($user)->create();
        $this->fillCart($user, $store, price: 2000);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 500, // больше баланса (100)
        ]);

        // Либо 422 от валидации, либо сервер ограничит до баланса
        // По ТЗ: bonusSpendAmount > user.balance → 422
        $response->assertUnprocessable();
    }

    public function testCannotSpendMoreThanMaxPercent(): void
    {
        $user = User::factory()->create(['bonus_balance' => 5000]);
        $store = $this->storeWithBonus(earnPercent: 5, spendPercent: 10); // max 10% от eligible
        $address = Address::factory()->active()->for($user)->create();
        $this->fillCart($user, $store, price: 1000); // eligible=1000, maxSpend=100

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 500, // больше maxSpend (100)
        ]);

        $response->assertCreated();
        $orderId = $response->json('orderId');

        // Фактически списано не более maxSpend=100
        $order = Order::find($orderId);
        $this->assertLessThanOrEqual(100, $order->bonus_spent);
        $this->assertGreaterThan(0, $order->bonus_spent);
    }

    public function testCannotEarnAndSpendSimultaneously(): void
    {
        $user = User::factory()->create(['bonus_balance' => 500]);
        $store = $this->storeWithBonus();
        $address = Address::factory()->active()->for($user)->create();
        $this->fillCart($user, $store, price: 1000);

        // Заказ с bonusAction=earn — bonus_spent должен быть 0
        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'bonusAction' => 'earn',
        ]);

        $response->assertCreated();
        $orderId = $response->json('orderId');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'bonus_spent' => 0,
        ]);

        // Баланс не трогался
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 500,
        ]);
    }

    public function testBonusDisabledForStore(): void
    {
        $user = User::factory()->create(['bonus_balance' => 500]);
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
            'is_bonus_enabled' => false,
            'bonus_earn_percent' => 10,
            'bonus_spend_percent' => 10,
        ]);
        $address = Address::factory()->active()->for($user)->create();
        $this->fillCart($user, $store, price: 1000);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'bonusAction' => 'earn',
        ]);

        $response->assertCreated();
        $orderId = $response->json('orderId');

        // Бонусы не начислены и не списаны
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'bonus_earned' => 0,
            'bonus_spent' => 0,
        ]);

        // Снапшот бонуса не создан
        $this->assertDatabaseMissing('order_bonuses', ['order_id' => $orderId]);

        // Корзина не показывает бонус
        $cart = $this->actingAs($user)->getJson('/api/v1/cart');
        // Корзина пуста после заказа, но в целом проверяем store.is_bonus_enabled=false
        // Создадим ещё один товар в корзину для проверки
        $this->fillCart($user, $store, price: 500);
        $cartResp = $this->actingAs($user)->getJson('/api/v1/cart');
        $cartResp->assertOk();
        $this->assertNull($cartResp->json('storeGroups.0.bonus'));
    }

    // ==================== Idempotency ====================

    public function testBonusIdempotency(): void
    {
        $user = User::factory()->create(['bonus_balance' => 500]);
        $store = $this->storeWithBonus(earnPercent: 5, spendPercent: 50);
        $address = Address::factory()->active()->for($user)->create();
        $idempotencyKey = 'test-idem-key-'.uniqid();

        // Первый запрос
        $this->fillCart($user, $store, price: 1000);
        $response1 = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'idempotencyKey' => $idempotencyKey,
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 100,
        ]);
        $response1->assertCreated();
        $orderId = $response1->json('orderId');

        $balanceAfterFirst = User::find($user->id)->bonus_balance;

        // Второй запрос с тем же ключом (корзина уже пуста — идемпотентность по ключу)
        $response2 = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'idempotencyKey' => $idempotencyKey,
            'bonusAction' => 'spend',
            'bonusSpendAmount' => 100,
        ]);
        $response2->assertCreated();

        // Возвращается тот же заказ
        $this->assertEquals($orderId, $response2->json('orderId'));

        // Баланс не изменился второй раз (в новой модели при создании баланс вообще не трогается)
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => $balanceAfterFirst,
            'bonus_reserved' => 100, // зарезервировано ровно один раз
        ]);

        // Резерв (hold) создан ровно один раз, фактического списания при создании нет
        $this->assertEquals(1, BonusHold::where('order_id', $orderId)
            ->where('status', BonusHold::STATUS_HELD)
            ->count());
        $this->assertDatabaseMissing('bonus_transactions', [
            'order_id' => $orderId,
            'type' => 'spend',
        ]);
    }

    // ==================== Bonus History ====================

    public function testBonusHistory(): void
    {
        $user = User::factory()->create(['bonus_balance' => 1200]);
        $store = Store::factory()->create();

        $order1 = Order::factory()->for($user)->for($store)->create();
        $order2 = Order::factory()->for($user)->for($store)->create();

        BonusTransaction::create([
            'user_id' => $user->id,
            'order_id' => $order1->id,
            'type' => 'earn',
            'amount' => 250,
            'balance_after' => 1200,
            'description' => "Начисление за заказ #{$order1->id}",
            'created_at' => now(),
        ]);
        BonusTransaction::create([
            'user_id' => $user->id,
            'order_id' => $order2->id,
            'type' => 'spend',
            'amount' => 500,
            'balance_after' => 950,
            'description' => "Списание за заказ #{$order2->id}",
            'created_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/bonuses');

        $response->assertOk()
            ->assertJsonStructure([
                'balance',
                'hasMore',
                'data' => [
                    '*' => ['id', 'type', 'amount', 'balanceAfter', 'description', 'orderId', 'createdAt'],
                ],
                'total',
            ]);

        $this->assertEquals(1200, $response->json('balance'));
        $this->assertEquals(2, $response->json('total'));

        // Сортировка по убыванию created_at
        $types = array_column($response->json('data'), 'type');
        $this->assertEquals('earn', $types[0]);
        $this->assertEquals('spend', $types[1]);
    }

    public function testBonusHistoryRequiresAuth(): void
    {
        $response = $this->getJson('/api/v1/bonuses');

        $response->assertUnauthorized();
    }

    // ==================== Weighted Products ====================

    public function testCartBonusCorrectForWeightedProduct(): void
    {
        $user = User::factory()->create(['bonus_balance' => 5000]);
        $store = $this->storeWithBonus(earnPercent: 10, spendPercent: 20);

        // Весовой товар: step=500г, price=200 за порцию
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 200,
            'price_old' => null,
            'quantity' => 50,
            'quantity_step' => 500,
            'measurement_unit' => 'gram',
        ]);

        // quantity=1500 (граммы) → portions=3 → стоимость=200*3=600
        $cart = Cart::firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1500,
            'price' => 200,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/cart');

        $response->assertOk();
        $bonus = $response->json('storeGroups.0.bonus');

        // eligible = 200 * 3 порции = 600, НЕ 200 * 1500 = 300000
        $this->assertEquals(600, $bonus['eligibleAmount']);
        $this->assertEquals(60, $bonus['maxEarn']);   // floor(600 * 10 / 100)
        $this->assertEquals(120, $bonus['maxSpend']); // min(floor(600 * 20 / 100), 5000)
    }

    public function testCreateOrderWithBonusEarnWeightedProduct(): void
    {
        $user = User::factory()->create(['bonus_balance' => 0]);
        $store = $this->storeWithBonus(earnPercent: 10, spendPercent: 20);
        $address = Address::factory()->active()->for($user)->create();

        // Весовой товар: step=500г, price=200 за порцию
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 200,
            'price_old' => null,
            'quantity' => 10000,
            'quantity_step' => 500,
            'measurement_unit' => 'gram',
        ]);

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1500, // 3 порции по 500г
            'price' => 200,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'bonusAction' => 'earn',
        ]);

        $response->assertCreated();
        $orderId = $response->json('orderId');

        // earned = floor(600 * 10 / 100) = 60, НЕ floor(300000 * 10 / 100)
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'bonus_earned' => 60,
            'bonus_spent' => 0,
        ]);

        $this->assertDatabaseHas('order_bonuses', [
            'order_id' => $orderId,
            'type' => 'earn',
            'eligible_amount' => 600,
            'amount' => 60,
        ]);
    }

    // ==================== External Cancel (RabbitMQ) ====================

    public function testBonusRefundOnExternalCancel(): void
    {
        $user = User::factory()->create(['bonus_balance' => 500]);
        $store = $this->storeWithBonus();
        $order = Order::factory()->for($user)->for($store)->create([
            'bonus_earned' => 0,
            'bonus_spent' => 200,
            'status' => OrderStatus::InDelivery,
        ]);

        // НОВАЯ модель: к моменту доставки баллы уже были фактически списаны на сборке
        // (online spend tx). Внешняя отмена возвращает ровно списанное.
        $this->seedOnlineSpend($user, $order, 200);

        // Имитация RabbitMQ события cancelled от delivery_back
        $transformer = new OrderEventTransformer;
        $transformer->afterUpdate($order, [
            'event_id' => \Illuminate\Support\Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $order->id,
            'source' => 'delivery',
            'shop_code' => $store->code,
            'status' => 'cancelled',
            'grand_total' => (float) $order->total,
            'items' => [],
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 700, // 500 + 200
        ]);

        $this->assertDatabaseHas('bonus_transactions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'type' => 'refund',
            'amount' => 200,
        ]);
    }

    public function testBonusRefundOnExternalCancelIdempotent(): void
    {
        $user = User::factory()->create(['bonus_balance' => 500]);
        $store = $this->storeWithBonus();
        $order = Order::factory()->for($user)->for($store)->create([
            'bonus_earned' => 0,
            'bonus_spent' => 200,
            'status' => OrderStatus::InDelivery,
        ]);

        // НОВАЯ модель: фактическое списание уже произошло на сборке (online spend tx).
        $this->seedOnlineSpend($user, $order, 200);

        $eventData = [
            'event_id' => \Illuminate\Support\Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $order->id,
            'source' => 'delivery',
            'shop_code' => $store->code,
            'status' => 'cancelled',
            'grand_total' => (float) $order->total,
            'items' => [],
        ];

        $transformer = new OrderEventTransformer;
        $transformer->afterUpdate($order, $eventData);
        $transformer->afterUpdate($order, $eventData); // повторный вызов

        // Только одна транзакция refund
        $this->assertEquals(1, BonusTransaction::where('order_id', $order->id)
            ->where('type', 'refund')
            ->count());

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 700,
        ]);
    }

    public function testNoBonusRefundWhenNoBonusSpent(): void
    {
        $user = User::factory()->create(['bonus_balance' => 500]);
        $store = $this->storeWithBonus();
        $order = Order::factory()->for($user)->for($store)->create([
            'bonus_earned' => 50,
            'bonus_spent' => 0,
            'status' => OrderStatus::InDelivery,
        ]);

        $transformer = new OrderEventTransformer;
        $transformer->afterUpdate($order, [
            'event_id' => \Illuminate\Support\Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $order->id,
            'source' => 'delivery',
            'shop_code' => $store->code,
            'status' => 'cancelled',
            'grand_total' => (float) $order->total,
            'items' => [],
        ]);

        // Баланс не изменился — бонусы не были начислены (нет earn транзакции)
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'bonus_balance' => 500,
        ]);

        $this->assertDatabaseMissing('bonus_transactions', [
            'order_id' => $order->id,
        ]);
    }

    // ==================== Profile ====================

    public function testBonusBalanceInProfile(): void
    {
        $user = User::factory()->create(['bonus_balance' => 1200]);

        $response = $this->actingAs($user)->getJson('/api/v1/auth/profile');

        $response->assertOk()
            ->assertJsonPath('data.bonusBalance', 1200);
    }
}
