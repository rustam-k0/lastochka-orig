<?php

namespace App\GraphQL\Validators\Assembly;

use App\Models\Assembly;
use App\Models\AssemblyItem;
use App\Models\Shop;
use App\Rules\AssemblyItemExistsRule;
use App\Rules\ProductHasStockRule;
use App\Rules\ProductWithPriceExistsRule;
use Illuminate\Database\Eloquent\Builder;
use Nuwave\Lighthouse\Validation\Validator;

final class SetProductsForChangeValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    $shop = $this->shopForAssemblyItem($this->arg('id'));

    return [
      'id' => ['required', 'bail', new AssemblyItemExistsRule(),
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
      'replacements' => ['bail', 'required', 'array', 'min:1', 'max:3',],
      'replacements.*' => ['bail', 'required',
        // Сетевой каталог: цена и остаток проверяются в магазине заказа.
        new ProductWithPriceExistsRule($shop?->id),
        new ProductHasStockRule($shop),
        // Если товар для замены выбран тот же, что и заменяется, то выдаем ошибку
        function ($attribute, $value, $fail) {
          $assembly = Assembly::whereRelation('assemblyItems', 'id', $this->arg('id'))->first();

          $exists = Assembly::auth()
            ->whereRelation('assemblyItems.orderItem', 'product_id', $value)
            ->when($assembly, fn(Builder $query) => $query->where('assemblies.id', $assembly->id))
            ->exists();

          if ($exists) {
            $fail("Товар для замены не может тем, который находится в сборке");
          }

        }
      ]
    ];
  }

  /**
   * Магазин сборки по позиции (для учёта настройки "Не учитывать остатки").
   */
  private function shopForAssemblyItem($assemblyItemId): ?Shop
  {
    return Assembly::auth()
      ->whereRelation('assemblyItems', 'id', $assemblyItemId)
      ->with('order.shop')
      ->first()
      ?->order?->shop;
  }
}
