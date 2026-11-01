<?php

namespace App\Rules;

use App\Models\Order;
use App\Models\Product;
use App\Models\Type;
use App\Services\OrderCreatorService;
use App\Support\NetworkCatalogMode;
use Illuminate\Contracts\Validation\Rule;


class RemainExistsRule implements Rule
{
  private $order_id;
  private $quantity;
  /**
   * Магазин, в контексте которого считаем цену и остаток (сетевой каталог).
   * null — берём магазин заказа; в легаси-режиме не используется вовсе.
   */
  private ?int $shopId;

  private string $message = 'Нет остатков для данного товара';

  /**
   * Create a new rule instance.
   *
   * @return void
   */
  public function __construct($order_id, $quantity, ?int $shopId = null)
  {
    $this->order_id = $order_id;
    $this->quantity = $quantity;
    $this->shopId = $shopId;
  }

  /**
   * Determine if the validation rule passes.
   *
   * @param string $attribute
   * @param mixed $value
   * @return bool
   */
  public function passes($attribute, $value)
  {
    $order = Order::find($this->order_id);

    abort_if(!$order || !$this->quantity, 404);

    $networkCatalog = NetworkCatalogMode::isEnabled();
    // Сетевой каталог: цена и остаток принадлежат магазину заказа, а не карточке.
    $shopId = $networkCatalog ? ($this->shopId ?? $order->shop_id) : null;

    $service = new OrderCreatorService();
    $product = Product::find($value);
    $price = $networkCatalog ? $product->getAuthPrice($shopId) : $product->prices()->first();

    if ($networkCatalog && !$price) {
      // Товара нет в прайсе магазина заказа — продать его оттуда нельзя.
      $this->message = 'Товар недоступен в магазине заказа';

      return false;
    }

    $quantity = $this->quantity;

    $product = $service->getQuantityAndPrice($product, $price, $quantity);

    if ($product['product']->categories()->where('without_blocking_price', true)->exists()) {
      return true;
    }

    if ($product['product']->effectiveStockIgnored()) {
      return true; // ignore/fixed — остаток не блокирует заказ (дневным лимитом fixed рулит витрина)
    }

    // Получаем остатки товара в магазине, закрепленном за клиентом
    // (сетевой каталог — в магазине заказа)
    $remain = $product['product']->getAuthRemains($shopId);

    $quantity = 0;

    if ($remain) {
      // Если тип товара - граммы, то переводим остатки килограммы в граммы
      $quantity = $product['product']->unit_type_id == Type::UNIT_GRAM
        ? $remain->quantity * 1000
        : $remain->quantity;
    }


    // Если остатков не хватает, то выдаем ошибку
    if ($quantity < $product['quantity']) {
      return false;
    }

    return true;
  }

  /**
   * Get the validation error message.
   *
   * @return string
   */
  public function message()
  {
    return $this->message;
  }
}
