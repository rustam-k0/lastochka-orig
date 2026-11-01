<?php

namespace App\GraphQL\Validators\User;

use App\Models\Shift;
use Nuwave\Lighthouse\Validation\Validator;

final class UserConfirmSelfieValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'img' => ['required', function (string $attribute, mixed $value, $fail) {
        // Валидатор Lighthouse отрабатывает до того, как мутация упрётся в
        // guard, поэтому с протухшим токеном сюда приходит гость. Без этой
        // проверки \Auth::user() отдаёт null и валидация падала фаталом
        // «Call to a member function shops() on null» вместо внятного ответа.
        $user = \Auth::user();
        if (!$user) {
          $fail("Требуется авторизация");

          return;
        }

        $exists = Shift::auth()->exists();
        if ($exists) {
          $fail("У вас есть активная смена!");
        }

        $shop = $user->shops()->first();
        if (!$shop) {
          $fail("У вас нет привязанного магазина!");
        }
      },]
    ];
  }
}
