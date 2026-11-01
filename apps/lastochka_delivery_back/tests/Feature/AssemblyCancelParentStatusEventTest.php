<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyCancel;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Регрессионный тест: отмена сборки ПОДзаказа обязана возвращать родителя
 * в ORDER_CREATED (через Eloquent-update с публикацией order.updated(created)),
 * когда ни один из подзаказов больше не в работе.
 *
 * Без отката родитель навсегда остаётся в PREPARING, а storefront — в
 * assembling (события подзаказов заблокированы OrderNotSubOrderCondition,
 * статус до клиента доносит только событие родителя).
 */
class AssemblyCancelParentStatusEventTest extends TestCase
{
  use DatabaseTransactions;

  private int $shopId;
  private int $clientId;
  private int $collectorId;

  protected function setUp(): void
  {
    // static-флаг пакета переживает пересоздание app между тестами —
    // без сброса observers регистрируются только в первом тесте процесса
    // и события молча не пишутся в outbox.
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
      'title' => 'Cancel shop',
      'id_1c' => 999995,
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
      'uuid' => 'cancel-' . uniqid(),
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

  private function insertAssembly(int $orderId): int
  {
    return \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $this->collectorId,
      'finished_at' => null,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
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

  public function test_cancelling_last_sub_order_assembly_returns_parent_to_created_and_publishes_event(): void
  {
    $parentId = $this->insertOrder(['status_id' => Status::ORDER_PREPARING]);
    $childId = $this->insertOrder([
      'parent_order_id' => $parentId,
      'sub_number' => 1,
      'uuid' => 'cancel-' . uniqid() . '-1',
      'status_id' => Status::ORDER_PREPARING,
    ]);
    $assemblyId = $this->insertAssembly($childId);

    $before = $this->parentOutboxRows($parentId)->count();

    AssemblyCancel::assemblyCancel($assemblyId);

    $this->assertSame(
      Status::ORDER_CREATED,
      (int) \DB::table('orders')->where('id', $childId)->value('status_id'),
      'Подзаказ должен вернуться в ORDER_CREATED'
    );

    $this->assertSame(
      Status::ORDER_CREATED,
      (int) \DB::table('orders')->where('id', $parentId)->value('status_id'),
      'Родитель должен вернуться в ORDER_CREATED, когда подзаказы не в работе'
    );

    $rows = $this->parentOutboxRows($parentId);
    $this->assertSame(
      $before + 1,
      $rows->count(),
      'Откат родителя должен записать order.updated в event_outbox'
    );

    $payload = json_decode($rows->first()->payload, true);
    $this->assertSame('created', $payload['status'] ?? null);
  }

  public function test_parent_stays_preparing_while_sibling_sub_order_is_in_assembly(): void
  {
    $parentId = $this->insertOrder(['status_id' => Status::ORDER_PREPARING]);
    $childId = $this->insertOrder([
      'parent_order_id' => $parentId,
      'sub_number' => 1,
      'uuid' => 'cancel-' . uniqid() . '-1',
      'status_id' => Status::ORDER_PREPARING,
    ]);
    $this->insertOrder([
      'parent_order_id' => $parentId,
      'sub_number' => 2,
      'uuid' => 'cancel-' . uniqid() . '-2',
      'status_id' => Status::ORDER_PREPARING, // второй подзаказ ещё собирается
    ]);
    $assemblyId = $this->insertAssembly($childId);

    $before = $this->parentOutboxRows($parentId)->count();

    AssemblyCancel::assemblyCancel($assemblyId);

    $this->assertSame(
      Status::ORDER_PREPARING,
      (int) \DB::table('orders')->where('id', $parentId)->value('status_id'),
      'Родитель остаётся в PREPARING, пока другой подзаказ в работе'
    );

    $this->assertSame($before, $this->parentOutboxRows($parentId)->count());
  }

  public function test_cancelled_parent_is_not_resurrected_to_created(): void
  {
    $parentId = $this->insertOrder(['status_id' => Status::ORDER_CANCELLED]);
    $childId = $this->insertOrder([
      'parent_order_id' => $parentId,
      'sub_number' => 1,
      'uuid' => 'cancel-' . uniqid() . '-1',
      'status_id' => Status::ORDER_PREPARING,
    ]);
    $assemblyId = $this->insertAssembly($childId);

    $before = $this->parentOutboxRows($parentId)->count();

    AssemblyCancel::assemblyCancel($assemblyId);

    $this->assertSame(
      Status::ORDER_CANCELLED,
      (int) \DB::table('orders')->where('id', $parentId)->value('status_id'),
      'Отменённый родитель не должен воскресать в CREATED'
    );

    $this->assertSame($before, $this->parentOutboxRows($parentId)->count());
  }
}
