<?php

declare(strict_types=1);

namespace App\DTOs\Order;

use App\Enums\ShippingMethod;
use App\Models\User;

/**
 * DTO для создания marketplace client-order'а: один клиентский заказ → N Order в БД, связанных через client_order_id.
 *
 * shippingMethod=Courier — корзина создаёт N Order по deliverySlots (один слот на магазин).
 * shippingMethod=Cdek/RussianPost — корзина делится по уникальным store_id из cart_items,
 * deliverySlots игнорируется, shippingTariffCodes резолвится per-store.
 */
final readonly class CreateClientOrderData
{
    /**
     * @param  array<int, array{storeId: int, slotId: int}>  $deliverySlots
     * @param  array<int, int>  $promocodeIds
     * @param  string|array<int|string, string>|null  $shippingTariffCodes
     *   Может быть строкой (один тариф на все магазины) либо map storeId→code.
     *   Используется только при shippingMethod=Cdek/RussianPost.
     */
    public function __construct(
        public User $user,
        public int $addressId,
        public string $paymentType,
        public array $deliverySlots,
        public array $promocodeIds = [],
        public ?string $comment = null,
        public ?string $idempotencyKey = null,
        public ?int $paymentCardId = null,
        public ShippingMethod $shippingMethod = ShippingMethod::Courier,
        public string|array|null $shippingTariffCodes = null,
        public ?string $bonusAction = null,
        public ?int $bonusSpendAmount = null,
        /**
         * Адрес, на который ЮKassa вернёт клиента после оплаты. Проверен
         * правилом валидации; null — дефолтный адрес из конфига.
         */
        public ?string $returnUrl = null,
    ) {
    }

    /**
     * Резолвит код тарифа для конкретного магазина.
     * - shippingTariffCodes как строка → одно значение для всех.
     * - shippingTariffCodes как map → значение по ключу storeId (с fallback на int/string ключи).
     * - null → null (актуально только для Courier).
     */
    public function tariffCodeFor(int $storeId): ?string
    {
        if (is_string($this->shippingTariffCodes)) {
            return $this->shippingTariffCodes;
        }

        if (is_array($this->shippingTariffCodes)) {
            return $this->shippingTariffCodes[$storeId]
                ?? $this->shippingTariffCodes[(string) $storeId]
                ?? null;
        }

        return null;
    }
}
