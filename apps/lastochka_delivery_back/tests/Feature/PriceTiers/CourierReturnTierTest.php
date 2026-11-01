<?php

namespace Tests\Feature\PriceTiers;

use App\GraphQL\Mutations\Delivery\DeliveryFinish;
use App\GraphQL\Mutations\Delivery\DeliveryTakeOrder;
use App\GraphQL\Mutations\Order\OrderItemReturnByCourier;
use App\GraphQL\Mutations\Order\OrderItemReturnCancelByCourier;
use App\Models\Order;
use App\Models\Status;
use App\Services\CourierReturnService;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Возврат у двери (MID-308): клиент отказался от части штучной позиции, сумма
 * доставленного = ступень для доставленного количества × это количество.
 */
class CourierReturnTierTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  private int $shopId;
  private int $clientId;

  protected function setUp(): void
  {
    parent::setUp();

    $fcm = $this->mock(FcmNotificationService::class);
    $fcm->shouldReceive('sendToUser')->andReturnNull();
    $fcm->shouldReceive('sendToUsers')->andReturnNull();

    $this->shopId = $this->insertShop();
    $this->clientId = $this->insertClient();
  }

  /**
   * Собранный заказ: 5 × 199 = 995 + доставка 100.
   *
   * @return array{0:int,1:int} [orderId, orderItemId]
   */
  private function assembledOrder(?string $snapshot, int $statusId = Status::ORDER_IN_DELIVERY): array
  {
    $orderId = $this->insertOrder($this->shopId, $this->clientId, [
      'status_id' => $statusId,
      'amount' => 1095,
      'items_subtotal' => 995,
      'delivery_price' => 100,
      'is_heavy' => 0,
      'slot_start_date' => now(),
      'slot_end_date' => now(),
    ]);

    $orderItemId = $this->insertOrderItem($orderId, $this->insertProduct(), [
      'quantity' => 5,
      'returned_quantity' => 0,
      'price' => 995,
      'price_during_order' => 199,
      'price_old' => 279,
      'is_eligible' => 0,
      'price_tiers' => $snapshot,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    return [$orderId, $orderItemId];
  }

  private function deliveryOrderFor(int $orderId): int
  {
    $deliveryId = \DB::table('deliveries')->insertGetId([
      'user_id' => $this->insertUser(),
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return \DB::table('delivery_orders')->insertGetId([
      'delivery_id' => $deliveryId,
      'order_id' => $orderId,
      'status_id' => Status::DELIVERY_IN_PROGRESS,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function order(int $orderId): object
  {
    return \DB::table('orders')->where('id', $orderId)->first();
  }

  public function test_partial_return_moves_delivered_part_to_more_expensive_tier(): void
  {
    $this->setPriceTiersFlag(true);
    [$orderId, $orderItemId] = $this->assembledOrder($this->snapshotJson(self::$LADDER_FROM_ONE));
    $deliveryOrderId = $this->deliveryOrderFor($orderId);

    (new OrderItemReturnByCourier())(null, [
      'delivery_order_id' => $deliveryOrderId,
      'order_item_id' => $orderItemId,
      'quantity' => 2,
      'reason_id' => null,
    ]);

    $order = $this->order($orderId);
    $this->assertSame(747.0, (float) $order->items_subtotal, 'доставлено 3 шт: 3 × 249, а не 3 × 199');
    $this->assertSame(847.0, (float) $order->amount);

    $item = \DB::table('order_items')->where('id', $orderItemId)->first();
    $this->assertSame(2, (int) $item->returned_quantity);
    $this->assertSame(199.0, (float) $item->price_during_order, 'позиция при возврате не переписывается');
  }

  public function test_return_down_to_one_unit_costs_regular_price(): void
  {
    $this->setPriceTiersFlag(true);
    [$orderId, $orderItemId] = $this->assembledOrder($this->snapshotJson(self::$LADDER_FROM_ONE));
    $deliveryOrderId = $this->deliveryOrderFor($orderId);

    (new OrderItemReturnByCourier())(null, [
      'delivery_order_id' => $deliveryOrderId,
      'order_item_id' => $orderItemId,
      'quantity' => 4,
      'reason_id' => null,
    ]);

    $this->assertSame(279.0, (float) $this->order($orderId)->items_subtotal);
  }

  public function test_full_return_is_zero(): void
  {
    $this->setPriceTiersFlag(true);
    [$orderId, $orderItemId] = $this->assembledOrder($this->snapshotJson(self::$LADDER_FROM_ONE));
    $deliveryOrderId = $this->deliveryOrderFor($orderId);

    (new OrderItemReturnByCourier())(null, [
      'delivery_order_id' => $deliveryOrderId,
      'order_item_id' => $orderItemId,
      'quantity' => 5,
      'reason_id' => null,
    ]);

    $this->assertSame(0.0, (float) $this->order($orderId)->items_subtotal);
  }

  public function test_cancelled_return_restores_full_amount(): void
  {
    $this->setPriceTiersFlag(true);
    [$orderId, $orderItemId] = $this->assembledOrder($this->snapshotJson(self::$LADDER_FROM_ONE));
    $deliveryOrderId = $this->deliveryOrderFor($orderId);

    (new OrderItemReturnByCourier())(null, [
      'delivery_order_id' => $deliveryOrderId,
      'order_item_id' => $orderItemId,
      'quantity' => 2,
      'reason_id' => null,
    ]);
    (new OrderItemReturnCancelByCourier())(null, [
      'delivery_order_id' => $deliveryOrderId,
      'order_item_id' => $orderItemId,
    ]);

    $order = $this->order($orderId);
    $this->assertSame(995.0, (float) $order->items_subtotal);
    $this->assertSame(1095.0, (float) $order->amount);
  }

  public function test_delivered_items_total_mixes_tiered_and_regular_items(): void
  {
    $this->setPriceTiersFlag(true);
    [$orderId, $orderItemId] = $this->assembledOrder($this->snapshotJson(self::$LADDER_FROM_ONE));
    \DB::table('order_items')->where('id', $orderItemId)->update(['returned_quantity' => 2]);
    // Обычная позиция без лесенки: 4 × 50, возвращена 1
    $this->insertOrderItem($orderId, $this->insertProduct(), [
      'quantity' => 4, 'returned_quantity' => 1, 'price' => 200, 'price_during_order' => 50,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $total = CourierReturnService::deliveredItemsTotal(
      CourierReturnService::billableItems(Order::findOrFail($orderId))
    );

    $this->assertSame(897.0, $total, '3 × 249 + 3 × 50');
  }

  public function test_delivery_finish_settles_order_by_tier_of_delivered_quantity(): void
  {
    $this->setPriceTiersFlag(true);
    [$orderId, $orderItemId] = $this->assembledOrder($this->snapshotJson(self::$LADDER_FROM_ONE), Status::ORDER_ASSEMBLED);

    $taken = DeliveryTakeOrder::takeOrder($this->insertUser(), $orderId);
    $taken['delivery']->update(['started_at' => now()]);
    $taken['delivery_order']->update(['status_id' => Status::DELIVERY_IN_PROGRESS, 'started_at' => now()]);
    Order::whereId($orderId)->update(['status_id' => Status::ORDER_IN_DELIVERY]);
    \DB::table('order_items')->where('id', $orderItemId)->update(['returned_quantity' => 2]);

    $deliveryOrder = $taken['delivery_order']->fresh();
    DeliveryFinish::deliveryFinish($deliveryOrder, $deliveryOrder->delivery);

    $order = $this->order($orderId);
    $this->assertSame(Status::ORDER_FINISHED, (int) $order->status_id);
    $this->assertSame(747.0, (float) $order->items_subtotal);
    $this->assertSame(847.0, (float) $order->amount);
  }

  public function test_existing_snapshot_is_used_even_when_flag_is_off(): void
  {
    $this->setPriceTiersFlag(false);
    [$orderId, $orderItemId] = $this->assembledOrder($this->snapshotJson(self::$LADDER_FROM_ONE));
    $deliveryOrderId = $this->deliveryOrderFor($orderId);

    (new OrderItemReturnByCourier())(null, [
      'delivery_order_id' => $deliveryOrderId,
      'order_item_id' => $orderItemId,
      'quantity' => 2,
      'reason_id' => null,
    ]);

    $this->assertSame(747.0, (float) $this->order($orderId)->items_subtotal, 'флаг гейтит только новые снапшоты');
  }

  public function test_item_without_snapshot_keeps_old_formula(): void
  {
    $this->setPriceTiersFlag(true);
    [$orderId, $orderItemId] = $this->assembledOrder(null);
    $deliveryOrderId = $this->deliveryOrderFor($orderId);

    (new OrderItemReturnByCourier())(null, [
      'delivery_order_id' => $deliveryOrderId,
      'order_item_id' => $orderItemId,
      'quantity' => 2,
      'reason_id' => null,
    ]);

    $this->assertSame(597.0, (float) $this->order($orderId)->items_subtotal);
  }
}
