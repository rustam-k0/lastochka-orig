<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Связка «поведение в приложении → заказ» (LASTOCHKI-JAMI-11659, #11642 п.5):
 * заголовки X-Analytics-Session-Id / X-Analytics-Client-Id при оформлении
 * заказа персистятся в orders и уезжают в payload события order.created
 * (в т.ч. при пересборке payload outbox-воркером из колонок).
 */
final class OrderAnalyticsHeadersTest extends TestCase
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
            'quantity' => 50,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 2,
            'price' => 100,
        ]);

        $payload = [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ];

        return [$user, $store, $payload];
    }

    public function test_analytics_headers_are_persisted_and_published_in_order_created(): void
    {
        [$user, $store, $payload] = $this->checkoutFixture();

        $sessionId = (string) Str::uuid();
        $deviceClientId = (string) Str::uuid();

        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Analytics-Session-Id' => $sessionId,
                'X-Analytics-Client-Id' => $deviceClientId,
            ])
            ->postJson('/api/v1/orders', $payload);

        $response->assertCreated();

        $this->assertDatabaseHas('orders', [
            'store_id' => $store->id,
            'analytics_session_id' => $sessionId,
            'analytics_device_client_id' => $deviceClientId,
        ]);

        // Пересборка payload воркером идёт из колонок — проверяем сам трансформер
        $order = \App\Models\Order::where('store_id', $store->id)->firstOrFail();
        $eventData = (new OrderEventTransformer())->toEvent($order);

        $this->assertSame($sessionId, $eventData['session_id']);
        $this->assertSame($deviceClientId, $eventData['device_client_id']);
        $this->assertSame((string) $order->id, $eventData['order_id']);
        $this->assertSame((string) $order->id, $eventData['correlation_id']);
    }

    public function test_order_without_headers_has_null_analytics_ids(): void
    {
        [$user, $store, $payload] = $this->checkoutFixture();

        $this->actingAs($user)->postJson('/api/v1/orders', $payload)->assertCreated();

        $this->assertDatabaseHas('orders', [
            'store_id' => $store->id,
            'analytics_session_id' => null,
            'analytics_device_client_id' => null,
        ]);
    }
}
