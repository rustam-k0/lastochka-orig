<?php

namespace App\GraphQL\Validators\Assembly;

use App\Models\Assembly;
use App\Models\Type;
use Nuwave\Lighthouse\Validation\Validator;

final class AssemblyStartCookingValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      "id" => ["bail", "required", function ($attribute, $value, $fail) {
        $assembly = Assembly::auth()->find($value);

        if (!$assembly) {
          $fail("Сборка не найдена");
        }

        if ($assembly->cooking_started_at) {
          $fail("Готовка уже началась");
        }

        if ($assembly->order->shop->type_id != Type::SHOP_TYPE_CAFE) {
          $fail("Чтобы начать готовку, тип заведения должен быть 'Кафе'");
        }
      }],
    ];
  }
}
