<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Модуль «Самовывоз без сборщиков» (pickup_skip_collectors, MID-192).
 *
 * При включённом модуле заказы с типом «Самовывоз» не передаются сборщикам:
 * скрыты из выдачи orders_paginate, пуш о новом заказе не отправляется,
 * самообслуживание OrderPrepare отклоняется валидатором. Такие заказы
 * обрабатываются на стороне 1С; назначение из админки (REST) остаётся доступным.
 */
class PickupSkipCollectorsTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private int $shopId;
  private int $clientId;
  private User $collector;

  protected function setUp(): void
  {
    parent::setUp();

    // default=1 — fallback-фильтр ShopService(null) в scopeCurrentShop
    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'PSC shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
      'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
      'is_pickup_enabled' => 1, 'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 0, 'is_bonus_enabled' => 0,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->clientId = \DB::table('clients')->insertGetId([
      'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->collector = $this->makeStaff('collector');
  }

  private function makeStaff(string $roleName): User
  {
    $userId = \DB::table('users')->insertGetId([
      'name' => ucfirst($roleName), 'surname' => 'Тестовый ' . uniqid(),
      'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    \DB::table('shifts')->insert([
      'user_id' => $userId,
      'shop_id' => $this->shopId,
      'finished_at' => null,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $user = User::findOrFail($userId);
    $user->assignRole(Role::findOrCreate($roleName, 'api'));

    return $user;
  }

  private function insertOrder(int $typeId, int $statusId = Status::ORDER_CREATED): int
  {
    return \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'psc-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => $typeId,
      'client_id' => $this->clientId,
      'status_id' => $statusId,
      'slot_start_date' => now(), 'slot_end_date' => now(),
      'created_at' => now(), 'updated_at' => now(),
    ]);
  }

  private function setModule(bool $enabled): void
  {
    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode(['pickup_skip_collectors' => ['label' => 'Самовывоз без сборщиков', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
    );
  }

  private function collectorOrderIds(): array
  {
    Sanctum::actingAs($this->collector, [], 'api');

    $response = $this->graphQL(/** @lang GraphQL */ '
      query { orders_paginate(status: 1, first: 100) { data { id } } }
    ');

    return array_map('intval', array_column($response->json('data.orders_paginate.data') ?? [], 'id'));
  }

  /** @test */
  public function collector_sees_pickup_orders_when_module_disabled()
  {
    $this->setModule(false);
    $pickupId = $this->insertOrder(Type::DELIVERY_PICKUP);
    $courierId = $this->insertOrder(Type::DELIVERY_COURIER);

    $ids = $this->collectorOrderIds();

    $this->assertContains($pickupId, $ids);
    $this->assertContains($courierId, $ids);
  }

  /** @test */
  public function collector_does_not_see_pickup_orders_when_module_enabled()
  {
    $this->setModule(true);
    $pickupId = $this->insertOrder(Type::DELIVERY_PICKUP);
    $courierId = $this->insertOrder(Type::DELIVERY_COURIER);

    $ids = $this->collectorOrderIds();

    $this->assertNotContains($pickupId, $ids);
    $this->assertContains($courierId, $ids);
  }

  /** @test */
  public function order_prepare_rejected_for_pickup_when_module_enabled()
  {
    $this->setModule(true);
    $pickupId = $this->insertOrder(Type::DELIVERY_PICKUP);

    Sanctum::actingAs($this->collector, [], 'api');

    $response = $this->graphQL(/** @lang GraphQL */ '
      mutation ($id: Int) { OrderPrepare(id: $id) { code message } }
    ', ['id' => $pickupId]);

    $this->assertStringContainsString(
      'не передаются сборщикам',
      json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
    );
    $this->assertSame(Status::ORDER_CREATED, (int) Order::findOrFail($pickupId)->status_id);
  }

  /** @test */
  public function order_prepare_allowed_for_pickup_when_module_disabled()
  {
    $this->setModule(false);
    $pickupId = $this->insertOrder(Type::DELIVERY_PICKUP);

    Sanctum::actingAs($this->collector, [], 'api');

    $response = $this->graphQL(/** @lang GraphQL */ '
      mutation ($id: Int) { OrderPrepare(id: $id) { code message } }
    ', ['id' => $pickupId]);

    $response->assertJson(['data' => ['OrderPrepare' => ['code' => 200]]]);
    $this->assertSame(Status::ORDER_PREPARING, (int) Order::findOrFail($pickupId)->status_id);
  }

  /** @test */
  public function new_order_push_skipped_for_pickup_when_module_enabled()
  {
    $this->setModule(true);
    $pickupId = $this->insertOrder(Type::DELIVERY_PICKUP);

    $this->mock(FcmNotificationService::class)
      ->shouldNotReceive('sendToUsers');

    config(['services.system.token' => 'test-token']);
    $this->postJson('/backend-delivery/notification', [
      'token' => 'test-token',
      'order_id' => $pickupId,
    ])->assertOk();
  }

  /** @test */
  public function new_order_push_sent_for_pickup_when_module_disabled()
  {
    $this->setModule(false);
    $pickupId = $this->insertOrder(Type::DELIVERY_PICKUP);

    $this->mock(FcmNotificationService::class)
      ->shouldReceive('sendToUsers')->once();

    config(['services.system.token' => 'test-token']);
    $this->postJson('/backend-delivery/notification', [
      'token' => 'test-token',
      'order_id' => $pickupId,
    ])->assertOk();
  }

  /** @test */
  public function new_order_push_sent_for_courier_order_when_module_enabled()
  {
    $this->setModule(true);
    $courierId = $this->insertOrder(Type::DELIVERY_COURIER);

    $this->mock(FcmNotificationService::class)
      ->shouldReceive('sendToUsers')->once();

    config(['services.system.token' => 'test-token']);
    $this->postJson('/backend-delivery/notification', [
      'token' => 'test-token',
      'order_id' => $courierId,
    ])->assertOk();
  }
}
