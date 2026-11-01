<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Enums\PromocodeType;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartPromocode;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Create Order ====================

    public function test_create_order(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'min_order_amount' => 500,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 10,
            // Остаток на карточке витрина списывает только в режиме fixed:
            // в normal им управляет 1С, в ignore он не учитывается вовсе
            // (ReserveProductsAction → CartStoreOffers::writesStockLocally).
            'stock_mode' => 'fixed',
        ]);
        $address = Address::factory()->active()->for($user)->create();

        // Создаём корзину с товаром
        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 300,
        ]);

        $deliverySlotId = $this->getValidDeliverySlotId();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $deliverySlotId,
            'comment' => 'Тестовый комментарий',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['orderId']);

        $orderId = $response->json('orderId');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
            'store_id' => $store->id,
            'status' => 'pending',
            'subtotal' => 600,
            // Доставку витрина берёт не из store.delivery_price, а из ответа
            // слотов (FakeDeliveryClient отдаёт 200), и она входит в total.
            'total' => 800,
            'items_count' => 1,
        ]);

        // Проверяем snapshot адреса
        $this->assertDatabaseHas('order_addresses', [
            'order_id' => $orderId,
            'city' => $address->city,
        ]);

        // Проверяем snapshot товаров
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 300,
            'total' => 600,
        ]);

        // Проверяем, что корзина очищена для этого магазина
        $this->assertDatabaseMissing('cart_items', [
            'cart_id' => $cart->id,
            'store_id' => $store->id,
        ]);

        // Проверяем, что товар зарезервирован
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'quantity' => 8, // 10 - 2
        ]);
    }

    public function test_create_order_empty_cart(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertUnprocessable();
    }

    public function test_create_order_min_amount(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['min_order_amount' => 1000]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.subtotal.0', 'Минимальная сумма заказа: 1000 руб. Текущая сумма: 100 руб.');
    }

    public function test_create_order_min_amount_with_fractional_subtotal(): void
    {
        // Регрессия LASTOCHKI-JAMI-11200: после перехода на «деньги с копейками»
        // calculateSubtotal возвращает float — заказ ниже минимума должен отдавать
        // 422 MinOrderAmountException, а не 500 TypeError (int $currentAmount).
        $user = User::factory()->create();
        $store = Store::factory()->create(['min_order_amount' => 1000]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100.50,
            'quantity' => 10,
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 100.50,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.subtotal.0', 'Минимальная сумма заказа: 1000 руб. Текущая сумма: 100.5 руб.');
    }

    public function test_create_order_with_promocode(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 1000,
            'price_old' => null,
            'quantity' => 10,
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $promocode = Promocode::factory()->create([
            'code' => 'TEST10',
            'type' => PromocodeType::Percent,
            'value' => 10,
            'store_id' => null,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 1000,
        ]);
        CartPromocode::create([
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertCreated();

        $orderId = $response->json('orderId');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'subtotal' => 1000,
            'discount' => 100, // 10% от 1000
            // total = subtotal - discount + delivery_cost; FakeDeliveryClient
            // отдаёт deliveryPrice=200 при subtotal < freeDeliveryFrom(2000).
            'total' => 1100,
        ]);

        $this->assertDatabaseHas('order_promocodes', [
            'order_id' => $orderId,
            'code' => 'TEST10',
            'type' => 'percent',
            'value' => 10,
            'discount_amount' => 100,
        ]);
    }

    public function test_create_order_with_fractional_promocode_discount(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 955.50,
            'price_old' => null,
            'quantity' => 10,
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $promocode = Promocode::factory()->create([
            'code' => 'TEST10',
            'type' => PromocodeType::Percent,
            'value' => 10,
            'store_id' => null,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 955.50,
        ]);
        CartPromocode::create([
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'promocode_id' => $promocode->id,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertCreated();

        $orderId = $response->json('orderId');

        // 10% от 955.50 = 95.55 — копейки не должны теряться (регрессия
        // LASTOCHKI-JAMI-11200: деньги стали decimal(12,2), а сигнатура
        // createOrderPromocode() и каст discount_amount остались int).
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'subtotal' => 955.50,
            'discount' => 95.55,
        ]);

        $this->assertDatabaseHas('order_promocodes', [
            'order_id' => $orderId,
            'code' => 'TEST10',
            'discount_amount' => 95.55,
        ]);
    }

    // ==================== Get Orders ====================

    public function test_get_orders(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        Order::factory()->count(3)->for($user)->for($store)->create();
        Order::factory()->for(User::factory())->for($store)->create(); // Чужой заказ

        $response = $this->actingAs($user)->getJson('/api/v1/orders');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'hasMore',
                'data' => [
                    '*' => ['id', 'status', 'subtotal', 'total', 'createdAt'],
                ],
                'total',
            ]);
    }

    public function test_get_orders_pagination(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        Order::factory()->count(25)->for($user)->for($store)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/orders?perPage=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('hasMore', true)
            ->assertJsonPath('total', 25);
    }

    // ==================== Get Order By Id ====================

    public function test_get_order_by_id(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()
            ->for($user)
            ->for($store)
            ->has(OrderItem::factory()->count(2), 'items')
            ->create();

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonCount(2, 'data.items');
    }

    public function test_get_other_user_order_forbidden(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()->for($otherUser)->for($store)->create();

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}");

        $response->assertForbidden();
    }

    public function test_get_nonexistent_order_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/orders/99999');

        $response->assertNotFound();
    }

    // ==================== Cancel Order ====================

    public function test_cancel_order(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'quantity' => 8, // После резервирования 2 шт
        ]);

        $order = Order::factory()->pending()->for($user)->for($store)->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/cancel");

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);

        // Товары возвращены на склад
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'quantity' => 10, // 8 + 2
        ]);
    }

    public function test_cancel_order_invalid_status(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()->delivered()->for($user)->for($store)->create();

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/cancel");

        $response->assertUnprocessable();
    }

    public function test_cancel_other_user_order_forbidden(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()->pending()->for($otherUser)->for($store)->create();

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/cancel");

        $response->assertForbidden();
    }

    // ==================== Reserves Products ====================

    public function test_reserves_products(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['min_order_amount' => null]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
            'stock_mode' => 'fixed',
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 3,
            'price' => 100,
        ]);

        $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);


        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'quantity' => 7, // 10 - 3
        ]);
    }

    public function test_fails_on_insufficient_stock(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['min_order_amount' => null]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Молоко',
            'price' => 100,
            'quantity' => 2,
            'stock_mode' => 'fixed',
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 5, // Больше чем на складе
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertUnprocessable();
        $this->assertStringContainsString('Молоко', $response->json('message'));
    }

    public function test_releases_products_on_cancel(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'quantity' => 5,
            'stock_mode' => 'fixed',
        ]);

        $order = Order::factory()->pending()->for($user)->for($store)->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/cancel");

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'quantity' => 8, // 5 + 3
        ]);
    }

    // ==================== Get Active Order ====================

    public function test_get_active_order(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        // Создаём активный заказ (pending)
        $activeOrder = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create(['created_at' => now()]);

        // Создаём старый активный заказ
        $oldActiveOrder = Order::factory()
            ->state(['status' => 'assembling'])
            ->for($user)
            ->for($store)
            ->create(['created_at' => now()->subHour()]);

        // Создаём неактивные заказы
        Order::factory()
            ->delivered()
            ->for($user)
            ->for($store)
            ->create(['created_at' => now()->subDay()]);

        Order::factory()
            ->state(['status' => 'cancelled'])
            ->for($user)
            ->for($store)
            ->create(['created_at' => now()->subDays(2)]);

        $response = $this->actingAs($user)->getJson('/api/v1/orders/active');

        $response->assertOk()
            ->assertJsonPath('data.id', $activeOrder->id)
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_get_active_order_returns_null_when_no_active(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        // Создаём только неактивные заказы
        Order::factory()->delivered()->for($user)->for($store)->create();
        Order::factory()->state(['status' => 'cancelled'])->for($user)->for($store)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/orders/active');

        $response->assertOk()
            ->assertJson(['data' => null]);
    }

    public function test_get_active_order_returns_null_when_no_orders(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/orders/active');

        $response->assertOk()
            ->assertJson(['data' => null]);
    }

    public function test_get_active_order_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/orders/active');

        $response->assertUnauthorized();
    }

    // ==================== Payment Type / Payment Method ====================

    public function test_create_order_with_payment_method_field(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 10,
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 300,
        ]);

        // Мобилка отправляет paymentMethod вместо paymentType
        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'paymentMethod' => 'card',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('orders', [
            'id' => $response->json('orderId'),
            'payment_type' => 'card',
        ]);
    }

    public function test_create_order_with_payment_type_field(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 10,
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 300,
        ]);

        // Прямая отправка paymentType тоже работает
        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'paymentType' => 'card',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('orders', [
            'id' => $response->json('orderId'),
            'payment_type' => 'card',
        ]);
    }

    public function test_payment_type_takes_precedence_over_payment_method(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 300,
            'quantity' => 10,
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 300,
        ]);

        // Если оба поля — paymentType имеет приоритет
        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'paymentType' => 'card',
            'paymentMethod' => 'cash',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('orders', [
            'id' => $response->json('orderId'),
            'payment_type' => 'card',
        ]);
    }

    // ==================== Auth Required ====================

    public function test_get_orders_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/orders');

        $response->assertUnauthorized();
    }

    public function test_create_order_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/orders', []);

        $response->assertUnauthorized();
    }

    // ==================== Validation ====================

    public function test_create_order_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['storeId', 'addressId', 'deliverySlotId']);
    }

    public function test_create_order_validates_address_ownership(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['addressId']);
    }
}
