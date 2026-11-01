<?php

namespace App\GraphQL\Validators\User;

use App\Models\User;
use App\Rules\CheckUserCredentials;
use App\Rules\PhoneExists;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class UserLoginValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'phone' => ['required', new PhoneExists(),],
      'password' => ['required', new CheckUserCredentials($this->arg('phone'))]
    ];
  }
}
