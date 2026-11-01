<?php

namespace App\GraphQL\Validators\Assembly;

use App\Models\Assembly;
use App\Models\Order;
use App\Models\Product;
use App\Models\Remain;
use App\Rules\ProductWithPriceExistsRule;
use App\Support\NetworkCatalogMode;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ProductAddToOrderValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    $productRules = ['bail', 'required',
      Rule::exists('products', 'id')->whereNull('deleted_at'),
      function ($attribute, $value, $fail) {
        $assembly = Assembly::auth()->with('order')->find($this->arg('assembly_id'));

        if (!$assembly) {
          return;
        }

        $order = $assembly->order;

        // После списания оплаты (модуль collector_payment, онлайн, paid=1)
        // добавление товара меняет состав — сумма списания перестала бы
        // соответствовать.
        if ($order && $order->isCompositionLockedForAssembly()) {
          $fail('Оплата по заказу уже списана, изменение состава недоступно');
          return;
        }

        // Товар должен принадлежать магазину заказа.
        // Сетевой каталог: карточка глобальная (products.shop_id = якорный магазин),
        // принадлежность магазину определяют остаток и цена, а не колонка карточки —
        // иначе гейт зарубал бы вообще любое добавление товара в сборку.
        $product = Product::find($value);
        if (!NetworkCatalogMode::isEnabled()) {
          if (!$product || $product->shop_id !== $order->shop_id) {
            $fail("Товар не принадлежит магазину заказа");
            return;
          }
        }

        // Товар должен быть в наличии — кроме магазинов с "Не учитывать остатки"
        // и кроме товаров в per-product режиме ignore/fixed (effective_stock_mode):
        // их остаток курьерка не блокирует. Зеркало точки в ProductAddToOrder.
        if (!$order->shop?->ignore_stock && !($product && $product->effectiveStockIgnored())) {
          $remain = Remain::withoutGlobalScopes()
            ->where('product_id', $value)
            ->where('shop_id', $order->shop_id)
            ->where('quantity', '>', 0)
            ->first();

          if (!$remain) {
            $fail("Товар отсутствует на складе");
          }
        }
      },
    ];

    // ТОЛЬКО сетевой режим: цена товара в магазине заказа обязана существовать —
    // ProductAddToOrder берёт prices по shop_id заказа и без строки цены упал бы
    // на null. В легаси набор правил остаётся ровно прежним: правило заодно
    // проверяет статус товара (onlyActive), а расширять легаси-гейт нельзя.
    if (NetworkCatalogMode::isEnabled()) {
      $order = $this->orderForAssembly($this->arg('assembly_id'));
      $productRules[] = new ProductWithPriceExistsRule($order?->shop_id);
    }

    return [
      'assembly_id' => ['required',
        function ($attribute, $value, $fail) {
          $assembly = Assembly::auth()->find($value);

          if (!$assembly) {
            $fail("Сборка не найдена");
          }
        }
      ],
      'product_id' => $productRules,
      'quantity' => ['required', 'integer', 'min:1']
    ];
  }

  /**
   * Заказ сборки — нужен для shop-контекста цены (сетевой каталог).
   */
  private function orderForAssembly($assemblyId): ?Order
  {
    return Assembly::auth()->with('order')->find($assemblyId)?->order;
  }
}
