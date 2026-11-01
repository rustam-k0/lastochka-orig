<?php

declare(strict_types=1);

namespace App\DTOs\Store;

use Illuminate\Support\Collection;

final readonly class MenuSectionData
{
    /**
     * @param  Collection<int, \App\Models\Product>  $products
     */
    public function __construct(
        public string $type,
        public int $id,
        public string $title,
        public ?string $imagePath,
        public int $sortOrder,
        public Collection $products,
    ) {}
}
