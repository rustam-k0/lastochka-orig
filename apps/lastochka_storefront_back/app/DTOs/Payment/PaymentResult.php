<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

final readonly class PaymentResult
{
    public function __construct(
        public bool $success,
        public ?string $paymentId = null,
        public ?string $confirmationUrl = null,
        public ?string $status = null,
        public ?string $paymentMethodId = null,
        public ?string $errorMessage = null,
        public ?string $refundId = null,
        /**
         * Машинный код причины отказа (ЮKassa cancellation_details.reason,
         * напр. 'insufficient_funds'). Заполняется при отказе платежа, чтобы
         * клиент идентифицировал кейс по коду, а не по тексту errorMessage.
         */
        public ?string $declineReason = null,
    ) {}
}
