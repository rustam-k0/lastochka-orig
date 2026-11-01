<?php

namespace App\Rules;

use Auth;
use Illuminate\Contracts\Validation\Rule;

class PasswordConfirmRule implements Rule
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
    public function passes($attribute, $value): bool
    {
      return Auth::guard('web')->attempt([
        'email' => Auth::user()->email,
        'password' => $value
      ]);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Неправильный пароль';
    }
}
