<?php

declare(strict_types=1);

namespace App\Enums;

enum ReceiptProvider: string
{
    case Yookassa = 'yookassa';
    case Atol = 'atol';
}
