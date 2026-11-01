<?php

namespace App\Aggregator\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Бросается из OrderEventTransformer::afterCreate ПОСЛЕ создания заказа и его позиций.
 * Безусловно — в форках без aggregator-listener'а это no-op.
 *
 * Слушатель OrderAggregatorSplitter (регистрируется только при aggregator_enabled)
 * расщепляет заказ на дочерние по магазину-поставщику (см. §6.5).
 *
 * @property Order $order
 * @property array<string, mixed> $payload Исходный payload входящего заказа (items с external_shop_code/marketplace_product_id)
 */
class OrderPlaced
{
  use Dispatchable, SerializesModels;

  public Order $order;

  /** @var array<string, mixed> */
  public array $payload;

  /**
   * @param array<string, mixed> $payload
   */
  public function __construct(Order $order, array $payload = [])
  {
    $this->order = $order;
    $this->payload = $payload;
  }
}
