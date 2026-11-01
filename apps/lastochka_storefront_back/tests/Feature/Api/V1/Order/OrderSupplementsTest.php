<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSupplement;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderSupplementsTest extends TestCase
{
    use RefreshDatabase;

    private function setupCartWithSupplements(): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['min_order_amount' => 0]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 170,
            'quantity' => 10,
        ]);
        $address = Address::factory()->for($user)->active()->moscow()->create();

        // Создаём добавки
        $supplement = Supplement::create([
            'title' => 'Допы',
            'product_id' => $product->id,
            'type' => 'many',
            'is_required' => false,
        ]);

        $value1 = SupplementValue::create(['title' => 'Аджика', 'price' => 50, 'supplement_id' => $supplement->id]);
        $value2 = SupplementValue::create(['title' => 'Сметана', 'price' => 40, 'supplement_id' => $supplement->id]);

        // Создаём корзину с добавками
        $cart = Cart::create(['user_id' => $user->id]);
        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 170,
        ]);

        $cartItemSupplement = CartItemSupplement::create([
            'cart_item_id' => $cartItem->id,
            'supplement_id' => $supplement->id,
        ]);
        $cartItemSupplement->values()->attach([$value1->id, $value2->id]);

        return compact('user', 'store', 'product', 'address', 'cart', 'cartItem', 'supplement', 'value1', 'value2');
    }

    public function test_create_order_with_supplements(): void
    {
        $data = $this->setupCartWithSupplements();
        $deliverySlotId = $this->getValidDeliverySlotId();

        $response = $this->actingAs($data['user'])->postJson('/api/v1/orders', [
            'storeId' => $data['store']->id,
            'addressId' => $data['address']->id,
            'deliverySlotId' => $deliverySlotId,
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['orderId']);

        $orderId = $response->json('orderId');

        // Проверяем что OrderItemSupplement создан
        $this->assertDatabaseHas('order_item_supplements', [
            'supplement_id' => $data['supplement']->id,
            'amount' => 90, // 50 + 40
        ]);

        // Проверяем pivot с snapshot цен
        $this->assertDatabaseHas('join_order_item_supplements_supplement_values', [
            'supplement_value_id' => $data['value1']->id,
            'amount' => 50,
        ]);

        $this->assertDatabaseHas('join_order_item_supplements_supplement_values', [
            'supplement_value_id' => $data['value2']->id,
            'amount' => 40,
        ]);
    }

    public function test_order_subtotal_includes_supplements(): void
    {
        $data = $this->setupCartWithSupplements();
        $deliverySlotId = $this->getValidDeliverySlotId();

        $response = $this->actingAs($data['user'])->postJson('/api/v1/orders', [
            'storeId' => $data['store']->id,
            'addressId' => $data['address']->id,
            'deliverySlotId' => $deliverySlotId,
        ]);

        $response->assertCreated();

        // subtotal = (170 + 50 + 40) * 1 = 260
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('orderId'),
            'subtotal' => 260,
        ]);
    }

    public function test_order_item_total_includes_supplements(): void
    {
        // ALADDIN-10348: order_items.total должен учитывать сумму добавок,
        // т.е. (price + supplements) * portions, не price * portions.
        $data = $this->setupCartWithSupplements();
        $deliverySlotId = $this->getValidDeliverySlotId();

        $response = $this->actingAs($data['user'])->postJson('/api/v1/orders', [
            'storeId' => $data['store']->id,
            'addressId' => $data['address']->id,
            'deliverySlotId' => $deliverySlotId,
        ]);

        $response->assertCreated();

        // (170 + 50 + 40) * 1 = 260, не 170 * 1 = 170
        $this->assertDatabaseHas('order_items', [
            'order_id' => $response->json('orderId'),
            'product_id' => $data['product']->id,
            'total' => 260,
        ]);
    }

    public function test_order_item_total_uses_portions_for_gram_products(): void
    {
        // ALADDIN-10348: для весовых товаров total = (price + supplements) * portions,
        // где portions = quantity / quantity_step (НЕ quantity в граммах).
        $user = \App\Models\User::factory()->create();
        $store = \App\Models\Store::factory()->create(['min_order_amount' => 0]);
        $category = \App\Models\Category::factory()->forStore($store)->create();
        $product = \App\Models\Product::factory()->for($store)->inCategory($category)->create([
            'price' => 22,
            'quantity' => 10000,
            'quantity_step' => 500,
            'measurement_unit' => 'gram',
        ]);
        $address = \App\Models\Address::factory()->for($user)->active()->moscow()->create();

        $supplement = \App\Models\Supplement::create([
            'title' => 'Допы',
            'product_id' => $product->id,
            'type' => 'many',
            'is_required' => false,
        ]);
        $husk = \App\Models\SupplementValue::create(['title' => 'Шелуха', 'price' => 10, 'supplement_id' => $supplement->id]);
        $skin = \App\Models\SupplementValue::create(['title' => 'Кожа', 'price' => 20, 'supplement_id' => $supplement->id]);

        $cart = \App\Models\Cart::create(['user_id' => $user->id]);
        $cartItem = \App\Models\CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 500,
            'price' => 22,
        ]);
        $cartItemSupplement = \App\Models\CartItemSupplement::create([
            'cart_item_id' => $cartItem->id,
            'supplement_id' => $supplement->id,
        ]);
        $cartItemSupplement->values()->attach([$husk->id, $skin->id]);

        $deliverySlotId = $this->getValidDeliverySlotId();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $deliverySlotId,
        ]);

        $response->assertCreated();

        // portions = 500 / 500 = 1 → total = (22 + 30) * 1 = 52, не 22 * 1 = 22 и не 15022
        $this->assertDatabaseHas('order_items', [
            'order_id' => $response->json('orderId'),
            'product_id' => $product->id,
            'total' => 52,
        ]);
    }

    public function test_order_item_resource_includes_supplements(): void
    {
        $data = $this->setupCartWithSupplements();
        $deliverySlotId = $this->getValidDeliverySlotId();

        $createResponse = $this->actingAs($data['user'])->postJson('/api/v1/orders', [
            'storeId' => $data['store']->id,
            'addressId' => $data['address']->id,
            'deliverySlotId' => $deliverySlotId,
        ]);

        $orderId = $createResponse->json('orderId');

        $response = $this->actingAs($data['user'])->getJson("/api/v1/orders/{$orderId}");

        $response->assertOk()
            ->assertJsonPath('data.items.0.supplements.0.supplementId', $data['supplement']->id)
            ->assertJsonPath('data.items.0.supplements.0.amount', 90);
    }
}
