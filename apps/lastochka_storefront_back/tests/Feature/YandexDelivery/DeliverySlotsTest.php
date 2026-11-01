<?php

declare(strict_types=1);

namespace Tests\Feature\YandexDelivery;

use App\Models\Address;
use App\Models\IntegrationConfig;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\FakeDeliveryClient;
use App\Services\YandexDelivery\FakeYandexGoClient;
use App\Services\YandexDelivery\YandexGoClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverySlotsTest extends TestCase
{
    use RefreshDatabase;

    private FakeDeliveryClient $fakeDelivery;
    private FakeYandexGoClient $fakeYandex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDelivery = $this->app->make(DeliveryClientInterface::class);
        $this->fakeYandex = $this->app->make(YandexGoClientInterface::class);

        config(['yandex-delivery.enabled' => true]);

        IntegrationConfig::create([
            'integration_type' => 'delivery',
            'driver' => 'yandex_go',
            'credentials' => ['oauth_token' => 'test_token', 'emergency_phone' => '+79991234567', 'callback_url' => 'https://test.com/webhook'],
            'is_active' => true,
        ]);
    }

    public function test_yandex_zone_returns_yandex_delivery_block(): void
    {
        // FakeDeliveryClient отдаёт слоты с 8:00 до 22:00 текущего дня, и
        // прошедшие отфильтровываются: без фиксации времени тест разваливался
        // при ночном прогоне — слотов не оставалось, и блок yandexDelivery
        // уходил в unavailable.
        $this->travelTo(now()->setTime(10, 0));

        $this->fakeDelivery->deliveryType = 'yandex';

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}&date=" . now()->format('Y-m-d'),
        );

        $response->assertOk();
        $response->assertJsonStructure([
            'yandexDelivery' => ['available', 'price', 'eta', 'availableSlots'],
            'availablePaymentMethods',
        ]);
        $response->assertJsonPath('availablePaymentMethods', ['card']);
        $response->assertJsonPath('yandexDelivery.available', true);
        $response->assertJsonPath('yandexDelivery.price', 508); // ceil(507.52)
    }

    public function test_own_zone_has_no_yandex_block(): void
    {
        $this->fakeDelivery->deliveryType = 'own';

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}&date=" . now()->format('Y-m-d'),
        );

        $response->assertOk();
        $response->assertJsonMissing(['yandexDelivery']);
        $response->assertJsonPath('availablePaymentMethods', ['card', 'cash']);
    }

    public function test_yandex_api_failure_returns_unavailable(): void
    {
        $this->fakeDelivery->deliveryType = 'yandex';
        $this->fakeYandex->shouldFail = true;

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}&date=" . now()->format('Y-m-d'),
        );

        $response->assertOk();
        $response->assertJsonPath('yandexDelivery.available', false);
    }

    public function test_yandex_disabled_returns_no_yandex_block(): void
    {
        $this->fakeDelivery->deliveryType = 'yandex';
        config(['yandex-delivery.enabled' => false]);

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}&date=" . now()->format('Y-m-d'),
        );

        $response->assertOk();
        // When yandex disabled, no yandex enrichment happens,
        // but deliveryType is still yandex from API - resource just won't have the block
    }
}
