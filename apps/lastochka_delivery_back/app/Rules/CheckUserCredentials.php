<?php

namespace App\Rules;

use App\GraphQL\Mutations\User\UserLogin;
use App\Models\User;
use App\Services\PhoneFormatter;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Hash;

class CheckUserCredentials implements Rule
{
  private ?string $phone;

  /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct(?string $phone)
    {
      $this->phone = $phone;
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
      if (!$this->phone) {
        return true;
      }

      $phone = PhoneFormatter::format($this->phone);

      $user = User::firstWhere('phone',$phone);

      if (!$user) {
        return true;
      }

      return Hash::check($value,$user->password);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Неверный логин или пароль';
    }
}
