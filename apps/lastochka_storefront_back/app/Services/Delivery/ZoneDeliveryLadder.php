<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Services\Pricing\DeliveryPriceLadder;
use App\Services\Settings\ModuleConfigService;

/**
 * Лесенка стоимости доставки зоны или магазина (MID-280).
 *
 * Ступени есть: лесенка из них. Ступеней нет (зона без диапазонов, старый ответ
 * slots-api, старая запись кэша, событие магазина до выкладки admin_back): лесенка
 * из пары «цена доставки + порог бесплатной доставки», результат совпадает с
 * прежним порогом.
 *
 * Модуль delivery_price_ranges выключен: ступени игнорируются независимо от
 * того, что пришло в $ranges — лесенка всегда строится из цены и порога, то
 * есть стоимость доставки считается как до фичи (раздел 5 спеки).
 */
final class ZoneDeliveryLadder
{
    public static function make(int $deliveryPrice, mixed $ranges, ?int $freeDeliveryFrom): DeliveryPriceLadder
    {
        if (! app(ModuleConfigService::class)->isDeliveryPriceRangesEnabled()) {
            return DeliveryPriceLadder::fromFreeDeliveryFrom($deliveryPrice, $freeDeliveryFrom);
        }

        $normalized = DeliveryPriceLadder::normalize($ranges);

        if ($normalized !== null && $normalized !== []) {
            return DeliveryPriceLadder::fromRanges($deliveryPrice, $normalized);
        }

        return DeliveryPriceLadder::fromFreeDeliveryFrom($deliveryPrice, $freeDeliveryFrom);
    }
}
