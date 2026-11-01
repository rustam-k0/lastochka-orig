<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\GraphQL\Mutations\Delivery\DeliveryFinish;
use App\GraphQL\Mutations\Delivery\DeliveryTakeOrder;
use App\GraphQL\Mutations\Order\OrderItemReturnByCourier;
use App\Models\Order;
use App\Models\Status;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MID-280: возврат у двери пересчитывает доставку по снимку условий заказа от суммы
 * доставленного: не дороже цены оформления. Прежний пересчёт (порог живой зоны,
 * только обнуление) у заказа со снимком не участвует.
 */
class CourierReturnDeliveryPriceTest extends TestCase
{
  use DatabaseTransactions, DeliveryPriceRangesFixtures;

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
    // Живая зона с порогом 500: прежний пересчёт обнулил бы доставку при любом возврате ниже
    $this->insertZone($this->shopId, 300, null, ['free_delivery_from' => 500]);
  }

  /**
   * Собранный заказ 5 × 800 = 4000, доставка после сборки 0.
   *
   * @return array{0:int,1:int} [orderId, orderItemId]
   */
  private function assembledOrder(int $checkoutPrice, int $statusId = Status::ORDER_IN_DELIVERY): array
  {
    $orderId = $this->insertOrder($this->shopId, $this->clientId, [
      'status_id' => $statusId,
      'amount' => 4000,
      'items_subtotal' => 4000,
      'delivery_price' => 0,
      'delivery_price_snapshot' => $this->snapshotJson($checkoutPrice),
    ]);

    $orderItemId = $this->insertOrderItem($orderId, 800, 5, [
      'price' => 4000,
      'returned_quantity' => 0,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    return [$orderId, $orderItemId];
  }

  private function deliveryOrderFor(int $orderId): int
  {
    $deliveryId = \DB::table('deliveries')->insertGetId([
      'user_id' => $this->insertCollector($this->shopId)->id,
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

  public function test_return_below_ranges_restores_checkout_price(): void
  {
    // Оформлено на 1500 → 100; собрано 4000 → 0; клиент вернул 3 шт → доставлено 1600:
    // по лесенке 100, не дороже оформления 100
    [$orderId, $orderItemId] = $this->assembledOrder(100);

    (new OrderItemReturnByCourier())(null, [
      'delivery_order_id' => $this->deliveryOrderFor($orderId),
      'order_item_id' => $orderItemId,
      'quantity' => 3,
      'reason_id' => null,
    ]);

    $order = $this->orderRow($orderId);
    $this->assertSame(100, (int) $order->delivery_price);
    $this->assertSame(1700.0, (float) $order->amount);
  }

  public function test_free_at_checkout_stays_free_after_return(): void
  {
    // Страж (зелёный и до правки): оформлено бесплатно → после возврата бесплатно
    [$orderId, $orderItemId] = $this->assembledOrder(0);

    (new OrderItemReturnByCourier())(null, [
      'delivery_order_id' => $this->deliveryOrderFor($orderId),
      'order_item_id' => $orderItemId,
      'quantity' => 4,
      'reason_id' => null,
    ]);

    $order = $this->orderRow($orderId);
    $this->assertSame(0, (int) $order->delivery_price);
    $this->assertSame(800.0, (float) $order->amount);
  }

  public function test_delivery_finish_settles_delivery_by_snapshot(): void
  {
    [$orderId, $orderItemId] = $this->assembledOrder(100, Status::ORDER_ASSEMBLED);

    $taken = DeliveryTakeOrder::takeOrder($this->insertCollector($this->shopId)->id, $orderId);
    $taken['delivery']->update(['started_at' => now()]);
    $taken['delivery_order']->update(['status_id' => Status::DELIVERY_IN_PROGRESS, 'started_at' => now()]);
    Order::whereId($orderId)->update(['status_id' => Status::ORDER_IN_DELIVERY]);
    \DB::table('order_items')->where('id', $orderItemId)->update(['returned_quantity' => 3]);

    $deliveryOrder = $taken['delivery_order']->fresh();
    DeliveryFinish::deliveryFinish($deliveryOrder, $deliveryOrder->delivery);

    $order = $this->orderRow($orderId);
    $this->assertSame(100, (int) $order->delivery_price);
    $this->assertSame(1700.0, (float) $order->amount);
  }
}
