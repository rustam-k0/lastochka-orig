<?php

declare(strict_types=1);

namespace App\DTOs\Cart;

use App\Models\Promocode;
use App\Models\Store;
use Illuminate\Support\Collection;

final readonly class CartStoreGroupData
{
    public function __construct(
        public Store $store,
        public Collection $items,
        public ?Promocode $promocode = null,
    ) {}
}
