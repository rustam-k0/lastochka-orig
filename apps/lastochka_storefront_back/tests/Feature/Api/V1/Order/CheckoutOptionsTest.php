<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Order;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CheckoutOptionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Store, 2: array<string, mixed>}
     */
    private function checkoutFixture(): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);
        $address = Address::factory()->active()->for($user)->create();
        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        $payload = [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ];

        return [$user, $store, $payload];
    }

    public function test_app_settings_returns_default_chill_option(): void
    {
        $response = $this->getJson('/api/v1/app-settings')->assertOk();

        $this->assertSame(
            [['code' => 'chill_before_delivery', 'label' => 'Охладить напитки!']],
            $response->json('data.checkoutOptions'),
        );
    }

    public function test_app_settings_returns_options_from_setting_and_hides_disabled(): void
    {
        StorefrontSetting::create([
            'key' => 'checkout_options',
            'value' => json_encode([
                ['code' => 'chill_before_delivery', 'label' => 'Охладить', 'enabled' => true],
                ['code' => 'call_before', 'label' => 'Позвонить заранее', 'enabled' => false],
                ['code' => 'no_bags', 'label' => 'Без пакетов'],
            ]),
            'type' => 'json',
        ]);

        $response = $this->getJson('/api/v1/app-settings')->assertOk();

        $this->assertSame([
            ['code' => 'chill_before_delivery', 'label' => 'Охладить'],
            ['code' => 'no_bags', 'label' => 'Без пакетов'],
        ], $response->json('data.checkoutOptions'));
    }

    public function test_order_stores_snapshot_of_selected_options(): void
    {
        [$user, $store, $payload] = $this->checkoutFixture();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', array_merge($payload, [
            'checkoutOptions' => ['chill_before_delivery'],
        ]));

        $response->assertCreated();

        $order = \App\Models\Order::findOrFail($response->json('orderId'));

        $this->assertSame(
            [['code' => 'chill_before_delivery', 'label' => 'Охладить напитки!']],
            $order->checkout_options,
        );
    }

    public function test_order_without_options_stores_null(): void
    {
        [$user, $store, $payload] = $this->checkoutFixture();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', $payload);

        $response->assertCreated();

        $order = \App\Models\Order::findOrFail($response->json('orderId'));

        $this->assertNull($order->checkout_options);
    }

    public function test_order_rejects_unknown_option(): void
    {
        [$user, $store, $payload] = $this->checkoutFixture();

        $response = $this->actingAs($user)->postJson('/api/v1/orders', array_merge($payload, [
            'checkoutOptions' => ['unknown_option'],
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['checkoutOptions']);
    }

    public function test_order_resource_returns_checkout_options(): void
    {
        [$user, $store, $payload] = $this->checkoutFixture();

        $orderId = $this->actingAs($user)->postJson('/api/v1/orders', array_merge($payload, [
            'checkoutOptions' => ['chill_before_delivery'],
        ]))->json('orderId');

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$orderId}");

        $response->assertOk()
            ->assertJsonPath('data.checkoutOptions.0.code', 'chill_before_delivery')
            ->assertJsonPath('data.checkoutOptions.0.label', 'Охладить напитки!');
    }

    public function test_order_event_contains_checkout_options(): void
    {
        [$user, $store, $payload] = $this->checkoutFixture();

        $orderId = $this->actingAs($user)->postJson('/api/v1/orders', array_merge($payload, [
            'checkoutOptions' => ['chill_before_delivery'],
        ]))->json('orderId');

        $order = \App\Models\Order::findOrFail($orderId);
        $event = app(\App\RabbitMQ\Transformers\OrderEventTransformer::class)->toEvent($order);

        $this->assertSame(
            [['code' => 'chill_before_delivery', 'label' => 'Охладить напитки!']],
            $event['checkout_options'],
        );
    }
}
