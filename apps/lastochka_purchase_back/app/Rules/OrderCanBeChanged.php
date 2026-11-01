<?php

namespace App\Rules;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Contracts\Validation\Rule;

class OrderCanBeChanged implements Rule
{
  private $collector_id;
  private $courier_id;

  protected $message = 'Заказ не может быть сменен';
  /**
   * Create a new rule instance.
   *
   * @return void
   */
  public function __construct($collector_id, $courier_id)
  {
    $this->collector_id = $collector_id;
    $this->courier_id = $courier_id;
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
    $order = Order::find($value);

    if (!$order) {
      return true;
    }

    return match ($order->status_id) {
      Status::ORDER_CREATED => $this->checkOrderCreated($order),
      Status::ORDER_ASSEMBLED => $this->checkOrderAssembled($order),
      Status::ORDER_PREPARING, Status::ORDER_IN_DELIVERY => true,
      default => false
    };
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

  private function checkOrderCreated(Order $order): bool
  {
    if (!$this->collector_id) {
      $this->message = 'Сборщик не выбран';
      return false;
    }

    $exists = User::withoutGlobalScope('onlyAdmin')
      ->whereRelation('shops', 'shop_id', $order->shop_id)
      ->whereId($this->collector_id)
      ->role(Role::COLLECTOR)
      ->exists();

    if (!$exists) {
      $this->message = 'Сборщик не найден';
    }

    return $exists;
  }

  private function checkOrderAssembled(Order $order): bool
  {
    if (Order::isCollectorPaymentModuleEnabled()
      && (int) $order->payment_type_id === Type::PAYMENT_CARD
      && !$order->paid
    ) {
      $this->message = 'Заказ не оплачен: спишите оплату перед назначением курьера';
      return false;
    }

    if (!$this->courier_id) {
      $this->message = 'Курьер не выбран';
      return false;
    }

    $exists =  User::withoutGlobalScope('onlyAdmin')
      ->whereRelation('shops', 'shop_id', $order->shop_id)
      ->whereId($this->courier_id)
      ->role(Role::COURIER)
      ->exists();

    if (!$exists) {
      $this->message = 'Курьер не найден';
    }

    return $exists;
  }
}
