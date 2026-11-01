<?php

namespace App\GraphQL\Validators\Assembly;

use App\Models\Assembly;
use App\Models\Order;
use App\Models\Status;
use Nuwave\Lighthouse\Validation\Validator;

class AssemblyChargePaymentValidator extends Validator
{
  public function rules(): array
  {
    return [
      'id' => ['bail', 'required',
        function ($attribute, $value, $fail) {
          if (!Order::isCollectorPaymentModuleEnabled()) {
            $fail('Списание оплаты сборщиком отключено');
            return;
          }

          $assembly = Assembly::auth()->find($value);
          if (!$assembly) {
            $fail('Сборка не найдена');
            return;
          }

          $left = $assembly->assemblyItems()
            ->whereIn('status_id', [Status::PRODUCT_WAIT, Status::PRODUCT_CLARIFY])
            ->exists();

          if ($left) {
            $fail('Невозможно списать оплату. Остались не обработанные товары');
          }
        },
      ],
    ];
  }
}
