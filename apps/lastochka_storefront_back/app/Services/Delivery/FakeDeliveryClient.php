<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\DTOs\Delivery\DeliveryInfoData;
use App\DTOs\Delivery\DeliverySlotData;
use App\Services\Delivery\Contracts\DeliveryClientInterface;
use Carbon\Carbon;

/**
 * Fake клиент для тестов.
 * Возвращает слоты на ближайшие 7 дней с 8:00 до 22:00 каждые 2 часа.
 */
final class FakeDeliveryClient implements DeliveryClientInterface
{
    public string $deliveryType = 'own';

    /**
     * Порог бесплатной доставки зоны (эмуляция ключа shipping_free_from из
     * ответа slots-api). null = у зоны нет своего порога (MID-280 фикс-раунд 1:
     * тест парности CartCalculator/CreateOrderAction на запасном пороге магазина).
     */
    public ?int $freeDeliveryFrom = 2000;

    /**
     * Зонная стоимость сборки, отдаваемая в DeliveryInfoData::assemblyPrice
     * (эмуляция ключа assembly_price из ответа slots-api). null = у зоны нет
     * своей цены.
     */
    public ?int $assemblyPrice = null;

    /**
     * Ступени стоимости доставки зоны (эмуляция ключа shipping_price_ranges
     * из ответа slots-api, MID-280). [] = ступеней нет.
     *
     * @var array<int, array{from_amount: int, price: int}>
     */
    public array $deliveryPriceRanges = [];

    /**
     * products каждого вызова getSlots — для assert'ов в тестах.
     *
     * @var array<int, array<int, array{articul: string, count: int}>>
     */
    public array $capturedProducts = [];

    public function getSlots(string $storeCode, float $latitude, float $longitude, string $date, array $products = []): DeliveryInfoData
    {
        $this->capturedProducts[] = $products;

        $requestedDate = Carbon::parse($date);
        $slots = [];

        // Генерируем слоты с 8:00 до 22:00 каждые 2 часа
        for ($hour = 8; $hour < 22; $hour += 2) {
            $start = $requestedDate->copy()->setHour($hour)->setMinute(0)->setSecond(0);
            $end = $start->copy()->addHours(2);

            // Не добавляем слоты в прошлом
            if ($start->isFuture()) {
                $slots[] = new DeliverySlotData(
                    id: $start->timestamp,
                    timeSlot: $start->format('H:i').'-'.$end->format('H:i'),
                    startAt: $start,
                    endAt: $end,
                );
            }
        }

        return new DeliveryInfoData(
            slots: $slots,
            minOrderAmount: 500,
            freeDeliveryFrom: $this->freeDeliveryFrom,
            deliveryPrice: 200,
            deliveryType: $this->deliveryType,
            assemblyPrice: $this->assemblyPrice,
            deliveryPriceRanges: $this->deliveryPriceRanges,
        );
    }
}
