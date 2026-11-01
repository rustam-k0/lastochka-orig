<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Delivery;

use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeliveryOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function fake(): FakeSlotsApiClient
    {
        /** @var FakeSlotsApiClient */
        return app(SlotsApiClientInterface::class);
    }

    public function test_returns_own_courier_always_available(): void
    {
        $this->fake()->setShopIntegrationConfigs([]);

        $response = $this->getJson('/api/v1/delivery/options?shopId=1');

        $response->assertOk()
            ->assertJsonPath('data.0.method', 'own_courier')
            ->assertJsonPath('data.0.available', true)
            ->assertJsonPath('data.0.reason', null);
    }

    public function test_cdek_available_when_integration_active(): void
    {
        $this->fake()->setShopIntegrationConfigs([
            ['integration_type' => 'delivery_cdek', 'is_active' => true],
            ['integration_type' => 'delivery_russian_post', 'is_active' => false],
        ]);

        $response = $this->getJson('/api/v1/delivery/options?shopId=1');

        $response->assertOk()
            ->assertJsonPath('data.1.method', 'cdek')
            ->assertJsonPath('data.1.available', true)
            ->assertJsonPath('data.1.reason', null);
    }

    public function test_cdek_not_available_when_integration_inactive(): void
    {
        $this->fake()->setShopIntegrationConfigs([
            ['integration_type' => 'delivery_cdek', 'is_active' => false],
        ]);

        $response = $this->getJson('/api/v1/delivery/options?shopId=1');

        $response->assertOk()
            ->assertJsonPath('data.1.method', 'cdek')
            ->assertJsonPath('data.1.available', false)
            ->assertJsonPath('data.1.reason', 'Магазин не подключил СДЭК');
    }

    public function test_russian_post_available_when_integration_active(): void
    {
        $this->fake()->setShopIntegrationConfigs([
            ['integration_type' => 'delivery_russian_post', 'is_active' => true],
        ]);

        $response = $this->getJson('/api/v1/delivery/options?shopId=1');

        $response->assertOk()
            ->assertJsonPath('data.2.method', 'russian_post')
            ->assertJsonPath('data.2.available', true)
            ->assertJsonPath('data.2.reason', null);
    }

    public function test_russian_post_not_available_when_no_configs(): void
    {
        $this->fake()->setShopIntegrationConfigs([]);

        $response = $this->getJson('/api/v1/delivery/options?shopId=1');

        $response->assertOk()
            ->assertJsonPath('data.2.method', 'russian_post')
            ->assertJsonPath('data.2.available', false);
    }

    public function test_returns_three_options(): void
    {
        $this->fake()->setShopIntegrationConfigs([]);

        $response = $this->getJson('/api/v1/delivery/options?shopId=1');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_degradation_on_client_failure(): void
    {
        $this->fake()->setShouldFail(true);

        $response = $this->getJson('/api/v1/delivery/options?shopId=1');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.available', true);
    }
}
