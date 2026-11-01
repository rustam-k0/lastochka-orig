<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Delivery;

use App\Models\User;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeliveryCalculateTest extends TestCase
{
    use RefreshDatabase;

    private function fake(): FakeSlotsApiClient
    {
        /** @var FakeSlotsApiClient */
        return app(SlotsApiClientInterface::class);
    }

    public function test_calculates_cdek_tariffs(): void
    {
        $user = User::factory()->create();

        $this->fake()->setCdekTariffs([
            ['code' => 'TARIFF_137', 'name' => 'Посылка склад-склад', 'price' => 35000, 'days' => 5],
            ['code' => 'TARIFF_368', 'name' => 'Экономичная посылка', 'price' => 28000, 'days' => 7],
        ]);

        $response = $this->actingAs($user)->getJson(
            '/api/v1/delivery/calculate?shippingMethod=cdek&shopId=1&externalId=MKHCH19'
        );

        $response->assertOk()
            ->assertJsonPath('data.shippingMethod', 'cdek')
            ->assertJsonCount(2, 'data.tariffs');
    }

    public function test_filters_cdek_tariffs_by_tariff_code(): void
    {
        $user = User::factory()->create();

        $this->fake()->setCdekTariffs([
            ['code' => 'TARIFF_137', 'name' => 'Посылка', 'price' => 35000, 'days' => 5],
            ['code' => 'TARIFF_368', 'name' => 'Экономичная', 'price' => 28000, 'days' => 7],
        ]);

        $response = $this->actingAs($user)->getJson(
            '/api/v1/delivery/calculate?shippingMethod=cdek&shopId=1&externalId=MKHCH19&tariffCode=TARIFF_137'
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data.tariffs')
            ->assertJsonPath('data.tariffs.0.code', 'TARIFF_137');
    }

    public function test_calculates_russian_post_tariffs(): void
    {
        $user = User::factory()->create();

        $this->fake()->setRpTariffs([
            ['code' => 'ECOM_STANDARD', 'name' => 'Посылка онлайн стандарт', 'price' => 25000, 'days' => 10],
        ]);

        $response = $this->actingAs($user)->getJson(
            '/api/v1/delivery/calculate?shippingMethod=russian_post&shopId=1&externalId=367000'
        );

        $response->assertOk()
            ->assertJsonPath('data.shippingMethod', 'russian_post')
            ->assertJsonCount(1, 'data.tariffs');
    }

    public function test_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/delivery/calculate?shippingMethod=cdek&shopId=1&externalId=MKHCH19');

        $response->assertUnauthorized();
    }

    public function test_validates_shipping_method(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(
            '/api/v1/delivery/calculate?shippingMethod=yandex&shopId=1'
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['shippingMethod']);
    }

    public function test_requires_shop_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(
            '/api/v1/delivery/calculate?shippingMethod=cdek&externalId=MKHCH19'
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['shopId']);
    }

    public function test_cdek_requires_external_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(
            '/api/v1/delivery/calculate?shippingMethod=cdek&shopId=1'
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['externalId']);
    }

    public function test_own_courier_requires_address_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(
            '/api/v1/delivery/calculate?shippingMethod=own_courier&shopId=1'
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['addressId']);
    }
}
