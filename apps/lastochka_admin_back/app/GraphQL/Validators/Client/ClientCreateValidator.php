<?php

namespace App\GraphQL\Validators\Client;

use App\Models\Client;
use App\Rules\PhoneUnique;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ClientCreateValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      "name" => ["required", "max:255"],
      "phone" => ["required", "phone:AUTO", new PhoneUnique(new Client())],
      "surname" => ["required", "max:255"],
      "patronymic" => ["nullable", "max:255"],
      "email" => ["nullable", "email", "max:255", Rule::unique((new Client())->getTable())->whereNull("deleted_at")],
      "birthday" => ["nullable", "date"],
    ];
  }
}
