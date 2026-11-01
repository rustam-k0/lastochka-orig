<?php

namespace App\Rules;

use App\Models\Attribute;
use Illuminate\Contracts\Validation\Rule;

class NotDefaultAttribute implements Rule
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
        return !Attribute::default()->whereId($value)->exists();
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Невозможно взаимодействовать с системным атрибутом';
    }
}
