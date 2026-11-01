<?php

declare(strict_types=1);

namespace Tests\Feature\YandexDelivery;

use App\DTOs\Delivery\YandexDeliveryMethodsData;
use App\Exceptions\DeliveryZoneNotFoundException;
use App\Exceptions\YandexDeliveryException;
use App\Models\IntegrationConfig;
use App\Services\YandexDelivery\YandexGoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        IntegrationConfig::create([
            'integration_type' => 'delivery',
            'driver' => 'yandex_go',
            'credentials' => ['oauth_token' => 'test_token'],
            'is_active' => true,
        ]);
    }

    /**
     * Замокать успешный ответ check-price.
     *
     * Why: ставим fake внутри теста, а не в setUp — повторный Http::fake()
     * мержит стабы, и при совпадении URL выигрывает первый зарегистрированный,
     * из-за чего setUp-стаб перебивал бы 4xx/5xx-фейки отдельных тестов.
     */
    private function fakeSuccessfulCheckPrice(): void
    {
        Http::fake([
            '*/check-price' => Http::response([
                'price' => '500.0',
                'eta' => 15.0,
                'currency_rules' => ['code' => 'RUB'],
                'distance_meters' => 3000,
                'zone_id' => 'moscow',
            ], 200),
        ]);
    }

    public function test_check_price_sends_skip_door_to_door_true_by_default(): void
    {
        $this->fakeSuccessfulCheckPrice();

        $result = (new YandexGoClient())->checkPrice([37.6, 55.7], [37.5, 55.6]);

        $this->assertSame(500.0, $result->price);
        $this->assertSame('moscow', $result->zoneId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/check-price')
                && $request['skip_door_to_door'] === true
                && $request['requirements']['taxi_class'] === 'express';
        });
    }

    public function test_check_price_respects_skip_door_to_door_config_override(): void
    {
        $this->fakeSuccessfulCheckPrice();

        config(['yandex-delivery.skip_door_to_door' => false]);

        (new YandexGoClient())->checkPrice([37.6, 55.7], [37.5, 55.6]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/check-price')
            && $request['skip_door_to_door'] === false);
    }

    public function test_check_price_throws_zone_not_found_on_yandex_no_zone_id(): void
    {
        // Яндекс возвращает 409 estimating.claim.no_zone_id, когда точка вне зоны
        // обслуживания. Раньше это пролетало как RequestException → 500 при
        // оформлении заказа (ALADDIN-11465). Теперь — доменное 422.
        Http::fake([
            '*/check-price' => Http::response([
                'code' => 'estimating.claim.no_zone_id',
                'message' => 'estimating.claim.no_zone_id',
            ], 409),
        ]);

        $this->expectException(DeliveryZoneNotFoundException::class);

        (new YandexGoClient())->checkPrice([37.6, 55.7], [37.5, 55.6]);
    }

    public function test_check_price_throws_yandex_exception_on_other_errors(): void
    {
        // Прочие ошибки Яндекса (5xx, иные 4xx) — graceful 422, а не сырой 500.
        Http::fake([
            '*/check-price' => Http::response(['code' => 'some_other_error'], 400),
        ]);

        $this->expectException(YandexDeliveryException::class);

        (new YandexGoClient())->checkPrice([37.6, 55.7], [37.5, 55.6]);
    }

    public function test_check_price_throws_zone_not_found_on_zero_coordinates_without_api_call(): void
    {
        // Магазин с latitude=0/longitude=0 — не дёргаем Яндекс зря, сразу 422.
        Http::fake();

        $this->expectException(DeliveryZoneNotFoundException::class);

        try {
            (new YandexGoClient())->checkPrice([0, 0], [37.5, 55.6]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_delivery_methods_unavailable_on_zero_coordinates_without_api_call(): void
    {
        Http::fake();

        $result = (new YandexGoClient())->getDeliveryMethods([0, 0], [37.5, 55.6]);

        $this->assertInstanceOf(YandexDeliveryMethodsData::class, $result);
        $this->assertFalse($result->expressAllowed);
        Http::assertNothingSent();
    }
}
