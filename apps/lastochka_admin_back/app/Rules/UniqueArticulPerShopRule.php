<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Product;
use Illuminate\Contracts\Validation\InvokableRule;

class UniqueArticulPerShopRule implements InvokableRule
{
    protected ?int $shopId;
    protected ?int $excludeId;

    public function __construct(?int $shopId, ?int $excludeId = null)
    {
        $this->shopId = $shopId;
        $this->excludeId = $excludeId;
    }

    public function __invoke($attribute, $value, $fail)
    {
        if ($this->shopId === null) {
            return;
        }

        $query = Product::where('shop_id', $this->shopId)
            ->where('articul', $value)
            ->whereNull('deleted_at');

        if ($this->excludeId !== null) {
            $query->where('id', '!=', $this->excludeId);
        }

        if ($query->exists()) {
            $fail('Товар с таким артикулом уже существует в этом магазине.');
        }
    }
}
