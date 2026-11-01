<?php

declare(strict_types=1);

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OrderCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Order $order,
    ) {}
}
