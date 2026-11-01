<?php

namespace App\Contracts;

use App\Models\Order;

interface PaymentSystem
{
  /**
   * must return ['order_id'=>'value','payment_link'=>'value']
   * @param string $client_id
   * @param string $order_id
   * @return array
   */
  public function getFirstPayLink(string $client_id, string $order_id): array;

  public function getOrder(mixed $id): mixed;

  public function makePayment(Order $order,string $binding_id): mixed;

  public function getBindingId(string $client_id, string $binding_id): mixed;

  public function refundOrder(string $order_id);

}
