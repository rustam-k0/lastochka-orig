<?php

declare(strict_types=1);

namespace App\DTOs\Order;

use App\Enums\ShippingMethod;
use App\Http\Requests\Api\V1\Order\CreateOrderRequest;
use App\Models\Address;
use App\Models\User;

final readonly class CreateOrderData
{
    public function __construct(
        public User $user,
        public int $storeId,
        public ?Address $address,
        public ?int $deliverySlotId,
        public ShippingMethod $shippingMethod = ShippingMethod::Courier,
        public ?string $comment = null,
        public ?string $idempotencyKey = null,
        public ?string $bonusAction = null,
        public ?int $bonusSpendAmount = null,
        public ?string $paymentType = null,
        public ?int $paymentCardId = null,
        public ?string $shippingTariffCode = null,
        /**
         * Marketplace sub-order'ы создаются с искусственным paymentType=`cash`
         * (consolidated hold живёт на уровне client_order), поэтому проверку
         * `pickup_payment_methods` нужно сделать на верхнем уровне по
         * реальному paymentType — в CreateClientOrderAction, а здесь
         * пропустить, чтобы фиктивный `cash` не валил магазин с
         * pickup_payment_methods=['card'].
         */
        public bool $skipPickupPaymentCheck = false,
        /**
         * Коды выбранных опций чекаута (MENDEV-17), например
         * chill_before_delivery. Снапшот с label'ами делает CreateOrderAction.
         *
         * @var list<string>
         */
        public array $checkoutOptions = [],
        /**
         * Адрес, на который ЮKassa вернёт клиента после оплаты. Проверен
         * правилом валидации; null — дефолтный адрес из конфига.
         */
        public ?string $returnUrl = null,
    ) {}

    public function isPickup(): bool
    {
        return $this->shippingMethod === ShippingMethod::Pickup;
    }

    /**
     * True для методов доставки через пункты выдачи (ПВЗ): СДЭК, Почта России.
     */
    public function isPickupPoint(): bool
    {
        return $this->shippingMethod->isPickupPoint();
    }

    /**
     * Создать из Request.
     */
    public static function fromRequest(CreateOrderRequest $request): self
    {
        $addressId = $request->validated('addressId');
        $deliverySlotId = $request->validated('deliverySlotId');

        return new self(
            user: $request->user(),
            storeId: $request->validated('storeId'),
            address: $addressId !== null ? Address::findOrFail($addressId) : null,
            deliverySlotId: $deliverySlotId !== null ? (int) $deliverySlotId : null,
            shippingMethod: ShippingMethod::from($request->validated('shippingMethod', 'courier')),
            comment: $request->validated('comment'),
            idempotencyKey: $request->validated('idempotencyKey'),
            bonusAction: $request->validated('bonusAction'),
            bonusSpendAmount: $request->validated('bonusSpendAmount'),
            paymentType: $request->validated('paymentType'),
            paymentCardId: $request->validated('paymentCardId'),
            shippingTariffCode: $request->validated('shippingTariffCode'),
            checkoutOptions: array_values(array_filter((array) $request->validated('checkoutOptions', []), 'is_string')),
            returnUrl: $request->returnUrl(),
        );
    }
}
