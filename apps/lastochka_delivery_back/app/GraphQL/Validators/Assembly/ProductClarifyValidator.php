<?php

namespace App\GraphQL\Validators\Assembly;

use App\Models\AssemblyItem;
use App\Models\ExplainReason;
use App\Models\Status;
use App\Rules\AssemblyItemExistsRule;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ProductClarifyValidator extends Validator
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
        Rule::exists((new AssemblyItem())->getTable())->whereNull('deleted_at')->where('status_id', Status::PRODUCT_WAIT),
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
      'explain_reason_id' => ['nullable', Rule::exists((new ExplainReason())->getTable(), 'id')->whereNull('deleted_at')],
      'cancel_reason_id' => ['nullable', 'integer', 'exists:product_cancel_reasons,id'],
    ];
  }
}
