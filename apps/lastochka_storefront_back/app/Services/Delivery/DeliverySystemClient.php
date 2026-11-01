<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\DTOs\Delivery\DeliveryInfoData;
use App\Exceptions\DeliverySystemException;
use App\Exceptions\DeliveryZoneNotFoundException;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class DeliverySystemClient implements DeliveryClientInterface
{
    private ?PendingRequest $http = null;

    private function http(): PendingRequest
    {
        if ($this->http === null) {
            $this->http = Http::baseUrl(config('services.delivery_system.url'))
                ->withToken(config('services.delivery_system.api_key'))
                ->timeout((int) config('services.delivery_system.timeout', 10))
                ->retry(1, 100);
        }

        return $this->http;
    }

    /**
     * Получить слоты доставки.
     *
     * @throws DeliverySystemException
     */
    public function getSlots(string $storeCode, float $latitude, float $longitude, string $date, array $products = []): DeliveryInfoData
    {
        $payload = [
            'code' => $storeCode,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'date' => $date,
        ];

        if ($products !== []) {
            $payload['products'] = $products;
        }

        try {
            $response = $this->http()->post('/api/v1/slots/available', $payload);
        } catch (\Throwable $e) {
            throw new DeliverySystemException(
                'Сервис доставки временно недоступен: '.$e->getMessage()
            );
        }

        if ($response->failed()) {
            $this->handleErrorResponse($response->json());
        }

        return DeliveryInfoData::fromApiResponse($response->json());
    }

    /**
     * Обработка ошибок от API доставки.
     *
     * @throws DeliveryZoneNotFoundException
     * @throws DeliverySystemException
     */
    private function handleErrorResponse(?array $data): never
    {
        $errorCode = $data['error'] ?? null;

        throw match ($errorCode) {
            'zone_not_found' => new DeliveryZoneNotFoundException,
            default => new DeliverySystemException(
                $data['message'] ?? 'Ошибка сервиса доставки'
            ),
        };
    }
}
