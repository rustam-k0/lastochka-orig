<?php

namespace App\GraphQL\Validators\Assembly;

use App\Models\Assembly;
use Nuwave\Lighthouse\Validation\Validator;

final class AssemblyCancelValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'id' => ['bail', 'required', function ($attribute, $value, $fail) {
        $exists = Assembly::auth()->whereId($value)->exists();
        if (!$exists) {
          $fail("Сборка не найдена");
        }
      }]
    ];
  }
}
