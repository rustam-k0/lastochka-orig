<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Порядок вкладки «В пути» в приложении курьера.
 *
 * Курьер сам расставляет приоритетность объезда (DeliveryOrderChangePriority,
 * выпадающий список в карточке: «Приоритетность не выбрана» = 0, дальше 1…n),
 * но запросы delivery_orders / delivery_orders_paginate сортировки не имели —
 * список оставался в порядке назначения заказов, выбранная приоритетность
 * на него не влияла.
 */
class DeliveryOrderRouteOrderingTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private int $shopId;
  private int $clientId;
  private User $courier;
  private int $deliveryId;

  protected function setUp(): void
  {
    parent::setUp();

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Route ordering shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
      'slot_max_orders' => 10, 'slot_time' => 30, 'is_popular' => 0,
      'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 0, 'is_bonus_enabled' => 0,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->clientId = \DB::table('clients')->insertGetId([
      'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $userId = \DB::table('users')->insertGetId([
      'name' => 'Курьер', 'surname' => 'Тестовый ' . uniqid(), 'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    \DB::table('shifts')->insert([
      'user_id' => $userId, 'shop_id' => $this->shopId, 'finished_at' => null,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->courier = User::findOrFail($userId);
    $this->courier->assignRole(Role::findOrCreate(Role::COURIER, 'api'));

    $this->deliveryId = \DB::table('deliveries')->insertGetId([
      'user_id' => $userId,
      'created_at' => now(), 'updated_at' => now(),
    ]);
  }

  /**
   * @param int $priority приоритетность объезда; 0 — «не выбрана»
   */
  private function assignOrder(int $priority, \Carbon\Carbon $slotStart): int
  {
    $orderId = \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'route-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => Type::DELIVERY_COURIER,
      'client_id' => $this->clientId,
      'status_id' => Status::ORDER_IN_DELIVERY,
      'slot_start_date' => $slotStart,
      'slot_end_date' => $slotStart->copy()->addMinutes(30),
      'created_at' => now(), 'updated_at' => now(),
    ]);

    return \DB::table('delivery_orders')->insertGetId([
      'delivery_id' => $this->deliveryId,
      'order_id' => $orderId,
      'status_id' => Status::DELIVERY_WAIT_TO_START,
      'priority' => $priority,
      'created_at' => now(), 'updated_at' => now(),
    ]);
  }

  /**
   * Взаимный порядок только своих назначений: в тестовой БД есть чужие строки.
   *
   * @param array<int,int> $ids
   * @param array<int,int> $mine
   * @return array<int,int>
   */
  private function onlyMine(array $ids, array $mine): array
  {
    return array_values(array_intersect($ids, $mine));
  }

  /**
   * @return array<int,int> id назначений (delivery_orders) в порядке выдачи
   */
  private function routeIds(string $query): array
  {
    Sanctum::actingAs($this->courier, [], 'api');

    $isPaginated = $query === 'delivery_orders_paginate';

    $response = $this->graphQL(/** @lang GraphQL */ $isPaginated
      ? "query { {$query}(first: 100) { data { id } } }"
      : "query { {$query} { id } }");

    $path = $isPaginated ? "data.{$query}.data" : "data.{$query}";

    return array_map('intval', array_column($response->json($path) ?? [], 'id'));
  }

  /** @test */
  public function route_list_follows_courier_priority()
  {
    $slot = now()->startOfDay()->setTime(19, 0);

    $third = $this->assignOrder(3, $slot);
    $first = $this->assignOrder(1, $slot);
    $second = $this->assignOrder(2, $slot);
    $mine = [$third, $first, $second];

    $this->assertSame([$first, $second, $third], $this->onlyMine($this->routeIds('delivery_orders'), $mine));
    $this->assertSame([$first, $second, $third], $this->onlyMine($this->routeIds('delivery_orders_paginate'), $mine));
  }

  /**
   * «Приоритетность не выбрана» (0 — значение по умолчанию при назначении)
   * уходит в конец маршрута, а не наверх, как при сортировке по возрастанию.
   *
   * @test
   */
  public function orders_without_chosen_priority_go_after_prioritized_ones()
  {
    $slot = now()->startOfDay()->setTime(19, 0);

    $notChosen = $this->assignOrder(0, $slot);
    $prioritized = $this->assignOrder(2, $slot);
    $mine = [$notChosen, $prioritized];

    $this->assertSame([$prioritized, $notChosen], $this->onlyMine($this->routeIds('delivery_orders'), $mine));
  }

  /**
   * Пока приоритетность не выбрана ни у одного заказа (обычный случай при
   * самостоятельном взятии заказов), порядок задаёт слот доставки.
   *
   * @test
   */
  public function orders_without_priority_are_sorted_by_slot()
  {
    $today = now()->startOfDay();

    $late = $this->assignOrder(0, $today->copy()->setTime(20, 0));
    $early = $this->assignOrder(0, $today->copy()->setTime(19, 30));
    $mine = [$late, $early];

    $this->assertSame([$early, $late], $this->onlyMine($this->routeIds('delivery_orders'), $mine));
  }
}
