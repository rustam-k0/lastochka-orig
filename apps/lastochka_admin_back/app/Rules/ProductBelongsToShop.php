<?php

namespace App\Rules;

use App\Models\Product;
use Illuminate\Contracts\Validation\Rule;

class ProductBelongsToShop implements Rule
{
    protected int $shopId;
    protected string $errorMessage = '';

    public function __construct(int $shopId)
    {
        $this->shopId = $shopId;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value): bool
    {
        if (!is_array($value)) {
            $this->errorMessage = 'Список товаров должен быть массивом';
            return false;
        }

        $invalidProducts = Product::whereIn('id', $value)
            ->where('shop_id', '!=', $this->shopId)
            ->pluck('id')
            ->toArray();

        if (!empty($invalidProducts)) {
            $this->errorMessage = 'Товары с ID: ' . implode(', ', $invalidProducts) . ' не принадлежат указанному магазину';
            return false;
        }

        $existingProducts = Product::whereIn('id', $value)->pluck('id')->toArray();
        $notFoundProducts = array_diff($value, $existingProducts);

        if (!empty($notFoundProducts)) {
            $this->errorMessage = 'Товары с ID: ' . implode(', ', $notFoundProducts) . ' не найдены';
            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return $this->errorMessage;
    }
}
