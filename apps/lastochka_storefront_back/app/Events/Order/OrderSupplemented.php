<?php

declare(strict_types=1);

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

final class OrderSupplemented
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  Collection<int, array{product_id: int, sku: string, title: string, price: int, quantity: int, total: int}>  $addedItems
     */
    public function __construct(
        public Order $order,
        public Collection $addedItems,
    ) {}
}
