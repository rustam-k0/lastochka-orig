<?php

declare(strict_types=1);

namespace Tests\Feature\YandexDelivery;

use App\Enums\OrderStatus;
use App\Enums\ShippingMethod;
use App\Enums\YandexClaimStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Models\YandexDeliveryClaim;
use App\Services\YandexDelivery\FakeYandexGoClient;
use App\Services\YandexDelivery\YandexGoClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelOrderYandexTest extends TestCase
{
    use RefreshDatabase;

    private FakeYandexGoClient $fakeYandex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeYandex = $this->app->make(YandexGoClientInterface::class);
    }

    public function test_cancel_order_cancels_yandex_claim(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'shipping_method' => ShippingMethod::YandexDelivery,
        ]);
        YandexDeliveryClaim::create([
            'order_id' => $order->id,
            'claim_id' => 'test_cancel_claim',
            'status' => YandexClaimStatus::Accepted,
            'due_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/cancel");

        $response->assertOk();
        $this->assertEquals(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertEquals(YandexClaimStatus::Cancelled, $order->fresh()->yandexClaim->status);
        $this->assertCount(1, $this->fakeYandex->cancelledClaims);
    }

    public function test_cancel_blocked_when_courier_arrived(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'shipping_method' => ShippingMethod::YandexDelivery,
        ]);
        YandexDeliveryClaim::create([
            'order_id' => $order->id,
            'claim_id' => 'test_blocked_claim',
            'status' => YandexClaimStatus::PickupArrived,
            'due_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$order->id}/cancel");

        $response->assertUnprocessable();
        $this->assertEquals(OrderStatus::Pending, $order->fresh()->status);
        $this->assertEmpty($this->fakeYandex->cancelledClaims);
    }
}
