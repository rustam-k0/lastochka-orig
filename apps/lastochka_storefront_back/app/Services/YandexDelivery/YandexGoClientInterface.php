<?php

namespace App\Services\YandexDelivery;

use App\DTOs\Delivery\YandexDeliveryMethodsData;
use App\DTOs\Delivery\YandexPriceData;

interface YandexGoClientInterface
{
    public function getDeliveryMethods(array $startPoint, array $endPoint): YandexDeliveryMethodsData;
    public function checkPrice(array $startPoint, array $endPoint, ?bool $skipDoorToDoor = null): YandexPriceData;
    public function createClaim(array $payload, string $requestId): array;
    public function acceptClaim(string $claimId, int $version): array;
    public function cancelClaim(string $claimId, string $cancelState = 'free', int $version = 1): array;
    public function getClaimInfo(string $claimId): array;
}
