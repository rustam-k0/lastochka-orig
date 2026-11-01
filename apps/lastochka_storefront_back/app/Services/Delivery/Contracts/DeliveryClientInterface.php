<?php

declare(strict_types=1);

namespace App\Services\Delivery\Contracts;

use App\DTOs\Delivery\DeliveryInfoData;
use App\Exceptions\DeliverySystemException;

interface DeliveryClientInterface
{
    /**
     * Получить слоты доставки.
     *
     * @param  array<int, array{articul: string, count: int}>  $products  Товары корзины для расчёта времени сборки
     *
     * @throws DeliverySystemException
     */
    public function getSlots(string $storeCode, float $latitude, float $longitude, string $date, array $products = []): DeliveryInfoData;
}
