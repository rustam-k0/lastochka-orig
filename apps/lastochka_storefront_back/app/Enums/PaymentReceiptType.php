<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentReceiptType: string
{
    case Payment = 'payment';
    case Refund = 'refund';
}
