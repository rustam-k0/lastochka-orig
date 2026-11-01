<?php

namespace App\Rules;

use Auth;
use Illuminate\Contracts\Validation\Rule;

class LoginRule implements Rule
{
  private $pass;

  /**
   * Create a new rule instance.
   *
   * @return void
   */
  public function __construct($pass)
  {

    $this->pass = $pass;
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
    return Auth::guard('web')->attempt([
      'email' => $value,
      'password' => $this->pass
    ]);
  }

  /**
   * Get the validation error message.
   *
   * @return string
   */
  public function message()
  {
    return 'Неправильный логин или пароль';
  }
}
