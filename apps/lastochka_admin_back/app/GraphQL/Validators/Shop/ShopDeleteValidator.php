<?php

namespace App\GraphQL\Validators\Shop;

use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ShopDeleteValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'id' => ['required', Rule::exists('shops')]
    ];
  }
}
