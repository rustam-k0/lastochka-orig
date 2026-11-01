<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

final readonly class PaymentInfo
{
    public function __construct(
        public string $paymentId,
        public string $status,
        public string $amount,
        public string $currency,
        public ?string $paymentMethodId = null,
        public ?string $cardType = null,
        public ?string $lastFour = null,
        public ?string $expiryMonth = null,
        public ?string $expiryYear = null,
        public bool $paid = false,
    ) {}
}
