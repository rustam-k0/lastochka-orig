<?php

namespace App\Rules;

use App\Models\AssemblyItem;
use App\Models\Type;
use Illuminate\Contracts\Validation\Rule;

class IsShopTypeRule implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $assembly_item = AssemblyItem::find($value);
        $shop = $assembly_item->assembly->order->shop;

        return $shop->type_id == Type::SHOP_TYPE_STORE;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Доступно только для магазинов.';
    }
}
