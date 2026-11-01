<?php

namespace App\GraphQL\Validators\Assembly;

use App\Models\Assembly;
use App\Models\AssemblyItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\Rules\AssemblyItemExistsRule;
use App\Rules\ProductHasStockRule;
use App\Services\HonestSign\MarkingRequirement;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ProductConfirmValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    $shop = $this->shopForAssemblyItem($this->arg('assembly_item_id'));

    return [
      // allowAssembled: повтор подтверждения уже собранной позиции пропускаем
      // до мутации — она вернёт идемпотентный OK либо «Товар уже собран».
      'assembly_item_id' => ['required', 'bail', new AssemblyItemExistsRule(allowAssembled: true),
        // После списания оплаты (модуль collector_payment, онлайн, paid=1)
        // менять состав нельзя — сумма списания перестала бы соответствовать.
        function ($attribute, $value, $fail) {
          $assemblyItem = AssemblyItem::with('assembly.order')->find($value);
          $order = $assemblyItem?->assembly?->order;
          if ($order && $order->isCompositionLockedForAssembly()) {
            $fail('Оплата по заказу уже списана, изменение состава недоступно');
            return;
          }

          // Честный знак (MID-266): маркированный товар подтверждается только
          // с полным набором отсканированных кодов (по коду на единицу).
          if ($assemblyItem) {
            $error = MarkingRequirement::errorForConfirm(
              $assemblyItem,
              $this->arg('replacement_product_id'),
              $this->arg('quantity'),
              $this->arg('actual_weight'),
            );
            if ($error) {
              $fail($error);
            }
          }
        },
      ],
      'replacement_product_id' => ['bail',
        // Если тип товара на замену, то id товара за замены обязательное
        Rule::requiredIf(function () {
          $assembly_item = AssemblyItem::whereHas('assembly',fn($query) => $query->auth())->find($this->arg('assembly_item_id'));
          if (!$assembly_item) {
            return true;
          }

          return $assembly_item->type_id == Type::PRODUCT_NOT_EXISTS;
        }),
        Rule::exists((new Product())->getTable(), 'id')->whereNull('deleted_at'),
        // Товар должен быть выбранным для замены
        function ($attribute, $value, $fail) {
          $exists = Assembly::auth()->whereHas('assemblyItems.replacements', function ($query) use ($value) {
            $query->whereProductId($value);
          })->exists();

          if (!$exists) {
            $fail("Этого товара нет в замене!");
          }
        },
        new ProductHasStockRule($shop),
      ],
      'quantity' => [
        'nullable',
        'integer',
        'gt:0',
        'required_without:actual_weight',
        'prohibits:actual_weight',
      ],
      'actual_weight' => [
        'nullable',
        'integer',
        'min:1',
        'required_without:quantity',
      ],
      'replace_reason_id' => ['nullable', 'integer', 'exists:product_replace_reasons,id'],
    ];
  }

  /**
   * Магазин сборки по позиции (для учёта настройки "Не учитывать остатки").
   */
  private function shopForAssemblyItem($assemblyItemId): ?Shop
  {
    return AssemblyItem::query()
      ->whereHas('assembly', fn($query) => $query->auth())
      ->with('assembly.order.shop')
      ->find($assemblyItemId)
      ?->assembly?->order?->shop;
  }
}
