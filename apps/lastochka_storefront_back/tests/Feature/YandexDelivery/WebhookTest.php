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

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private FakeYandexGoClient $fakeYandex;

    protected function setUp(): void
    {
        parent::setUp();

        // Контроллер вебхука верифицирует присланный статус через
        // getClaimInfo() и при расхождении верит API, а не вебхуку. Фейк по
        // умолчанию отвечает 'accepted' на любую заявку, поэтому каждому
        // сценарию нужно сказать, что API подтверждает тот же статус.
        $this->fakeYandex = $this->app->make(YandexGoClientInterface::class);
    }

    public function test_pickuped_sets_order_in_delivery(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Assembling,
            'shipping_method' => ShippingMethod::YandexDelivery,
        ]);
        YandexDeliveryClaim::create([
            'order_id' => $order->id,
            'claim_id' => 'test_claim_pickuped',
            'status' => YandexClaimStatus::PerformerFound,
            'due_at' => now()->addHour(),
        ]);

        $this->fakeYandex->claimStatuses['test_claim_pickuped'] = 'pickuped';

        $response = $this->postJson('/api/v1/webhooks/yandex-delivery', [
            'claim_id' => 'test_claim_pickuped',
            'status' => 'pickuped',
            'updated_ts' => now()->addSecond()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertEquals(OrderStatus::InDelivery, $order->fresh()->status);
        $this->assertEquals(YandexClaimStatus::Pickuped, $order->fresh()->yandexClaim->status);
    }

    public function test_delivered_sets_order_delivered(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::InDelivery,
            'shipping_method' => ShippingMethod::YandexDelivery,
        ]);
        YandexDeliveryClaim::create([
            'order_id' => $order->id,
            'claim_id' => 'test_claim_delivered',
            'status' => YandexClaimStatus::Pickuped,
            'due_at' => now()->addHour(),
        ]);

        $this->fakeYandex->claimStatuses['test_claim_delivered'] = 'delivered';

        $response = $this->postJson('/api/v1/webhooks/yandex-delivery', [
            'claim_id' => 'test_claim_delivered',
            'status' => 'delivered',
            'updated_ts' => now()->addSecond()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertEquals(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_cancelled_does_not_change_order_status(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'shipping_method' => ShippingMethod::YandexDelivery,
        ]);
        YandexDeliveryClaim::create([
            'order_id' => $order->id,
            'claim_id' => 'test_claim_cancelled',
            'status' => YandexClaimStatus::Accepted,
            'due_at' => now()->addHour(),
        ]);

        $this->fakeYandex->claimStatuses['test_claim_cancelled'] = 'cancelled';

        $response = $this->postJson('/api/v1/webhooks/yandex-delivery', [
            'claim_id' => 'test_claim_cancelled',
            'status' => 'cancelled',
            'updated_ts' => now()->addSecond()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertEquals(OrderStatus::Pending, $order->fresh()->status);
        $this->assertEquals(YandexClaimStatus::Cancelled, $order->fresh()->yandexClaim->status);
    }

    public function test_performer_found_saves_courier_info(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'shipping_method' => ShippingMethod::YandexDelivery,
        ]);
        YandexDeliveryClaim::create([
            'order_id' => $order->id,
            'claim_id' => 'test_claim_performer',
            'status' => YandexClaimStatus::PerformerLookup,
            'due_at' => now()->addHour(),
        ]);

        $this->fakeYandex->claimStatuses['test_claim_performer'] = 'performer_found';

        $response = $this->postJson('/api/v1/webhooks/yandex-delivery', [
            'claim_id' => 'test_claim_performer',
            'status' => 'performer_found',
            'updated_ts' => now()->addSecond()->toIso8601String(),
            'performer_info' => [
                'courier_name' => 'Алексей',
                'phone' => '+79991234567',
            ],
        ]);

        $response->assertOk();
        $claim = $order->fresh()->yandexClaim;
        $this->assertEquals('Алексей', $claim->courier_name);
        $this->assertEquals('+79991234567', $claim->courier_phone);
    }

    public function test_unknown_claim_returns_ok(): void
    {
        $this->fakeYandex->claimStatuses['nonexistent_claim'] = 'delivered';

        $response = $this->postJson('/api/v1/webhooks/yandex-delivery', [
            'claim_id' => 'nonexistent_claim',
            'status' => 'delivered',
        ]);

        $response->assertOk();
    }
}
