<?php

declare(strict_types=1);

namespace App\Services\Geo;

use App\DTOs\Geo\IpLocationData;
use App\Services\Geo\Contracts\IpGeolocatorInterface;

final class FakeIpGeolocator implements IpGeolocatorInterface
{
    /**
     * По умолчанию локация «не определена» — как для приватных IP
     * в реальном геолокаторе.
     */
    private ?IpLocationData $location = null;

    public function setLocation(?IpLocationData $location): self
    {
        $this->location = $location;

        return $this;
    }

    public function locate(string $ip): ?IpLocationData
    {
        return $this->location;
    }
}
