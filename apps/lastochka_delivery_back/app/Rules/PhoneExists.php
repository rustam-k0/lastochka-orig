<?php

namespace App\Rules;

use App\Models\User;
use App\Services\PhoneFormatter;
use Illuminate\Contracts\Validation\Rule;

class PhoneExists implements Rule
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
   * @param string $attribute
   * @param mixed $value
   * @return bool
   */
  public function passes($attribute, $value)
  {
    $phone = PhoneFormatter::format($value);

    return User::wherePhone($phone)->exists();
  }

  /**
   * Get the validation error message.
   *
   * @return string
   */
  public function message()
  {
    return 'Неверный логин или пароль!';
  }
}
