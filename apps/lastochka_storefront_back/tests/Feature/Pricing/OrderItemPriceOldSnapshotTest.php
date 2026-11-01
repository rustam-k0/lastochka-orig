<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Actions\Cart\AddToCartAction;
use App\Actions\Order\CreateOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Http\Resources\Api\V1\OrderItemResource;
use App\Models\Address;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Оригинальная цена ДО скидки снапшотится по всему пути:
 * Product::getOriginalPrice() → cart_items.price_old → order_items.price_old
 * → OrderItemResource.priceOld. Позволяет показать зачёркнутую цену в заказе.
 */
final class OrderItemPriceOldSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_discount_original_price_flows_from_cart_to_order(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $category = Category::factory()->forStore($store)->create();
        // Ручная скидка: было 300, стало 240.
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'sku' => 'SKU-DISCOUNT',
            'price' => 240,
            'price_old' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        // 1. Добавление в корзину снапшотит оригинальную цену.
        app(AddToCartAction::class)->execute($user, $product->id, 1);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'price' => 240,
            'price_old' => 300,
        ]);

        // 2. Оформление заказа копирует снапшот в order_items.
        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            paymentType: 'cash',
        ));
        $order = Order::query()->findOrFail($result['orderId']);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'price' => 240,
            'price_old' => 300,
        ]);

        // 3. Ресурс отдаёт зачёркнутую цену и процент скидки.
        $orderItem = $order->items()->where('product_id', $product->id)->first();
        $payload = (new OrderItemResource($orderItem))->toArray(request());

        $this->assertSame(240.0, $payload['price']);
        $this->assertSame(300.0, $payload['priceOld']);
        $this->assertSame(20, $payload['discountPercent']);
    }

    public function test_price_old_preserves_kopecks_decimal(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $category = Category::factory()->forStore($store)->create();
        // Базовая цена с копейками: было 199.90, стало 179.91.
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'sku' => 'SKU-KOPECKS',
            'price' => 179.91,
            'price_old' => 199.90,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        app(AddToCartAction::class)->execute($user, $product->id, 1);

        // Копейки оригинальной цены не теряются (decimal, не int).
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'price_old' => 199.90,
        ]);

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            paymentType: 'cash',
        ));
        $order = Order::query()->findOrFail($result['orderId']);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'price_old' => 199.90,
        ]);

        $orderItem = $order->items()->where('product_id', $product->id)->first();
        $payload = (new OrderItemResource($orderItem))->toArray(request());

        $this->assertSame(199.90, $payload['priceOld']);
    }

    public function test_no_discount_keeps_price_old_null(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'sku' => 'SKU-PLAIN',
            'price' => 300,
            'price_old' => null,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        app(AddToCartAction::class)->execute($user, $product->id, 1);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'price' => 300,
            'price_old' => null,
        ]);

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            paymentType: 'cash',
        ));
        $order = Order::query()->findOrFail($result['orderId']);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'price' => 300,
            'price_old' => null,
        ]);

        $orderItem = $order->items()->where('product_id', $product->id)->first();
        $payload = (new OrderItemResource($orderItem))->toArray(request());

        $this->assertNull($payload['priceOld']);
        $this->assertNull($payload['discountPercent']);
    }
}
