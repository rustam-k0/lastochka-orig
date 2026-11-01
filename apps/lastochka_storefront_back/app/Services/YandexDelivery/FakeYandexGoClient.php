<?php

namespace App\Services\YandexDelivery;

use App\DTOs\Delivery\YandexDeliveryMethodsData;
use App\DTOs\Delivery\YandexPriceData;

class FakeYandexGoClient implements YandexGoClientInterface
{
    public bool $expressAllowed = true;
    public float $price = 507.52;
    public float $eta = 16.5;
    public bool $shouldFail = false;
    public array $createdClaims = [];
    public array $acceptedClaims = [];
    public array $cancelledClaims = [];

    /**
     * Статус заявки, который вернёт getClaimInfo() для конкретного claim_id.
     *
     * Вебхук-контроллер верифицирует присланный статус через getClaimInfo и
     * при расхождении верит API, а не вебхуку. Без переопределения фейк
     * отвечает 'accepted' на любую заявку и глушит проверяемый переход.
     *
     * @var array<string, string>
     */
    public array $claimStatuses = [];

    public function getDeliveryMethods(array $startPoint, array $endPoint): YandexDeliveryMethodsData
    {
        if ($this->shouldFail) {
            return YandexDeliveryMethodsData::unavailable();
        }
        return new YandexDeliveryMethodsData(expressAllowed: $this->expressAllowed);
    }

    public function checkPrice(array $startPoint, array $endPoint, ?bool $skipDoorToDoor = null): YandexPriceData
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('Yandex API unavailable');
        }
        return new YandexPriceData(
            price: $this->price,
            eta: $this->eta,
            currency: 'RUB',
            distanceMeters: 3457.2,
            zoneId: 'test_zone',
        );
    }

    public function createClaim(array $payload, string $requestId): array
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('Yandex claims/create failed');
        }
        $claimId = 'fake_claim_' . $requestId;
        $this->createdClaims[] = ['claim_id' => $claimId, 'payload' => $payload];
        return [
            'id' => $claimId,
            'status' => 'ready_for_approval',
            'version' => 1,
            'pricing' => [
                'offer' => [
                    'offer_id' => 'fake_offer_id',
                    'price' => (string) $this->price,
                    'price_raw' => (int) $this->price,
                    'valid_until' => now()->addMinutes(10)->toIso8601String(),
                ],
                'currency' => 'RUB',
            ],
        ];
    }

    public function acceptClaim(string $claimId, int $version): array
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('Yandex claims/accept failed');
        }
        $this->acceptedClaims[] = $claimId;
        return ['id' => $claimId, 'status' => 'accepted', 'version' => $version + 1];
    }

    public function cancelClaim(string $claimId, string $cancelState = 'free', int $version = 1): array
    {
        $this->cancelledClaims[] = ['claim_id' => $claimId, 'cancel_state' => $cancelState, 'version' => $version];
        return ['id' => $claimId, 'status' => 'cancelled', 'version' => $version];
    }

    public function getClaimInfo(string $claimId): array
    {
        return [
            'id' => $claimId,
            'status' => $this->claimStatuses[$claimId] ?? 'accepted',
            'version' => 1,
        ];
    }
}
