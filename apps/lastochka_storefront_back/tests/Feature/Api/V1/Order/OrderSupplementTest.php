<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Enums\OrderStatus;
use App\Enums\PromocodeType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPromocode;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrderSupplementTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Check Supplement ====================

    public function test_check_supplement_returns_order_id_when_pending_order_exists(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create();

        $response = $this->actingAs($user)->getJson("/api/v1/orders/check-supplement?storeId={$store->id}");

        $response->assertOk()
            ->assertJson([
                'order_id' => $order->id,
                'can_supplement' => true,
            ]);
    }

    public function test_check_supplement_returns_null_when_no_pending_order(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        // Заказ в статусе, который дополнять нельзя. Assembling для этого
        // больше не подходит: с ALADDIN-8173 он входит в
        // OrderStatus::supplementable() наравне с Pending.
        Order::factory()
            ->for($user)
            ->for($store)
            ->create(['status' => OrderStatus::Delivered]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/check-supplement?storeId={$store->id}");

        $response->assertOk()
            ->assertJson([
                'order_id' => null,
                'can_supplement' => false,
            ]);
    }

    public function test_check_supplement_returns_latest_pending_order(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        // Старый заказ
        Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create(['created_at' => now()->subHour()]);

        // Новый заказ
        $newOrder = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create(['created_at' => now()]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/check-supplement?storeId={$store->id}");

        $response->assertOk()
            ->assertJson([
                'order_id' => $newOrder->id,
                'can_supplement' => true,
            ]);
    }

    public function test_check_supplement_requires_store_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/orders/check-supplement');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['storeId']);
    }

    public function test_check_supplement_validates_store_exists(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/orders/check-supplement?storeId=99999');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['storeId']);
    }

    /**
     * ALD-130: время слота доставки прошло — кнопка «Дополнить заказ» должна пропасть,
     * даже если статус заказа всё ещё supplementable (заказ завис в pending/assembling).
     */
    public function test_check_supplement_returns_false_when_delivery_slot_passed(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create([
                'delivery_slot_start' => now()->subHours(7),
                'delivery_slot_end' => now()->subHours(6),
            ]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/check-supplement?storeId={$store->id}");

        $response->assertOk()
            ->assertJson([
                'order_id' => null,
                'can_supplement' => false,
            ]);
    }

    /**
     * Заказ без слота (delivery_slot_end === null — доставка СДЭК/Почтой России,
     * где слотов нет) правилу по времени не подчиняется, дополнение остаётся доступным.
     */
    public function test_check_supplement_returns_true_when_order_has_no_delivery_slot(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create([
                'delivery_slot_start' => null,
                'delivery_slot_end' => null,
                'delivery_slot_display' => null,
            ]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/check-supplement?storeId={$store->id}");

        $response->assertOk()
            ->assertJson([
                'order_id' => $order->id,
                'can_supplement' => true,
            ]);
    }

    /**
     * Порог — конец слота (delivery_slot_end), а не начало: заказ, слот которого уже
     * начался, но ещё не закончился (сборщик мог взять его в работу в середине окна),
     * остаётся дополняемым.
     */
    public function test_check_supplement_returns_true_when_slot_started_but_not_yet_ended(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create([
                'delivery_slot_start' => now()->subMinutes(15),
                'delivery_slot_end' => now()->addMinutes(15),
            ]);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/check-supplement?storeId={$store->id}");

        $response->assertOk()
            ->assertJson([
                'order_id' => $order->id,
                'can_supplement' => true,
            ]);
    }

    // ==================== Supplement Order ====================

    public function test_supplement_order_adds_new_items(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        // Товар в заказе
        $product1 = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
            'stock_mode' => 'fixed', // остаток списывается только в fixed
        ]);

        // Новый товар для дополнения
        $product2 = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 200,
            'quantity' => 10,
            'stock_mode' => 'fixed', // остаток списывается только в fixed
        ]);

        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create([
                'subtotal' => 200,
                'discount' => 0,
                'delivery_cost' => 100,
                'total' => 300,
                'items_count' => 1,
            ]);

        OrderItem::factory()->for($order)->create([
            'product_id' => $product1->id,
            'price' => 100,
            'quantity' => 2,
            'total' => 200,
        ]);

        // Корзина с новым товаром
        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product2)->create([
            'store_id' => $store->id,
            'quantity' => 3,
            'price' => 200,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement");

        $response->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonCount(2, 'data.items');

        // Проверяем пересчёт сумм
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'subtotal' => 800, // 200 + 600
            'discount' => 0,
            'delivery_cost' => 100,
            'total' => 900, // 800 - 0 + 100
            'items_count' => 2,
        ]);

        // Проверяем новый товар
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product2->id,
            'quantity' => 3,
            'price' => 200,
            'total' => 600,
        ]);

        // Проверяем резервирование
        $this->assertDatabaseHas('products', [
            'id' => $product2->id,
            'quantity' => 7, // 10 - 3
        ]);

        // Проверяем очистку корзины
        $this->assertDatabaseMissing('cart_items', [
            'cart_id' => $cart->id,
            'store_id' => $store->id,
        ]);
    }

    public function test_supplement_order_increases_existing_item_quantity(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);

        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create([
                'subtotal' => 200,
                'discount' => 0,
                'delivery_cost' => 0,
                'total' => 200,
                'items_count' => 1,
            ]);

        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'price' => 100,
            'quantity' => 2,
            'total' => 200,
        ]);

        // Корзина с тем же товаром
        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 3,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items'); // Всё ещё 1 позиция

        // Проверяем увеличение количества
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 5, // 2 + 3
            'total' => 500, // 5 * 100
        ]);

        // Проверяем пересчёт заказа
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'subtotal' => 500,
            'total' => 500,
            'items_count' => 1,
        ]);
    }

    public function test_supplement_other_user_order_forbidden(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()
            ->pending()
            ->for($otherUser)
            ->for($store)
            ->create();

        // Корзина текущего пользователя
        $cart = Cart::factory()->for($user)->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement");

        $response->assertForbidden();
    }

    public function test_supplement_order_in_terminal_status_is_forbidden(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);

        // Assembling дополнять разрешено (ALADDIN-8173). Неподходящий статус
        // отсекает OrderPolicy::supplement (403); 409 остался только за
        // гонкой — когда статус меняется между проверкой прав и транзакцией.
        $order = Order::factory()
            ->for($user)
            ->for($store)
            ->create(['status' => OrderStatus::Delivered]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement");

        $response->assertStatus(403);
    }

    /**
     * ALD-130: заказ висит в supplementable-статусе (pending/assembling), но время
     * слота доставки уже прошло — дополнение должно быть отклонено, а не молча пройти.
     */
    public function test_supplement_forbidden_when_delivery_slot_passed(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);

        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create([
                'delivery_slot_start' => now()->subHours(7),
                'delivery_slot_end' => now()->subHours(6),
            ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement");

        $response->assertForbidden();

        // Товар не должен резервироваться, корзина не должна очищаться
        $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 10]);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'store_id' => $store->id]);
    }

    /**
     * Заказ без слота доставки (delivery_slot_end === null — доставка СДЭК/Почтой
     * России, где слотов нет) правилу по времени не подчиняется: дополнение проходит,
     * поведение не меняется.
     */
    public function test_supplement_succeeds_when_order_has_no_delivery_slot(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);

        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create([
                'delivery_slot_start' => null,
                'delivery_slot_end' => null,
                'delivery_slot_display' => null,
            ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement");

        $response->assertOk();
    }

    /**
     * Порог — конец слота, а не начало: заказ, слот которого уже начался, но ещё
     * не закончился (сборщик мог взять его в работу в середине окна), остаётся
     * дополняемым.
     */
    public function test_supplement_succeeds_when_slot_started_but_not_yet_ended(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);

        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create([
                'delivery_slot_start' => now()->subMinutes(15),
                'delivery_slot_end' => now()->addMinutes(15),
            ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement");

        $response->assertOk();
    }

    public function test_supplement_empty_cart_returns_error(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create();

        // Пустая корзина
        Cart::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement");

        $response->assertUnprocessable();
    }

    public function test_supplement_insufficient_stock_returns_error(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Молоко',
            'price' => 100,
            'quantity' => 2, // Мало на складе
        ]);

        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 5, // Больше чем на складе
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement");

        $response->assertUnprocessable();
        $this->assertStringContainsString('Молоко', $response->json('message'));
    }

    public function test_supplement_idempotency(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);

        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create([
                'subtotal' => 0,
                'discount' => 0,
                'delivery_cost' => 0,
                'total' => 0,
                'items_count' => 0,
            ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 100,
        ]);

        $idempotencyKey = Str::uuid()->toString();

        // Первый запрос
        $response1 = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement", [
            'idempotency_key' => $idempotencyKey,
        ]);

        $response1->assertOk();

        // Добавляем ещё товаров в корзину
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 3,
            'price' => 100,
        ]);

        // Повторный запрос с тем же ключом
        $response2 = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement", [
            'idempotency_key' => $idempotencyKey,
        ]);

        $response2->assertOk();

        // Проверяем, что сумма не изменилась
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'subtotal' => 200, // Только от первого запроса
        ]);

        // Проверяем, что запись идемпотентности создана
        $this->assertDatabaseHas('order_supplement_idempotency', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_supplement_requires_auth(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();
        $order = Order::factory()->pending()->for($user)->for($store)->create();

        $response = $this->postJson("/api/v1/orders/{$order->id}/supplement");

        $response->assertUnauthorized();
    }

    public function test_check_supplement_requires_auth(): void
    {
        $store = Store::factory()->create();

        $response = $this->getJson("/api/v1/orders/check-supplement?storeId={$store->id}");

        $response->assertUnauthorized();
    }

    public function test_supplement_preserves_discount_and_delivery_cost_without_promocode(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
        ]);

        // Заказ с существующей скидкой (без промокода) и стоимостью доставки
        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create([
                'subtotal' => 500,
                'discount' => 50,  // Скидка без промокода
                'delivery_cost' => 200, // Доставка
                'total' => 650, // 500 - 50 + 200
                'items_count' => 1,
            ]);

        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'price' => 100,
            'quantity' => 5,
            'total' => 500,
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 3, // Добавляем 300 рублей
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement");

        $response->assertOk();

        // Без промокода discount и delivery_cost сохраняются
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'subtotal' => 800, // 500 + 300
            'discount' => 50,  // Не изменилось (нет промокода)
            'delivery_cost' => 200, // Не изменилось
            'total' => 950, // 800 - 50 + 200
        ]);
    }

    public function test_supplement_recalculates_percent_discount_with_promocode(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 20,
        ]);

        // Заказ с промокодом 10%
        $order = Order::factory()
            ->pending()
            ->for($user)
            ->for($store)
            ->create([
                'subtotal' => 500,
                'discount' => 50, // 10% от 500
                'delivery_cost' => 0,
                'total' => 450, // 500 - 50
                'items_count' => 1,
            ]);

        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'price' => 100,
            'quantity' => 5,
            'total' => 500,
        ]);

        // Snapshot промокода на заказе
        OrderPromocode::factory()->percent(10)->for($order)->create([
            'discount_amount' => 50,
        ]);

        // Корзина с тем же товаром (добавляем 3 шт = 300 руб)
        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 3,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/supplement");

        $response->assertOk();

        // Discount пересчитан: 10% от 800 = 80
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'subtotal' => 800,
            'discount' => 80, // Пересчитано: 10% от 800
            'total' => 720, // 800 - 80
        ]);

        // discount_amount в order_promocodes тоже обновлён
        $this->assertDatabaseHas('order_promocodes', [
            'order_id' => $order->id,
            'discount_amount' => 80,
        ]);
    }
}
