<?php

declare(strict_types=1);

namespace App\Services\Geo\Contracts;

use App\DTOs\Geo\IpLocationData;

interface IpGeolocatorInterface
{
    /**
     * Определить страну и город по внешнему IP.
     *
     * null — локация не определена: приватный/зарезервированный адрес,
     * невалидный IP или сбой гео-провайдера. Вызывающий код обязан
     * трактовать null как «неизвестно», а не как «вне РФ».
     */
    public function locate(string $ip): ?IpLocationData;
}
