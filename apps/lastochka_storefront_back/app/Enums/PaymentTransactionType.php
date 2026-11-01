<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentTransactionType: string
{
    case Hold = 'hold';
    case Capture = 'capture';
    case Refund = 'refund';
}
