<?php

namespace App\GraphQL\Validators\Assembly;

use App\Models\AssemblyItem;
use App\Rules\AssemblyItemExistsRule;
use Nuwave\Lighthouse\Validation\Validator;

final class MarkingCodeScanValidator extends Validator
{
  /**
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      // allowAssembled: собранная позиция отбивается в мутации понятным
      // «Товар уже собран, верните его в сборку», а не «Товара нет в заказе».
      'assembly_item_id' => ['required', 'integer', 'bail', new AssemblyItemExistsRule(allowAssembled: true),
        // После списания оплаты (модуль collector_payment) состав заморожен —
        // сканы менять смысла нет.
        function ($attribute, $value, $fail) {
          $assemblyItem = AssemblyItem::with('assembly.order')->find($value);
          $order = $assemblyItem?->assembly?->order;
          if ($order && $order->isCompositionLockedForAssembly()) {
            $fail('Оплата по заказу уже списана, изменение состава недоступно');
          }
        },
      ],
      'code' => ['required', 'string', 'min:8', 'max:255'],
      'product_id' => ['nullable', 'integer',
        // Код можно привязать только к товару позиции либо к кандидату на замену.
        function ($attribute, $value, $fail) {
          $assemblyItem = AssemblyItem::with(['orderItem', 'replacements'])->find($this->arg('assembly_item_id'));
          if (!$assemblyItem) {
            return;
          }

          $allowed = $assemblyItem->replacements->pluck('product_id')->map(fn($id) => (int) $id)->all();
          if ($assemblyItem->orderItem) {
            $allowed[] = (int) $assemblyItem->orderItem->product_id;
          }

          if (!in_array((int) $value, $allowed, true)) {
            $fail('Товар не относится к этой позиции сборки');
          }
        },
      ],
    ];
  }
}
