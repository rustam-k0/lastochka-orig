<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Delivery\Tariffs;

use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeliveryRussianPostTariffsTest extends TestCase
{
    use RefreshDatabase;

    private function fake(): FakeSlotsApiClient
    {
        /** @var FakeSlotsApiClient */
        return app(SlotsApiClientInterface::class);
    }

    public function test_returns_rp_tariffs(): void
    {
        $this->fake()->setRpTariffs([
            ['code' => 'ECOM_STANDARD', 'name' => 'Посылка онлайн стандарт', 'price' => 25000, 'days' => 10],
        ]);

        $response = $this->getJson('/api/v1/delivery/russianpost/tariffs?shopId=1&index=367000');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'ECOM_STANDARD');
    }

    public function test_rp_tariffs_requires_shopId(): void
    {
        $response = $this->getJson('/api/v1/delivery/russianpost/tariffs?index=367000');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['shopId']);
    }

    public function test_rp_tariffs_requires_index(): void
    {
        $response = $this->getJson('/api/v1/delivery/russianpost/tariffs?shopId=1');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['index']);
    }

    public function test_rp_tariffs_index_must_be_6_digits(): void
    {
        $response = $this->getJson('/api/v1/delivery/russianpost/tariffs?shopId=1&index=123');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['index']);
    }

    public function test_rp_tariffs_empty_on_failure(): void
    {
        $this->fake()->setShouldFail(true);

        $response = $this->getJson('/api/v1/delivery/russianpost/tariffs?shopId=1&index=367000');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
