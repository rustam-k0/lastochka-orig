<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class RemainsForAddingExistsRule implements Rule
{
  private $order_id;
  /**
   * Магазин заказа для сетевого каталога — пробрасывается во вложенное правило.
   */
  private ?int $shopId;

  private string $message = 'Нет остатков для данного товара';

  /**
   * Create a new rule instance.
   *
   * @return void
   */
  public function __construct($order_id, ?int $shopId = null)
  {
    $this->order_id = $order_id;
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

    $rule = new RemainExistsRule($this->order_id, $value['quantity'], $this->shopId);

    if (!$rule->passes('attr', $value['product_id'])) {
      // Причина отказа известна вложенному правилу (нет остатка / нет цены в магазине)
      $this->message = $rule->message();

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
