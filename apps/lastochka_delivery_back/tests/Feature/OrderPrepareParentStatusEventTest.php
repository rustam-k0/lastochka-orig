<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Order\OrderPrepare;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Регрессионный тест: взятие ПОДзаказа в сборку обязано переводить родителя
 * в ORDER_PREPARING через Eloquent-update, чтобы outbox-observer опубликовал
 * order.updated(assembling) по родителю.
 *
 * События самих подзаказов заблокированы OrderNotSubOrderCondition (storefront
 * не знает uuid вида «55-1»), поэтому событие родителя — единственный канал,
 * по которому клиент видит «Заказ собирается». Mass-update через
 * Order::whereId()->update() события не триггерит — outbox молчит.
 */
class OrderPrepareParentStatusEventTest extends TestCase
{
  use DatabaseTransactions;

  private int $shopId;
  private int $clientId;
  private int $collectorId;

  protected function setUp(): void
  {
    // static-флаг пакета переживает пересоздание app между тестами —
    // без сброса до создания приложения observers регистрируются только
    // в первом тесте процесса и события молча не пишутся в outbox.
    $prop = new \ReflectionProperty(
      \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class,
      'observersRegistered'
    );
    $prop->setAccessible(true);
    $prop->setValue(null, false);

    parent::setUp();

    $this->clientId = \DB::table('clients')->insertGetId([
      'is_authorized' => 1,
      'newsletter_subscribed' => 0,
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Prepare shop',
      'id_1c' => 999996,
      'default' => 1,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 0,
      'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 0,
      'is_bonus_enabled' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->collectorId = \DB::table('users')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function insertOrder(array $attributes = []): int
  {
    return \DB::table('orders')->insertGetId(array_merge([
      'shop_id' => $this->shopId,
      'uuid' => 'prep-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $this->clientId,
      'status_id' => Status::ORDER_CREATED,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attributes));
  }

  private function parentOutboxRows(int $parentId)
  {
    return \DB::table('event_outbox')
      ->where('entity_type', 'orders')
      ->where('entity_id', $parentId)
      ->where('event_type', 'updated')
      ->orderByDesc('id')
      ->get();
  }

  public function test_preparing_sub_order_publishes_parent_assembling_event(): void
  {
    $parentId = $this->insertOrder();
    $childId = $this->insertOrder([
      'parent_order_id' => $parentId,
      'sub_number' => 1,
      'uuid' => 'prep-' . uniqid() . '-1',
    ]);

    $before = $this->parentOutboxRows($parentId)->count();

    OrderPrepare::orderPrepare(
      Order::with('orderItems')->findOrFail($childId),
      $this->collectorId
    );

    $this->assertSame(
      Status::ORDER_PREPARING,
      (int) \DB::table('orders')->where('id', $parentId)->value('status_id'),
      'Родитель должен перейти в ORDER_PREPARING'
    );

    $rows = $this->parentOutboxRows($parentId);
    $this->assertSame(
      $before + 1,
      $rows->count(),
      'Eloquent-update родителя должен записать order.updated в event_outbox'
    );

    $payload = json_decode($rows->first()->payload, true);
    $this->assertSame('assembling', $payload['status'] ?? null);
  }

  public function test_preparing_second_sub_order_does_not_duplicate_parent_event(): void
  {
    $parentId = $this->insertOrder(['status_id' => Status::ORDER_PREPARING]);
    $childId = $this->insertOrder([
      'parent_order_id' => $parentId,
      'sub_number' => 2,
      'uuid' => 'prep-' . uniqid() . '-2',
    ]);

    $before = $this->parentOutboxRows($parentId)->count();

    OrderPrepare::orderPrepare(
      Order::with('orderItems')->findOrFail($childId),
      $this->collectorId
    );

    $this->assertSame(
      $before,
      $this->parentOutboxRows($parentId)->count(),
      'Родитель уже в PREPARING — повторное событие не публикуется'
    );
  }

  public function test_preparing_sub_order_logs_parent_history(): void
  {
    $parentId = $this->insertOrder();
    $childId = $this->insertOrder([
      'parent_order_id' => $parentId,
      'sub_number' => 1,
      'uuid' => 'prep-' . uniqid() . '-1',
    ]);

    $child = Order::with('orderItems')->findOrFail($childId);

    OrderPrepare::orderPrepare($child, $this->collectorId);

    $row = OrderHistory::where('order_id', $parentId)->where('action', OrderHistory::ACTION_STATUS_CHANGED)->first();
    $this->assertNotNull($row);
    $this->assertSame(OrderHistory::ACTOR_SYSTEM, $row->actor_type);
    $this->assertSame('Создан', $row->old_value);
    $this->assertSame('Заказ на сборке', $row->new_value);
    $this->assertStringContainsString($child->label, $row->comment);
  }
}
