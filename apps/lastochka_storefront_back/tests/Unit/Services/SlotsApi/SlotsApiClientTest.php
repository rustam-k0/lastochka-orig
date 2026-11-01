<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SlotsApi;

use App\Services\SlotsApi\SlotsApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class SlotsApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.slots_api.base_url' => 'https://api.slots.test']);
        config(['services.slots_api.timeout' => 5]);
    }

    /**
     * Создать клиент ПОСЛЕ Http::fake(), чтобы PendingRequest поймал активный fake-factory.
     * SlotsApiClient кеширует PendingRequest при первом вызове http() — поэтому клиент
     * должен создаваться уже после вызова Http::fake().
     */
    private function makeClient(): SlotsApiClient
    {
        return new SlotsApiClient;
    }

    public function test_get_available_shops_parses_response_correctly(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/shops*' => Http::response([
                'shops' => [
                    [
                        'id'              => 1,
                        'code'            => 'shop-1',
                        'title'           => 'Магазин 1',
                        'address'         => 'ул. Арбат, 10',
                        'latitude'        => '55.7520',
                        'longitude'       => '37.5910',
                        'logo_img'        => 'https://cdn.example.com/logo.png',
                        'delivery_price'  => 200,
                        'min_order_price' => 500,
                    ],
                    [
                        'id'              => 2,
                        'code'            => 'shop-2',
                        'title'           => 'Магазин 2',
                        'address'         => 'ул. Тверская, 5',
                        'latitude'        => '55.7650',
                        'longitude'       => '37.6050',
                        'logo_img'        => null,
                        'delivery_price'  => 150,
                        'min_order_price' => 300,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->makeClient()->getAvailableShops(55.7558, 37.6173);

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals(200, $result[0]['delivery_price']);
        $this->assertEquals(500, $result[0]['min_order_price']);
        $this->assertEquals(2, $result[1]['id']);
        $this->assertEquals(150, $result[1]['delivery_price']);
        $this->assertEquals(300, $result[1]['min_order_price']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.slots.test/api/v1/slots/shops?latitude=55.7558&longitude=37.6173';
        });
    }

    public function test_get_available_shops_returns_empty_array_on_500_error(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/shops*' => Http::response('Internal Server Error', 500),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'SlotsApi request failed'
                    && $context['response_code'] === 500
                    && isset($context['duration_ms']);
            });

        $result = $this->makeClient()->getAvailableShops(55.7558, 37.6173);

        $this->assertEquals([], $result);
    }

    public function test_get_available_shops_returns_empty_array_on_connection_error(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/shops*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'SlotsApi request failed'
                    && str_contains($context['error_message'], 'Connection refused');
            });

        $result = $this->makeClient()->getAvailableShops(55.7558, 37.6173);

        $this->assertEquals([], $result);
    }

    public function test_get_available_shops_returns_empty_array_on_4xx_error(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/shops*' => Http::response(['error' => 'Bad Request'], 400),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'SlotsApi request failed'
                    && $context['response_code'] === 400;
            });

        $result = $this->makeClient()->getAvailableShops(55.7558, 37.6173);

        $this->assertEquals([], $result);
    }

    public function test_get_available_shops_handles_empty_shops_array(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/shops*' => Http::response(['shops' => []], 200),
        ]);

        $result = $this->makeClient()->getAvailableShops(55.7558, 37.6173);

        $this->assertEquals([], $result);
    }

    public function test_get_available_shops_handles_missing_optional_fields(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/shops*' => Http::response([
                'shops' => [
                    [
                        'id'   => 1,
                        'code' => 'shop-1',
                        // delivery_price, min_order_price, free_delivery_from отсутствуют — должны дать 0/null
                    ],
                ],
            ], 200),
        ]);

        $result = $this->makeClient()->getAvailableShops(55.7558, 37.6173);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals(0, $result[0]['delivery_price']);
        $this->assertEquals(0, $result[0]['min_order_price']);
        $this->assertNull($result[0]['free_delivery_from']);
    }

    // -----------------------------------------------------------------------
    // getCdekPvz
    // -----------------------------------------------------------------------

    public function test_get_cdek_pvz_returns_data_on_success(): void
    {
        $pvzData = [
            [
                'provider'    => 'cdek',
                'external_id' => 'MSK001',
                'code'        => 'MSK001',
                'title'       => 'ПВЗ Москва Центр',
                'address'     => 'ул. Тверская, 1',
                'city'        => 'Москва',
                'postalCode'  => '125009',
                'lat'         => 55.755864,
                'lng'         => 37.617698,
                'workTime'    => 'Пн-Пт 9:00-20:00',
                'phone'       => '+74951234567',
                'type'        => 'PVZ',
            ],
        ];

        Http::fake([
            'api.slots.test/api/v1/slots/cdek-pvz*' => Http::response(['data' => $pvzData], 200),
        ]);

        $result = $this->makeClient()->getCdekPvz(['bbox' => '37.5,55.7,37.7,55.8', 'type' => 'PVZ']);

        $this->assertCount(1, $result);
        $this->assertEquals('MSK001', $result[0]['code']);
        $this->assertEquals('cdek', $result[0]['provider']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/slots/cdek-pvz');
        });
    }

    public function test_get_cdek_pvz_returns_empty_array_on_error(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/cdek-pvz*' => Http::response('Server Error', 500),
        ]);

        Log::shouldReceive('warning')->once();

        $result = $this->makeClient()->getCdekPvz(['bbox' => '37.5,55.7,37.7,55.8']);

        $this->assertEquals([], $result);
    }

    // -----------------------------------------------------------------------
    // getCdekPickupByCode
    // -----------------------------------------------------------------------

    public function test_get_cdek_pickup_by_code_returns_null_on_404(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/cdek-pvz/UNKNOWN' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $result = $this->makeClient()->getCdekPickupByCode('UNKNOWN');

        $this->assertNull($result);
    }

    public function test_get_cdek_pickup_by_code_returns_data_on_success(): void
    {
        $pvzItem = [
            'provider'    => 'cdek',
            'external_id' => 'MSK001',
            'code'        => 'MSK001',
            'title'       => 'ПВЗ Москва Центр',
            'address'     => 'ул. Тверская, 1',
            'city'        => 'Москва',
            'postalCode'  => '125009',
            'lat'         => 55.755864,
            'lng'         => 37.617698,
            'workTime'    => 'Пн-Пт 9:00-20:00',
            'phone'       => '+74951234567',
            'type'        => 'PVZ',
        ];

        Http::fake([
            'api.slots.test/api/v1/slots/cdek-pvz/MSK001' => Http::response(['data' => $pvzItem], 200),
        ]);

        $result = $this->makeClient()->getCdekPickupByCode('MSK001');

        $this->assertNotNull($result);
        $this->assertEquals('MSK001', $result['code']);
    }

    // -----------------------------------------------------------------------
    // getRpOffices
    // -----------------------------------------------------------------------

    public function test_get_rp_offices_returns_data_on_success(): void
    {
        $officesData = [
            [
                'provider'    => 'russian_post',
                'external_id' => '101000',
                'code'        => '101000',
                'title'       => 'ГОПС Москва Центр',
                'address'     => 'ул. Мясницкая, 26',
                'city'        => 'Москва',
                'postalCode'  => '101000',
                'lat'         => 55.763283,
                'lng'         => 37.633929,
                'workTime'    => 'Пн-Пт 8:00-20:00',
                'phone'       => '+74951234567',
                'type'        => 'ГОПС',
            ],
        ];

        Http::fake([
            'api.slots.test/api/v1/slots/rp-offices*' => Http::response(['data' => $officesData], 200),
        ]);

        $result = $this->makeClient()->getRpOffices(['bbox' => '37.5,55.7,37.7,55.8', 'opsType' => 'ГОПС']);

        $this->assertCount(1, $result);
        $this->assertEquals('101000', $result[0]['code']);
        $this->assertEquals('russian_post', $result[0]['provider']);
    }

    public function test_get_rp_offices_returns_empty_array_on_error(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/rp-offices*' => Http::response('Not Found', 404),
        ]);

        Log::shouldReceive('warning')->once();

        $result = $this->makeClient()->getRpOffices(['bbox' => '37.5,55.7,37.7,55.8']);

        $this->assertEquals([], $result);
    }

    // -----------------------------------------------------------------------
    // getPickupCities
    // -----------------------------------------------------------------------

    public function test_get_pickup_cities_returns_data_on_success(): void
    {
        $citiesData = [
            ['name' => 'Москва', 'slug' => 'moscow', 'region' => 'Московская обл.', 'zip' => '101000', 'lat' => 55.7558, 'lng' => 37.6173],
            ['name' => 'Санкт-Петербург', 'slug' => 'spb', 'region' => 'Ленинградская обл.', 'zip' => '190000', 'lat' => 59.9343, 'lng' => 30.3351],
        ];

        Http::fake([
            'api.slots.test/api/v1/slots/pickup-cities*' => Http::response(['data' => $citiesData], 200),
        ]);

        $result = $this->makeClient()->getPickupCities('Мос', 10);

        $this->assertCount(2, $result);
        $this->assertEquals('moscow', $result[0]['slug']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/slots/pickup-cities');
        });
    }

    public function test_get_pickup_cities_returns_empty_array_on_error(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/pickup-cities*' => Http::response('Internal Server Error', 500),
        ]);

        Log::shouldReceive('warning')->once();

        $result = $this->makeClient()->getPickupCities(null);

        $this->assertEquals([], $result);
    }

    // -----------------------------------------------------------------------
    // getCdekTariffs
    // -----------------------------------------------------------------------

    public function test_get_cdek_tariffs_returns_data_on_success(): void
    {
        $tariffsData = [
            ['tariff_code' => 136, 'tariff_name' => 'Посылка склад-склад', 'delivery_sum' => 350, 'period_min' => 2, 'period_max' => 4],
            ['tariff_code' => 137, 'tariff_name' => 'Посылка склад-дверь', 'delivery_sum' => 500, 'period_min' => 2, 'period_max' => 5],
        ];

        Http::fake([
            'api.slots.test/api/v1/slots/delivery/cdek/tariffs*' => Http::response(['data' => $tariffsData], 200),
        ]);

        $result = $this->makeClient()->getCdekTariffs(['code' => 'MSK001', 'shopId' => 1, 'weight' => 1000]);

        $this->assertCount(2, $result);
        $this->assertEquals(136, $result[0]['tariff_code']);
        $this->assertEquals(350, $result[0]['delivery_sum']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/slots/delivery/cdek/tariffs');
        });
    }

    public function test_get_cdek_tariffs_returns_empty_array_on_error(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/delivery/cdek/tariffs*' => Http::response('Bad Request', 400),
        ]);

        Log::shouldReceive('warning')->once();

        $result = $this->makeClient()->getCdekTariffs(['shopId' => 1]);

        $this->assertEquals([], $result);
    }

    // -----------------------------------------------------------------------
    // getRpTariffs
    // -----------------------------------------------------------------------

    public function test_get_rp_tariffs_returns_data_on_success(): void
    {
        $tariffsData = [
            ['service' => 'Посылка', 'price' => 280, 'days_min' => 3, 'days_max' => 7],
        ];

        Http::fake([
            'api.slots.test/api/v1/slots/delivery/rp/tariffs*' => Http::response(['data' => $tariffsData], 200),
        ]);

        $result = $this->makeClient()->getRpTariffs(['index' => '101000', 'weight' => 500, 'shopId' => 1]);

        $this->assertCount(1, $result);
        $this->assertEquals(280, $result[0]['price']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/slots/delivery/rp/tariffs');
        });
    }

    public function test_get_rp_tariffs_returns_empty_array_on_error(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/delivery/rp/tariffs*' => Http::response('Internal Server Error', 500),
        ]);

        Log::shouldReceive('warning')->once();

        $result = $this->makeClient()->getRpTariffs(['shopId' => 1]);

        $this->assertEquals([], $result);
    }

    // -----------------------------------------------------------------------
    // getShopIntegrationConfigs
    // -----------------------------------------------------------------------

    public function test_get_shop_integration_configs_returns_data_on_success(): void
    {
        $configsData = [
            ['integration_type' => 'delivery_cdek', 'is_active' => true],
            ['integration_type' => 'delivery_russian_post', 'is_active' => false],
        ];

        Http::fake([
            'api.slots.test/api/v1/slots/integration-configs/1*' => Http::response(['data' => $configsData], 200),
        ]);

        $result = $this->makeClient()->getShopIntegrationConfigs(1, ['delivery_cdek', 'delivery_russian_post']);

        $this->assertCount(2, $result);
        $this->assertEquals('delivery_cdek', $result[0]['integration_type']);
        $this->assertTrue($result[0]['is_active']);
        $this->assertEquals('delivery_russian_post', $result[1]['integration_type']);
        $this->assertFalse($result[1]['is_active']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/slots/integration-configs/1');
        });
    }

    public function test_get_shop_integration_configs_returns_empty_array_on_error(): void
    {
        Http::fake([
            'api.slots.test/api/v1/slots/integration-configs/99*' => Http::response('Not Found', 404),
        ]);

        Log::shouldReceive('warning')->once();

        $result = $this->makeClient()->getShopIntegrationConfigs(99, ['delivery_cdek']);

        $this->assertEquals([], $result);
    }
}
