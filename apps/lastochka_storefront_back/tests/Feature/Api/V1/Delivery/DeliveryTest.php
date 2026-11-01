<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Delivery;

use App\Models\Address;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use App\Services\Delivery\DeliverySystemClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Использовать реальный DeliverySystemClient для тестирования HTTP-интеграции.
     */
    private function useRealDeliveryClient(): void
    {
        $this->app->bind(DeliveryClientInterface::class, DeliverySystemClient::class);
    }

    private function fakeDeliveryApiResponse(): array
    {
        return [
            'slots' => [
                [
                    'start' => '2025-01-15 10:00:00',
                    'end' => '2025-01-15 12:00:00',
                ],
                [
                    'start' => '2025-01-15 12:00:00',
                    'end' => '2025-01-15 14:00:00',
                ],
            ],
            'min_order_price' => 500,
            'shipping_free_from' => 1500,
            'shipping_price' => 200,
        ];
    }

    // ==================== Get Delivery Slots ====================

    public function test_get_delivery_slots(): void
    {
        $this->useRealDeliveryClient();

        config(['services.delivery_system.url' => 'https://delivery.test']);
        config(['services.delivery_system.api_key' => 'test-key']);

        Http::fake([
            'delivery.test/*' => Http::response($this->fakeDeliveryApiResponse()),
        ]);

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}&date=2025-01-15"
        );

        $response->assertOk()
            ->assertJsonCount(2, 'slots')
            ->assertJsonPath('slots.0.timeSlot', '10:00-12:00')
            ->assertJsonPath('slots.1.timeSlot', '12:00-14:00')
            ->assertJsonPath('minOrderAmount', 500)
            ->assertJsonPath('freeDeliveryFrom', 1500)
            ->assertJsonPath('deliveryPrice', 200);

        // Проверяем наличие поля date в формате ISO 8601
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $response->json('slots.0.date')
        );
    }

    public function test_get_delivery_slots_default_date(): void
    {
        // Без date берётся сегодняшний день, а FakeDeliveryClient отдаёт слоты с 8:00
        // до 22:00 и отбрасывает прошедшие: вечером слотов не оставалось и тест падал
        $this->travelTo(now()->setTime(10, 0));

        config(['services.delivery_system.url' => 'https://delivery.test']);
        config(['services.delivery_system.api_key' => 'test-key']);

        Http::fake([
            'delivery.test/*' => Http::response($this->fakeDeliveryApiResponse()),
        ]);

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}"
        );

        $response->assertOk()
            ->assertJsonStructure([
                'slots' => [['id', 'timeSlot', 'date']],
                'minOrderAmount',
                'freeDeliveryFrom',
                'deliveryPrice',
            ]);
    }

    public function test_get_delivery_slots_no_coordinates(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => null,
            'longitude' => null,
        ]);

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}"
        );

        $response->assertUnprocessable()
            ->assertJsonPath('errors.addressId.0', 'У адреса отсутствуют корректные координаты.');
    }

    public function test_get_delivery_slots_zero_coordinates(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}"
        );

        $response->assertUnprocessable()
            ->assertJsonPath('errors.addressId.0', 'У адреса отсутствуют корректные координаты.');
    }

    public function test_get_delivery_slots_other_user_address(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}"
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['addressId']);
    }

    public function test_delivery_system_unavailable(): void
    {
        $this->useRealDeliveryClient();

        config(['services.delivery_system.url' => 'https://delivery.test']);
        config(['services.delivery_system.api_key' => 'test-key']);

        Http::fake([
            'delivery.test/*' => Http::response('Server Error', 500),
        ]);

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}"
        );

        $response->assertServiceUnavailable();
    }

    public function test_delivery_zone_not_found(): void
    {
        $this->useRealDeliveryClient();

        config(['services.delivery_system.url' => 'https://delivery.test']);
        config(['services.delivery_system.api_key' => 'test-key']);

        Http::fake([
            'delivery.test/*' => Http::response([
                'error' => 'zone_not_found',
                'message' => 'Доставка по указанным координатам недоступна',
            ], 422),
        ]);

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}"
        );

        $response->assertUnprocessable()
            ->assertJsonPath('error', 'zone_not_found')
            ->assertJsonPath('message', 'Доставка по указанным координатам недоступна');
    }

    public function test_get_delivery_slots_requires_auth(): void
    {
        $store = Store::factory()->create();

        $response = $this->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId=1"
        );

        $response->assertUnauthorized();
    }

    public function test_get_delivery_slots_store_not_found(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson(
            '/api/v1/stores/99999/delivery-slots?addressId='.$address->id
        );

        $response->assertNotFound();
    }

    public function test_get_delivery_slots_address_required(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots"
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['addressId']);
    }

    public function test_get_delivery_slots_invalid_date_format(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $address = Address::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson(
            "/api/v1/stores/{$store->id}/delivery-slots?addressId={$address->id}&date=15-01-2025"
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);
    }
}
