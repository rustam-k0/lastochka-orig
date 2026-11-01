<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\B2b;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesB2bModule;
use Tests\TestCase;

final class B2bOrderTest extends TestCase
{
    use RefreshDatabase;
    use TogglesB2bModule;

    protected function tearDown(): void
    {
        $this->invalidateB2bModuleCache();
        parent::tearDown();
    }

    // ==================== Create Order ====================

    public function test_b2b_client_creates_order_from_b2b_store(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->b2b()->create();
        $store = Store::factory()->b2b()->create([
            'min_order_amount' => 500,
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

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'comment' => 'B2B заказ',
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
        ]);
    }

    public function test_b2b_client_can_order_from_b2c_store(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->b2b()->create();
        $store = Store::factory()->create([ // B2C по умолчанию
            'min_order_amount' => null,
            'delivery_price' => 0,
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

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['orderId']);
    }

    public function test_b2c_client_cannot_order_from_b2b_store(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->create(); // B2C по умолчанию
        $store = Store::factory()->b2b()->create();
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

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertForbidden();
    }

    public function test_module_disabled_b2c_client_can_order_from_b2b_store(): void
    {
        $this->setB2bModule(false);

        $user = User::factory()->create(); // B2C по умолчанию
        $store = Store::factory()->b2b()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
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

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['orderId']);
    }

    public function test_b2b_order_idempotency(): void
    {
        $user = User::factory()->b2b()->create();
        $store = Store::factory()->b2b()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 500,
            'quantity' => 10,
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        $payload = [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
            'idempotencyKey' => 'b2b-test-key-123',
        ];

        $response1 = $this->actingAs($user)->postJson('/api/v1/orders', $payload);
        $response1->assertCreated();
        $orderId1 = $response1->json('orderId');

        // Повторный запрос с тем же ключом
        $response2 = $this->actingAs($user)->postJson('/api/v1/orders', $payload);
        $response2->assertCreated();
        $orderId2 = $response2->json('orderId');

        $this->assertEquals($orderId1, $orderId2);
        $this->assertEquals(1, Order::where('user_id', $user->id)->count());
    }

    public function test_b2b_store_min_order_amount(): void
    {
        $user = User::factory()->b2b()->create();
        $store = Store::factory()->b2b()->create(['min_order_amount' => 5000]);
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
            ->assertJsonPath('errors.subtotal.0', 'Минимальная сумма заказа: 5000 руб. Текущая сумма: 100 руб.');
    }
}
