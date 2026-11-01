<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\ShippingMethod;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use Illuminate\Validation\ValidationException;

/**
 * Резолвит стоимость тарифа сторонних служб доставки (СДЭК, Почта России)
 * для конкретного ПВЗ/ОПС, веса и кода тарифа.
 *
 * Источник тарифов — slots-api (purchase_back), запрашивает он провайдера
 * напрямую. Метод возвращает рубли (int), валидирует наличие выбранного
 * тарифа в актуальном списке.
 */
final class ThirdPartyTariffPriceResolver
{
    public function __construct(
        private readonly SlotsApiClientInterface $slotsApiClient,
    ) {
    }

    /**
     * @throws ValidationException 422 если тариф не найден среди доступных.
     */
    public function resolve(
        int $shopId,
        ShippingMethod $shippingMethod,
        ?string $externalId,
        ?string $tariffCode,
        ?int $weightGrams,
    ): int {
        if (! $shippingMethod->isPickupPoint()) {
            throw new \LogicException('ThirdPartyTariffPriceResolver вызван для метода без интеграции: '.$shippingMethod->value);
        }

        if ($externalId === null || $externalId === '') {
            throw ValidationException::withMessages([
                'address' => ['Адрес доставки должен содержать код ПВЗ / индекс ОПС.'],
            ]);
        }

        if ($tariffCode === null || $tariffCode === '') {
            throw ValidationException::withMessages([
                'shippingTariffCode' => ['Не указан тариф доставки.'],
            ]);
        }

        $tariffs = match ($shippingMethod) {
            ShippingMethod::Cdek => $this->slotsApiClient->getCdekTariffs(array_filter([
                'shopId' => $shopId,
                'code' => $externalId,
                'weight' => $weightGrams,
            ], fn ($v) => $v !== null)),
            ShippingMethod::RussianPost => $this->slotsApiClient->getRpTariffs(array_filter([
                'shopId' => $shopId,
                'index' => $externalId,
                'weight' => $weightGrams,
            ], fn ($v) => $v !== null)),
            default => [],
        };

        foreach ($tariffs as $tariff) {
            if (! is_array($tariff)) {
                continue;
            }

            if ((string) ($tariff['code'] ?? '') !== $tariffCode) {
                continue;
            }

            $price = $tariff['price'] ?? null;
            if (! is_numeric($price)) {
                continue;
            }

            return (int) round((float) $price);
        }

        throw ValidationException::withMessages([
            'shippingTariffCode' => ['Выбранный тариф доставки больше недоступен. Обновите список тарифов и попробуйте снова.'],
        ]);
    }
}
