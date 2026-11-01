<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Delivery\Tariffs;

use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeliveryCdekTariffsTest extends TestCase
{
    use RefreshDatabase;

    private function fake(): FakeSlotsApiClient
    {
        /** @var FakeSlotsApiClient */
        return app(SlotsApiClientInterface::class);
    }

    public function test_returns_cdek_tariffs_by_code(): void
    {
        $this->fake()->setCdekTariffs([
            ['code' => 'TARIFF_137', 'name' => 'Посылка склад-склад', 'price' => 35000, 'days' => 5],
            ['code' => 'TARIFF_368', 'name' => 'Экономичная посылка', 'price' => 28000, 'days' => 7],
        ]);

        $response = $this->getJson('/api/v1/delivery/cdek/tariffs?shopId=1&code=MKHCH19');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'TARIFF_137');
    }

    public function test_returns_cdek_tariffs_by_city(): void
    {
        $this->fake()->setCdekTariffs([
            ['code' => 'TARIFF_137', 'name' => 'Посылка', 'price' => 40000, 'days' => 6],
        ]);

        $response = $this->getJson('/api/v1/delivery/cdek/tariffs?shopId=1&city=Москва&weight=500');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_cdek_tariffs_requires_shopId(): void
    {
        $response = $this->getJson('/api/v1/delivery/cdek/tariffs?code=MKHCH19');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['shopId']);
    }

    public function test_cdek_tariffs_requires_code_or_city(): void
    {
        $response = $this->getJson('/api/v1/delivery/cdek/tariffs?shopId=1');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_cdek_tariffs_empty_on_failure(): void
    {
        $this->fake()->setShouldFail(true);

        $response = $this->getJson('/api/v1/delivery/cdek/tariffs?shopId=1&code=MKHCH19');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
