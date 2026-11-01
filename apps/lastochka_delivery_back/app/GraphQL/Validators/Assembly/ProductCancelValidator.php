<?php

namespace App\GraphQL\Validators\Assembly;

use App\Models\AssemblyItem;
use App\Rules\AssemblyItemExistsRule;
use Nuwave\Lighthouse\Validation\Validator;

final class ProductCancelValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'id' => ['bail', 'required', new AssemblyItemExistsRule(),
        // После списания оплаты (модуль collector_payment, онлайн, paid=1)
        // менять состав нельзя — сумма списания перестала бы соответствовать.
        function ($attribute, $value, $fail) {
          $assemblyItem = AssemblyItem::with('assembly.order')->find($value);
          $order = $assemblyItem?->assembly?->order;
          if ($order && $order->isCompositionLockedForAssembly()) {
            $fail('Оплата по заказу уже списана, изменение состава недоступно');
            return;
          }
        },
      ],
      'cancel_reason_id' => ['nullable', 'integer', 'exists:product_cancel_reasons,id'],
    ];
  }
}
