<?php

namespace App\Rules;

use App\Models\DeliveryOrder;
use App\Models\Status;
use Illuminate\Contracts\Validation\Rule;

class CourierHasOrderRule implements Rule
{
  /**
   * @var array|null
   */
  private $status_ids;

  /**
     * Create a new rule instance.
     *
     * Принимает одно значение или массив допустимых статусов delivery_order.
     * Если null — статус не проверяется.
     *
     * @param int|int[]|null $status_id
     * @return void
     */
    public function __construct($status_id = null)
    {
      $this->status_ids = $status_id !== null ? (array) $status_id : null;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
      $delivery_order = DeliveryOrder::auth()->find($value);

      if (!$delivery_order) {
        return false;
      }

      if ($this->status_ids !== null) {
        return in_array($delivery_order->status_id, $this->status_ids);
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
        return 'Заказ не найден';
    }
}
