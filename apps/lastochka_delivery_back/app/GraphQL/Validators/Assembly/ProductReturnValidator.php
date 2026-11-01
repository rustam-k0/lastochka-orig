<?php

namespace App\GraphQL\Validators\Assembly;

use App\Models\AssemblyItem;
use App\Models\Status;
use App\Rules\IsShopTypeRule;
use Nuwave\Lighthouse\Validation\Validator;

final class ProductReturnValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'id' => ['bail', 'required', new IsShopTypeRule(),
        // После списания оплаты (модуль collector_payment, онлайн, paid=1)
        // возврат позиции меняет состав — сумма списания перестала бы
        // соответствовать. До оплаты (paid=0) возврат штатно разрешён.
        function ($attribute, $value, $fail) {
          $assemblyItem = AssemblyItem::with('assembly.order')->find($value);
          $order = $assemblyItem?->assembly?->order;
          if ($order && $order->isCompositionLockedForAssembly()) {
            $fail('Оплата по заказу уже списана, изменение состава недоступно');
            return;
          }
        },
        function ($attribute, $value, $fail) {
        $exists = AssemblyItem::whereId($value)
          ->auth()
          ->whereIn('status_id', [Status::PRODUCT_ASSEMBLED, Status::PRODUCT_CANCELED])
          ->exists();

        if (!$exists) {
          $fail("Товар не может быть завершен, так как он не собран");
        }
      },]
    ];
  }
}
