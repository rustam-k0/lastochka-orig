<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\ReceiptProvider;

final readonly class FiscalReceiptResult
{
    public function __construct(
        public ReceiptProvider $provider,
        public ?string $externalId,
        public string $status,           // pending | succeeded | failed
        public ?string $fn = null,
        public ?string $fd = null,
        public ?string $fp = null,
        public ?string $receiptUrl = null,
        public ?\DateTimeInterface $registeredAt = null,
        public array $raw = [],
    ) {}
}
