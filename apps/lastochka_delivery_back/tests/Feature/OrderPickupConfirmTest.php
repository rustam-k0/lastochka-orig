<?php

namespace Tests\Feature;

use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Выдача самовывоза сборщиком из мобильного приложения.
 *
 * Сборщик видит во вкладке «Собрано» только свои сборки, поэтому и выдать
 * может только собранный им заказ. Мутация повторяет семантику
 * OrderPickupConfirm в purchase_back (кнопка «Заказ выдан» в админке):
 * статус 5, picked_up_at/by, finished_at, история; при сплите по зонам
 * сборки работает с корневым заказом и синхронизирует подзаказы.
 */
class OrderPickupConfirmTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private int $shopId;
  private int $clientId;
  private int $collectorId;

  protected function setUp(): void
  {
    parent::setUp();

    $this->clientId = \DB::table('clients')->insertGetId([
      'is_authorized' => 1,
      'newsletter_subscribed' => 0,
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // default=1 — fallback-фильтр ShopService(null) в scopeCurrentShop
    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Pickup confirm shop',
      'id_1c' => random_int(900000, 999999),
      'default' => 1,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 1,
      'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 1,
      'is_bonus_enabled' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->collectorId = $this->insertUser();
  }

  private function insertUser(): int
  {
    return \DB::table('users')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function insertOrder(array $attributes = []): int
  {
    return \DB::table('orders')->insertGetId(array_merge([
      'shop_id' => $this->shopId,
      'uuid' => 'pc-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => Type::DELIVERY_PICKUP,
      'client_id' => $this->clientId,
      'status_id' => Status::ORDER_ASSEMBLED,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attributes));
  }

  private function insertFinishedAssembly(int $orderId, ?int $userId = null): int
  {
    return \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $userId ?? $this->collectorId,
      'finished_at' => now(),
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function actingAsCollectorOnShift(?int $userId = null): void
  {
    $userId ??= $this->collectorId;

    \DB::table('shifts')->insert([
      'user_id' => $userId,
      'shop_id' => $this->shopId,
      'finished_at' => null,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    Sanctum::actingAs(\App\Models\User::findOrFail($userId), [], 'api');
  }

  private function confirmPickup(int $orderId)
  {
    return $this->graphQL(/** @lang GraphQL */ '
      mutation ($id: Int!) {
        OrderPickupConfirm(id: $id) {
          code
          message
        }
      }
    ', ['id' => $orderId]);
  }

  private function errorMessages(array $json): string
  {
    return json_encode($json['errors'] ?? [], JSON_UNESCAPED_UNICODE);
  }

  public function test_collector_confirms_pickup_and_order_is_finished(): void
  {
    $orderId = $this->insertOrder();
    $this->insertFinishedAssembly($orderId);
    $this->actingAsCollectorOnShift();

    $response = $this->confirmPickup($orderId);

    $response->assertJson(['data' => ['OrderPickupConfirm' => ['code' => 200]]]);

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(Status::ORDER_FINISHED, (int) $order->status_id);
    $this->assertNotNull($order->picked_up_at);
    $this->assertSame($this->collectorId, (int) $order->picked_up_by_user_id);
    $this->assertNotNull($order->finished_at);
  }

  public function test_history_records_who_issued_the_order(): void
  {
    $orderId = $this->insertOrder();
    $this->insertFinishedAssembly($orderId);
    $this->actingAsCollectorOnShift();

    $this->confirmPickup($orderId);

    $history = \DB::table('order_history')
      ->where('order_id', $orderId)
      ->where('new_value', 'Выдан')
      ->first();

    $this->assertNotNull($history);
    $this->assertSame($this->collectorId, (int) $history->actor_id);
  }

  public function test_delivery_order_can_not_be_confirmed_as_pickup(): void
  {
    $orderId = $this->insertOrder(['type_id' => Type::DELIVERY_COURIER]);
    $this->insertFinishedAssembly($orderId);
    $this->actingAsCollectorOnShift();

    $response = $this->confirmPickup($orderId);

    $this->assertStringContainsString('самовывоз', $this->errorMessages($response->json()));
    $this->assertSame(
      Status::ORDER_ASSEMBLED,
      (int) \DB::table('orders')->where('id', $orderId)->value('status_id')
    );
  }

  public function test_order_must_be_assembled(): void
  {
    $orderId = $this->insertOrder(['status_id' => Status::ORDER_PREPARING]);
    $this->insertFinishedAssembly($orderId);
    $this->actingAsCollectorOnShift();

    $response = $this->confirmPickup($orderId);

    $this->assertStringContainsString('не собран', mb_strtolower($this->errorMessages($response->json())));
    $this->assertSame(
      Status::ORDER_PREPARING,
      (int) \DB::table('orders')->where('id', $orderId)->value('status_id')
    );
  }

  public function test_repeated_confirm_is_rejected(): void
  {
    $orderId = $this->insertOrder();
    $this->insertFinishedAssembly($orderId);
    $this->actingAsCollectorOnShift();

    $this->confirmPickup($orderId)->assertJson(['data' => ['OrderPickupConfirm' => ['code' => 200]]]);
    $firstPickedUpAt = \DB::table('orders')->where('id', $orderId)->value('picked_up_at');

    $response = $this->confirmPickup($orderId);

    $this->assertNotEmpty($response->json('errors'));
    $this->assertSame(
      $firstPickedUpAt,
      \DB::table('orders')->where('id', $orderId)->value('picked_up_at')
    );
  }

  public function test_collector_can_not_confirm_order_assembled_by_someone_else(): void
  {
    $orderId = $this->insertOrder();
    $this->insertFinishedAssembly($orderId, $this->insertUser());
    $this->actingAsCollectorOnShift();

    $response = $this->confirmPickup($orderId);

    $this->assertNotEmpty($response->json('errors'));
    $this->assertSame(
      Status::ORDER_ASSEMBLED,
      (int) \DB::table('orders')->where('id', $orderId)->value('status_id')
    );
  }

  public function test_child_order_confirm_finishes_parent_and_siblings(): void
  {
    $parentId = $this->insertOrder();
    $childOneId = $this->insertOrder(['parent_order_id' => $parentId, 'sub_number' => 1]);
    $childTwoId = $this->insertOrder(['parent_order_id' => $parentId, 'sub_number' => 2]);

    $this->insertFinishedAssembly($childOneId);
    $this->insertFinishedAssembly($childTwoId, $this->insertUser());

    $this->actingAsCollectorOnShift();

    $response = $this->confirmPickup($childOneId);

    $response->assertJson(['data' => ['OrderPickupConfirm' => ['code' => 200]]]);

    $parent = \DB::table('orders')->where('id', $parentId)->first();
    $this->assertSame(Status::ORDER_FINISHED, (int) $parent->status_id);
    $this->assertNotNull($parent->picked_up_at);
    $this->assertSame($this->collectorId, (int) $parent->picked_up_by_user_id);

    foreach ([$childOneId, $childTwoId] as $childId) {
      $child = \DB::table('orders')->where('id', $childId)->first();
      $this->assertSame(Status::ORDER_FINISHED, (int) $child->status_id);
      $this->assertNotNull($child->finished_at);
    }
  }

  public function test_child_order_is_not_issuable_until_whole_order_is_assembled(): void
  {
    $parentId = $this->insertOrder(['status_id' => Status::ORDER_PREPARING]);
    $childOneId = $this->insertOrder(['parent_order_id' => $parentId, 'sub_number' => 1]);
    $childTwoId = $this->insertOrder([
      'parent_order_id' => $parentId,
      'sub_number' => 2,
      'status_id' => Status::ORDER_PREPARING,
    ]);

    $this->insertFinishedAssembly($childOneId);
    $this->actingAsCollectorOnShift();

    $response = $this->confirmPickup($childOneId);

    $this->assertNotEmpty($response->json('errors'));
    $this->assertSame(
      Status::ORDER_PREPARING,
      (int) \DB::table('orders')->where('id', $parentId)->value('status_id')
    );
    $this->assertSame(
      Status::ORDER_ASSEMBLED,
      (int) \DB::table('orders')->where('id', $childOneId)->value('status_id')
    );
    $this->assertSame(
      Status::ORDER_PREPARING,
      (int) \DB::table('orders')->where('id', $childTwoId)->value('status_id')
    );
  }
}
