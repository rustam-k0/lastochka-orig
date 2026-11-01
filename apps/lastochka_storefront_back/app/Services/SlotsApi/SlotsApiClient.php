<?php

declare(strict_types=1);

namespace App\Services\SlotsApi;

use App\Services\Pricing\DeliveryPriceLadder;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class SlotsApiClient implements SlotsApiClientInterface
{
    private ?PendingRequest $http = null;

    private function http(): PendingRequest
    {
        if ($this->http === null) {
            $this->http = Http::baseUrl(config('services.slots_api.base_url'))
                ->connectTimeout(5)
                ->timeout((int) config('services.slots_api.timeout', 10))
                ->retry(1, 1000, fn ($exception) => $this->shouldRetry($exception));
        }

        return $this->http;
    }

    /**
     * Получить список магазинов, доступных для доставки по координатам.
     *
     * @return array<int, array{id: int, code: string, delivery_price: int, min_order_price: int, free_delivery_from: int|null, delivery_price_ranges: array<int, array{from_amount: int, price: int}>}>
     */
    public function getAvailableShops(float $latitude, float $longitude): array
    {
        $startTime = microtime(true);

        try {
            $response = $this->http()->get('/api/v1/slots/shops', [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);

            if ($response->failed()) {
                $this->logError(
                    latitude: $latitude,
                    longitude: $longitude,
                    errorMessage: 'API returned error: '.$response->body(),
                    responseCode: $response->status(),
                    durationMs: $this->getDurationMs($startTime)
                );

                return [];
            }

            $data = $response->json('shops', []);

            return array_map(fn (array $shop) => [
                'id' => (int) $shop['id'],
                'code' => (string) $shop['code'],
                'delivery_price' => (int) ($shop['delivery_price'] ?? 0),
                'min_order_price' => (int) ($shop['min_order_price'] ?? 0),
                'free_delivery_from' => isset($shop['free_delivery_from']) ? (int) $shop['free_delivery_from'] : null,
                // Ступени стоимости доставки зоны (MID-280); нет ключа или битые → []
                'delivery_price_ranges' => DeliveryPriceLadder::normalize($shop['delivery_price_ranges'] ?? null) ?? [],
            ], $data);
        } catch (\Throwable $e) {
            $this->logError(
                latitude: $latitude,
                longitude: $longitude,
                errorMessage: $e->getMessage(),
                responseCode: 0,
                durationMs: $this->getDurationMs($startTime)
            );

            return [];
        }
    }

    /**
     * Получить список ПВЗ CDEK по параметрам bbox/поиска.
     *
     * @param  array{bbox?: string, q?: string|null, limit?: int|null, type?: string|null}  $params
     * @return array<int, array{provider: string, external_id: string, code: string, title: string, address: string, city: string, postalCode: string, lat: float, lng: float, workTime: string, phone: string, type: string}>
     */
    public function getCdekPvz(array $params): array
    {
        try {
            $response = $this->http()->get('/api/v1/slots/cdek-pvz', array_filter([
                'bbox'  => $params['bbox'] ?? null,
                'q'     => $params['q'] ?? null,
                'limit' => $params['limit'] ?? null,
                'type'  => $params['type'] ?? 'PVZ',
            ], fn ($v) => $v !== null && $v !== ''));

            if ($response->failed()) {
                Log::warning('SlotsApi getCdekPvz failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return [];
            }

            return $response->json('data', []);
        } catch (\Throwable $e) {
            Log::warning('SlotsApi getCdekPvz exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Получить одну точку CDEK по коду.
     *
     * @return array{provider: string, external_id: string, code: string, title: string, address: string, city: string, postalCode: string, lat: float, lng: float, workTime: string, phone: string, type: string}|null
     */
    public function getCdekPickupByCode(string $code): ?array
    {
        try {
            $response = $this->http()->get('/api/v1/slots/cdek-pvz/'.$code);

            if ($response->status() === 404) {
                return null;
            }

            if ($response->failed()) {
                Log::warning('SlotsApi getCdekPickupByCode failed', [
                    'code'   => $code,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return null;
            }

            return $response->json('data');
        } catch (\Throwable $e) {
            Log::warning('SlotsApi getCdekPickupByCode exception', ['code' => $code, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Получить список отделений Почты России.
     *
     * @param  array{bbox?: string, q?: string|null, limit?: int|null, opsType?: string|null}  $params
     * @return array<int, array{provider: string, external_id: string, code: string, title: string, address: string, city: string, postalCode: string, lat: float, lng: float, workTime: string, phone: string, type: string}>
     */
    public function getRpOffices(array $params): array
    {
        try {
            $response = $this->http()->get('/api/v1/slots/rp-offices', array_filter([
                'bbox'    => $params['bbox'] ?? null,
                'q'       => $params['q'] ?? null,
                'limit'   => $params['limit'] ?? null,
                'opsType' => $params['opsType'] ?? 'ГОПС',
            ], fn ($v) => $v !== null && $v !== ''));

            if ($response->failed()) {
                Log::warning('SlotsApi getRpOffices failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return [];
            }

            return $response->json('data', []);
        } catch (\Throwable $e) {
            Log::warning('SlotsApi getRpOffices exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Получить одно отделение Почты России по индексу.
     *
     * @return array{provider: string, external_id: string, code: string, title: string, address: string, city: string, postalCode: string, lat: float, lng: float, workTime: string, phone: string, type: string}|null
     */
    public function getRpOfficeByIndex(string $index): ?array
    {
        try {
            $response = $this->http()->get('/api/v1/slots/rp-offices/'.$index);

            if ($response->status() === 404) {
                return null;
            }

            if ($response->failed()) {
                Log::warning('SlotsApi getRpOfficeByIndex failed', [
                    'index'  => $index,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return null;
            }

            return $response->json('data');
        } catch (\Throwable $e) {
            Log::warning('SlotsApi getRpOfficeByIndex exception', ['index' => $index, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Получить список городов для самовывоза.
     *
     * @return array<int, array{name: string, slug: string, region: string, zip: string, lat: float, lng: float}>
     */
    public function getPickupCities(?string $q, ?int $limit = null): array
    {
        try {
            $response = $this->http()->get('/api/v1/slots/pickup-cities', array_filter([
                'q'     => $q,
                'limit' => $limit,
            ], fn ($v) => $v !== null && $v !== ''));

            if ($response->failed()) {
                Log::warning('SlotsApi getPickupCities failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return [];
            }

            return $response->json('data', []);
        } catch (\Throwable $e) {
            Log::warning('SlotsApi getPickupCities exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Получить тарифы CDEK для доставки.
     *
     * @param  array{code?: string, city?: string, shopId: int, weight?: int|null, dimensions?: string|null, declaredValue?: int|null}  $params
     * @return array<int, mixed>
     */
    public function getCdekTariffs(array $params): array
    {
        try {
            $response = $this->http()->get('/api/v1/slots/delivery/cdek/tariffs', array_filter([
                'code'          => $params['code'] ?? null,
                'city'          => $params['city'] ?? null,
                'shopId'        => $params['shopId'] ?? null,
                'weight'        => $params['weight'] ?? null,
                'dimensions'    => $params['dimensions'] ?? null,
                'declaredValue' => $params['declaredValue'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''));

            if ($response->failed()) {
                Log::warning('SlotsApi getCdekTariffs failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return [];
            }

            return $response->json('data', []);
        } catch (\Throwable $e) {
            Log::warning('SlotsApi getCdekTariffs exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Получить тарифы Почты России для доставки.
     *
     * @param  array{index?: string, weight?: int|null, shopId: int}  $params
     * @return array<int, mixed>
     */
    public function getRpTariffs(array $params): array
    {
        try {
            $response = $this->http()->get('/api/v1/slots/delivery/rp/tariffs', array_filter([
                'index'  => $params['index'] ?? null,
                'weight' => $params['weight'] ?? null,
                'shopId' => $params['shopId'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''));

            if ($response->failed()) {
                Log::warning('SlotsApi getRpTariffs failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return [];
            }

            return $response->json('data', []);
        } catch (\Throwable $e) {
            Log::warning('SlotsApi getRpTariffs exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Получить конфигурации интеграций магазина.
     *
     * @param  array<int, string>  $types
     * @return array<int, array{integration_type: string, is_active: bool}>
     */
    public function getShopIntegrationConfigs(int $shopId, array $types): array
    {
        try {
            $query = ['types' => $types];
            $response = $this->http()->get('/api/v1/slots/integration-configs/'.$shopId, $query);

            if ($response->failed()) {
                Log::warning('SlotsApi getShopIntegrationConfigs failed', [
                    'shopId' => $shopId,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return [];
            }

            return $response->json('data', []);
        } catch (\Throwable $e) {
            Log::warning('SlotsApi getShopIntegrationConfigs exception', ['shopId' => $shopId, 'error' => $e->getMessage()]);

            return [];
        }
    }

    private function shouldRetry(\Throwable $exception): bool
    {
        if ($exception instanceof \Illuminate\Http\Client\RequestException) {
            $statusCode = $exception->response?->status() ?? 0;

            return $statusCode >= 500 || $statusCode === 0;
        }

        return $exception instanceof \Illuminate\Http\Client\ConnectionException;
    }

    private function logError(
        float $latitude,
        float $longitude,
        string $errorMessage,
        int $responseCode,
        float $durationMs
    ): void {
        Log::warning('SlotsApi request failed', [
            'coordinates' => ['lat' => $latitude, 'lng' => $longitude],
            'error_message' => $errorMessage,
            'response_code' => $responseCode,
            'duration_ms' => round($durationMs, 2),
        ]);
    }

    private function getDurationMs(float $startTime): float
    {
        return (microtime(true) - $startTime) * 1000;
    }
}
