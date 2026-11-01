<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Actions\Cart\AddToCartAction;
use App\Actions\Order\CreateOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Models\Address;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

/**
 * Ставка НДС товара (vat_rate, код ЮKassa 1-6) снапшотится в order_items
 * на момент оформления заказа — чтобы чек отражал НДС, действовавший тогда.
 */
final class OrderItemVatSnapshotTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private function makeStore(): Store
    {
        return Store::factory()->create([
            'min_order_amount' => 100,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
    }

    public function test_product_vat_rate_snapshots_into_order_item(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();
        $store = $this->makeStore();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'sku' => 'SKU-VAT', 'price' => 300, 'quantity' => 10,
            'quantity_step' => 1, 'measurement_unit' => 'piece', 'vat_rate' => 3,
        ]);

        app(AddToCartAction::class)->execute($user, $product->id, 1);

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
            'vat_rate' => 3,
        ]);
    }

    public function test_product_without_vat_rate_snapshots_null(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();
        $store = $this->makeStore();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'sku' => 'SKU-NOVAT', 'price' => 300, 'quantity' => 10,
            'quantity_step' => 1, 'measurement_unit' => 'piece', 'vat_rate' => null,
        ]);

        app(AddToCartAction::class)->execute($user, $product->id, 1);

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
            'vat_rate' => null,
        ]);
    }
}
