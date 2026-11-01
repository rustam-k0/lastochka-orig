<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CreateOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\FakeDeliveryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Валидация слота при создании заказа учитывает товары корзины (LASTOCHKI-JAMI-11145).
 *
 * Если слоты отдаются клиенту с учётом времени сборки, то и validateSlot
 * при оформлении обязан запрашивать слоты с теми же products — иначе слот,
 * недоступный из-за времени сборки, прошёл бы валидацию.
 */
final class CreateOrderPassesCartProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_courier_order_creation_requests_slots_with_cart_products(): void
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
            'sku' => 'SKU-ORDER',
            'price' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 300,
        ]);

        app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            paymentType: 'cash',
        ));

        /** @var FakeDeliveryClient $client */
        $client = app(DeliveryClientInterface::class);

        $this->assertNotEmpty($client->capturedProducts, 'getSlots должен вызываться при создании заказа');

        foreach ($client->capturedProducts as $products) {
            $this->assertSame([['articul' => 'SKU-ORDER', 'count' => 2]], $products);
        }
    }
}
