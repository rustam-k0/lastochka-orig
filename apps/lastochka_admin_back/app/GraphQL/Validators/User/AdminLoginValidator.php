<?php

namespace App\GraphQL\Validators\User;

use App\Rules\AdminLoginRule;
use App\Rules\LoginRule;
use Nuwave\Lighthouse\Validation\Validator;

class AdminLoginValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      "email" => [
        "bail",
        "required",
        "email",
        new LoginRule($this->arg("password")),
        new AdminLoginRule(),
      ],
      "password" => ["required", "min:8"],
    ];
  }
}
