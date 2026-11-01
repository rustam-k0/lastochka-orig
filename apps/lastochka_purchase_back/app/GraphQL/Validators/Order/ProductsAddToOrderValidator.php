<?php

namespace App\GraphQL\Validators\Order;

use App\Models\Order;
use App\Models\Status;
use App\Rules\ProductActiveRule;
use App\Rules\RemainsForAddingExistsRule;
use App\Support\NetworkCatalogMode;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ProductsAddToOrderValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    // Сетевой каталог: цена и остаток товара считаются в магазине заказа —
    // резолвим его один раз здесь. В легаси-режиме магазин не нужен (лишних
    // запросов не делаем), правило работает как раньше.
    $shopId = NetworkCatalogMode::isEnabled()
      ? Order::whereKey($this->arg('order_id'))->value('shop_id')
      : null;

    return [
      'order_id' => ['required', Rule::exists('orders', 'id')->whereIn('status_id', [Status::ORDER_CREATED, Status::ORDER_PREPARING]),
        function ($attribute, $value, $fail) {
          $query = Order::query();

          if (auth('client')->check()) {
            $query->auth();
          }

          if (!$query->whereId($value)->exists()) {
            $fail("У вас нет доступа к заказу");
          }

        }
      ],
      'products.*.product_id' => ['bail', 'required', Rule::exists('products', 'id'),
        new ProductActiveRule(),
      ],
      'products.*.quantity' => ['required', 'integer', 'min:1'],
      'products.*' => ['required', new RemainsForAddingExistsRule($this->arg('order_id'), $shopId !== null ? (int) $shopId : null)]
    ];
  }
}
