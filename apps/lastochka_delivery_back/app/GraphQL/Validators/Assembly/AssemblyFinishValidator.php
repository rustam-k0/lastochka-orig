<?php

namespace App\GraphQL\Validators\Assembly;

use App\Models\Assembly;
use App\Models\Scopes\NotFinished;
use App\Models\Status;
use App\Models\Type;
use App\Services\HonestSign\MarkingRequirement;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class AssemblyFinishValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      "id" => ["bail","required", function ($attribute, $value, $fail) {
        // Без скоупа NotFinished: повторный запрос по уже завершённой сборке —
        // штатный сценарий (двойной свайп, ретрай после обрыва). Мутация
        // ответит на него идемпотентным успехом, поэтому здесь не фейлим.
        $assembly = Assembly::withoutGlobalScope(NotFinished::class)->auth()->find($value);

        if (!$assembly) {
          $fail("Сборка не найдена");
          return;
        }

        // Завершённую сборку дальше не проверяем: её позиции уже финализированы,
        // а проверки «остались необработанные товары» и «Честный знак» относятся
        // к незакрытой сборке.
        if ($assembly->finished_at !== null) {
          return;
        }

        // Проверка, остались ли заказы со статусом товар ожидает сборки и уточнить
        $left = $assembly->assemblyItems()->whereIn("status_id", [Status::PRODUCT_WAIT, Status::PRODUCT_CLARIFY])->exists();

        if ($left) {
          $fail("Невозможно завершить сборку. Остались не обработанные товары");
          return;
        }

        // Честный знак (MID-266): у каждой собранной маркированной позиции
        // должно быть ровно столько кодов, сколько собрано единиц.
        if ($error = MarkingRequirement::errorForAssembly($assembly)) {
          $fail("Невозможно завершить сборку. {$error}");
        }

      }],
      "packages" => ["required", "min:1"],
      "packages.*.quantity" => ["required", "gt:0"],
      "packages.*.type_id" => ["required", Rule::in([Type::PACKAGE_CABINET, Type::PACKAGE_FRIDGE, Type::PACKAGE_FREEZER])]
    ];
  }
}
