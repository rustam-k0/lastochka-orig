<?php

namespace App\Services\YandexDelivery;

use App\DTOs\Delivery\YandexDeliveryMethodsData;
use App\DTOs\Delivery\YandexPriceData;
use App\Exceptions\DeliveryZoneNotFoundException;
use App\Exceptions\YandexDeliveryException;
use App\Services\IntegrationConfigService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YandexGoClient implements YandexGoClientInterface
{
    public function getDeliveryMethods(array $startPoint, array $endPoint): YandexDeliveryMethodsData
    {
        // Нулевые/пустые координаты (магазин без координат, напр. синк из RetailCRM)
        // не имеют смысла для Яндекса — не дёргаем API, доставка недоступна.
        if (! $this->coordinatesValid($startPoint) || ! $this->coordinatesValid($endPoint)) {
            return YandexDeliveryMethodsData::unavailable();
        }

        $cacheKey = sprintf('yandex_methods:%s:%s', implode(',', $startPoint), implode(',', $endPoint));

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        try {
            $response = $this->http()->post('/b2b/cargo/integration/v2/delivery-methods', [
                'start_point' => $startPoint,
                'end_point' => $endPoint,
            ]);

            if ($response->failed()) {
                Log::warning('Yandex delivery-methods failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return YandexDeliveryMethodsData::unavailable();
            }

            $data = YandexDeliveryMethodsData::fromApiResponse($response->json());
            Cache::put($cacheKey, $data, config('yandex-delivery.delivery_methods_cache_ttl', 1800));

            return $data;
        } catch (\Throwable $e) {
            Log::error('Yandex delivery-methods exception', ['error' => $e->getMessage()]);
            return YandexDeliveryMethodsData::unavailable();
        }
    }

    public function checkPrice(array $startPoint, array $endPoint, ?bool $skipDoorToDoor = null): YandexPriceData
    {
        // Без валидных координат (напр. магазин с latitude=0/longitude=0) Яндекс
        // вернёт estimating.claim.no_zone_id — не дёргаем API зря, сразу отдаём
        // доменное исключение (рендерится как 422, а не 500). ALADDIN-11465.
        if (! $this->coordinatesValid($startPoint) || ! $this->coordinatesValid($endPoint)) {
            throw new DeliveryZoneNotFoundException();
        }

        // null = режим из конфига (обратная совместимость). Адаптивно режим задаёт
        // вызывающий код по адресу (есть дом → «до двери», нет → «от подъезда»),
        // чтобы оценка (check-price) и заявка (claim) считали один тариф.
        $skipDoorToDoor ??= (bool) config('yandex-delivery.skip_door_to_door', true);

        // Режим заноса влияет на цену — кэшируем отдельно «до двери» и «от подъезда».
        $cacheKey = sprintf(
            'yandex_price:%s:%s:%s',
            implode(',', $startPoint),
            implode(',', $endPoint),
            $skipDoorToDoor ? 'curb' : 'door',
        );

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $response = $this->http()->post('/b2b/cargo/integration/v2/check-price', [
            'items' => [[
                'quantity' => 1,
                'size' => config('yandex-delivery.default_item_size'),
                'weight' => config('yandex-delivery.default_item_weight', 5),
            ]],
            'route_points' => [
                ['coordinates' => $startPoint, 'fullname' => 'Магазин'],
                ['coordinates' => $endPoint, 'fullname' => 'Покупатель'],
            ],
            'requirements' => [
                'taxi_class' => config('yandex-delivery.taxi_class', 'express'),
                'cargo_options' => config('yandex-delivery.cargo_options', []),
            ],
            'skip_door_to_door' => $skipDoorToDoor,
        ]);

        if ($response->failed()) {
            Log::error('Yandex check-price failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            // Яндекс не нашёл тарифную зону для маршрута (точка вне зоны
            // обслуживания либо нулевые координаты) — это не серверная ошибка,
            // а недоступность доставки по адресу. Отдаём 422, а не 500.
            if ($this->isNoZoneError($response)) {
                throw new DeliveryZoneNotFoundException();
            }

            throw new YandexDeliveryException('Yandex check-price failed: ' . $response->body());
        }

        $data = YandexPriceData::fromApiResponse($response->json());
        Cache::put($cacheKey, $data, config('yandex-delivery.check_price_cache_ttl', 300));

        return $data;
    }

    public function createClaim(array $payload, string $requestId): array
    {
        $response = $this->http()->post(
            '/b2b/cargo/integration/v2/claims/create?request_id=' . urlencode($requestId),
            $payload,
        );

        if ($response->status() === 409) {
            Log::warning('Yandex claim duplicate', ['request_id' => $requestId]);
            return $response->json();
        }

        if ($response->failed()) {
            Log::error('Yandex claims/create failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'request_id' => $requestId,
            ]);
            throw new \RuntimeException('Yandex claims/create failed: ' . $response->body());
        }

        return $response->json();
    }

    public function acceptClaim(string $claimId, int $version): array
    {
        $response = $this->http()->post(
            '/b2b/cargo/integration/v2/claims/accept?claim_id=' . urlencode($claimId),
            ['version' => $version],
        );

        if ($response->failed()) {
            Log::error('Yandex claims/accept failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'claim_id' => $claimId,
            ]);
            throw new \RuntimeException('Yandex claims/accept failed: ' . $response->body());
        }

        return $response->json();
    }

    public function cancelClaim(string $claimId, string $cancelState = 'free', int $version = 1): array
    {
        $response = $this->http()->post(
            '/b2b/cargo/integration/v2/claims/cancel?claim_id=' . urlencode($claimId),
            ['cancel_state' => $cancelState, 'version' => $version],
        );

        if ($response->failed()) {
            Log::error('Yandex claims/cancel failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'claim_id' => $claimId,
            ]);
            throw new \RuntimeException('Yandex claims/cancel failed: ' . $response->body());
        }

        return $response->json();
    }

    public function getClaimInfo(string $claimId): array
    {
        $response = $this->http()->post(
            '/b2b/cargo/integration/v2/claims/info?claim_id=' . urlencode($claimId),
            [],
        );

        if ($response->failed()) {
            throw new \RuntimeException('Yandex claims/info failed: ' . $response->body());
        }

        return $response->json();
    }

    protected function http(): PendingRequest
    {
        $token = $this->resolveToken();

        return Http::baseUrl(config('yandex-delivery.base_url'))
            ->withToken($token)
            ->timeout(config('yandex-delivery.timeout', 10))
            ->retry(
                config('yandex-delivery.retry_times', 2),
                fn (int $attempt) => $attempt * 1000,
                fn (\Throwable $e) => $e instanceof \Illuminate\Http\Client\RequestException && $e->response->status() >= 500,
                throw: false,
            )
            ->withHeaders(['Accept-Language' => 'ru']);
    }

    /**
     * Режим доставки Яндекса по адресу: «до двери» (skip=false) возможна только
     * когда известен номер дома — Яндексу есть куда заносить заказ. Без дома
     * (только улица/координаты) — «от подъезда» (skip=true, расчёт по координатам).
     * Один критерий для оценки (check-price) и заявки (claim), чтобы цены совпадали.
     */
    public static function shouldSkipDoorToDoor(?string $houseNumber): bool
    {
        return blank($houseNumber);
    }

    /**
     * Координаты считаются валидными, если переданы обе оси и точка не [0, 0].
     * Магазин без координат (часто после синка из RetailCRM-бриджа, где нет
     * полей широты/долготы) приходит как [0, 0] — для Яндекса это бессмысленная
     * точка в океане, вызов API заведомо вернёт no_zone_id.
     *
     * @param  array<int, float|int|string|null>  $point  [longitude, latitude]
     */
    protected function coordinatesValid(array $point): bool
    {
        if (! isset($point[0], $point[1])) {
            return false;
        }

        $lon = (float) $point[0];
        $lat = (float) $point[1];

        return ! ($lon === 0.0 && $lat === 0.0);
    }

    /**
     * Ответ Яндекса об отсутствии тарифной зоны для маршрута (HTTP 409
     * estimating.claim.no_zone_id) — доставка по адресу недоступна.
     */
    protected function isNoZoneError(Response $response): bool
    {
        return $response->status() === 409
            && str_contains((string) $response->body(), 'no_zone_id');
    }

    protected function resolveToken(): string
    {
        $credentials = IntegrationConfigService::getCredentials('delivery');
        if ($credentials && !empty($credentials['oauth_token'])) {
            return $credentials['oauth_token'];
        }

        $envToken = config('yandex-delivery.oauth_token');
        if ($envToken) {
            return $envToken;
        }

        throw new \RuntimeException('Yandex Delivery OAuth token not configured');
    }
}
