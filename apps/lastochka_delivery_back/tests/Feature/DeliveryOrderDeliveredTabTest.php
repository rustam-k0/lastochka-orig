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
 * Вкладка «Доставлено» приложения курьера (MID-322).
 *
 * Приложение строит вкладки из delivery_orders_paginate и ждёт там назначения
 * со status_id = 4. Но после DeliveryFinish последнего заказа рейс
 * (deliveries) получает finished_at, а скоуп auth назначения идёт через
 * whereHas('delivery') с GlobalScope NotFinished — завершённые назначения
 * закрытого рейса пропадали из всех трёх запросов курьера.
 */
class DeliveryOrderDeliveredTabTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private int $shopId;
  private int $clientId;
  private User $courier;

  protected function setUp(): void
  {
    parent::setUp();

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Delivered tab shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
      'slot_max_orders' => 10, 'slot_time' => 30, 'is_popular' => 0,
      'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 0, 'is_bonus_enabled' => 0,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->clientId = \DB::table('clients')->insertGetId([
      'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->courier = $this->makeCourier();
  }

  private function makeCourier(): User
  {
    $userId = \DB::table('users')->insertGetId([
      'name' => 'Курьер', 'surname' => 'Тестовый ' . uniqid(), 'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    \DB::table('shifts')->insert([
      'user_id' => $userId, 'shop_id' => $this->shopId, 'finished_at' => null,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $courier = User::findOrFail($userId);
    $courier->assignRole(Role::findOrCreate(Role::COURIER, 'api'));

    return $courier;
  }

  private function makeDelivery(User $courier, bool $finished): int
  {
    return \DB::table('deliveries')->insertGetId([
      'user_id' => $courier->id,
      'started_at' => now()->subHour(),
      'finished_at' => $finished ? now()->subMinutes(5) : null,
      'created_at' => now(), 'updated_at' => now(),
    ]);
  }

  private function assign(int $deliveryId, int $statusId, ?\Carbon\Carbon $finishedAt = null): int
  {
    $orderId = \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'delivered-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => Type::DELIVERY_COURIER,
      'client_id' => $this->clientId,
      'status_id' => $statusId === Status::DELIVERY_FINISHED ? Status::ORDER_FINISHED : Status::ORDER_IN_DELIVERY,
      'slot_start_date' => now()->startOfDay()->setTime(19, 0),
      'slot_end_date' => now()->startOfDay()->setTime(19, 30),
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $isClosed = in_array($statusId, [Status::DELIVERY_FINISHED, Status::DELIVERY_CANCELED], true);

    return \DB::table('delivery_orders')->insertGetId([
      'delivery_id' => $deliveryId,
      'order_id' => $orderId,
      'status_id' => $statusId,
      'priority' => 0,
      'finished_at' => $isClosed ? ($finishedAt ?? now()->subMinutes(5)) : null,
      'created_at' => now(), 'updated_at' => now(),
    ]);
  }

  /**
   * @return array<int,int> id назначений в порядке выдачи
   */
  private function ids(string $query, string $args = ''): array
  {
    Sanctum::actingAs($this->courier, [], 'api');

    if ($query === 'delivery_orders_paginate') {
      $args = $args === '' ? 'first: 100' : "first: 100, {$args}";
      $response = $this->graphQL("query { delivery_orders_paginate({$args}) { data { id status_id order { id } } } }");
      $rows = $response->json('data.delivery_orders_paginate.data');
    } else {
      $args = $args === '' ? '' : "({$args})";
      $response = $this->graphQL("query { delivery_orders{$args} { id status_id order { id } } }");
      $rows = $response->json('data.delivery_orders');
    }

    $this->assertNull($response->json('errors'), json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));

    return array_map('intval', array_column($rows ?? [], 'id'));
  }

  /** @test */
  public function finished_assignment_of_closed_trip_is_listed()
  {
    $finished = $this->assign($this->makeDelivery($this->courier, true), Status::DELIVERY_FINISHED);

    $this->assertContains($finished, $this->ids('delivery_orders_paginate', 'status_id: [4]'));
    $this->assertContains($finished, $this->ids('delivery_orders', 'status_id: [4]'));

    Sanctum::actingAs($this->courier, [], 'api');
    $this->graphQL("query { delivery_orders_item(id: {$finished}) { id status_id order { id } } }")
      ->assertJsonPath('data.delivery_orders_item.id', $finished)
      ->assertJsonPath('data.delivery_orders_item.status_id', Status::DELIVERY_FINISHED);
  }

  /**
   * Закрытый рейс с незакрытыми назначениями (старые данные, закрытие рейса
   * из админки) не должен возвращать их во вкладки «К доставке» / «В пути»;
   * отменённые назначения закрытого рейса тоже не показываем.
   *
   * @test
   */
  public function unfinished_and_cancelled_assignments_of_closed_trip_stay_hidden()
  {
    $trip = $this->makeDelivery($this->courier, true);
    $stale = $this->assign($trip, Status::DELIVERY_WAIT_TO_START);
    $cancelled = $this->assign($trip, Status::DELIVERY_CANCELED);

    $ids = $this->ids('delivery_orders_paginate');

    $this->assertNotContains($stale, $ids);
    $this->assertNotContains($cancelled, $ids);
  }

  /** @test */
  public function other_courier_finished_assignments_are_not_listed()
  {
    $foreign = $this->assign($this->makeDelivery($this->makeCourier(), true), Status::DELIVERY_FINISHED);

    $this->assertNotContains($foreign, $this->ids('delivery_orders_paginate', 'status_id: [4]'));
  }

  /**
   * Вкладка «В пути» приложения (inDeliveryTabQuery, takedToDeliveryOrdersPaginate)
   * запрашивает delivery_orders* без status_id. Без фильтра — только текущий
   * рейс, как до MID-322: иначе вся история доставок курьера (прод 07.10:
   * 228 заказов) попадала в «В пути». Доставленные текущего рейса остаются.
   *
   * @test
   */
  public function without_status_filter_only_current_trip_is_listed()
  {
    $closed = $this->assign($this->makeDelivery($this->courier, true), Status::DELIVERY_FINISHED);
    $trip = $this->makeDelivery($this->courier, false);
    $active = $this->assign($trip, Status::DELIVERY_IN_PROGRESS);
    $deliveredInTrip = $this->assign($trip, Status::DELIVERY_FINISHED);

    foreach (['delivery_orders_paginate', 'delivery_orders'] as $query) {
      $ids = $this->ids($query);
      $this->assertNotContains($closed, $ids, $query);
      $this->assertContains($active, $ids, $query);
      $this->assertContains($deliveredInTrip, $ids, $query);
    }
  }

  /** @test */
  public function status_filter_returns_only_requested_statuses()
  {
    $active = $this->assign($this->makeDelivery($this->courier, false), Status::DELIVERY_WAIT_TO_START);
    $finished = $this->assign($this->makeDelivery($this->courier, true), Status::DELIVERY_FINISHED);

    $delivered = $this->ids('delivery_orders_paginate', 'status_id: [4]');
    $this->assertContains($finished, $delivered);
    $this->assertNotContains($active, $delivered);

    $route = $this->ids('delivery_orders', 'status_id: [1, 2, 3]');
    $this->assertContains($active, $route);
    $this->assertNotContains($finished, $route);
  }

  /**
   * История доставленных не вытесняет текущий маршрут со страницы: сначала
   * незавершённые назначения, затем доставленные от свежих к старым.
   *
   * @test
   */
  public function delivered_go_after_route_newest_first()
  {
    $older = $this->assign($this->makeDelivery($this->courier, true), Status::DELIVERY_FINISHED, now()->subDay());
    $newer = $this->assign($this->makeDelivery($this->courier, true), Status::DELIVERY_FINISHED, now()->subHour());
    $active = $this->assign($this->makeDelivery($this->courier, false), Status::DELIVERY_WAIT_TO_START);

    $mine = [$older, $newer, $active];
    $ids = array_values(array_intersect($this->ids('delivery_orders_paginate', 'status_id: [1, 2, 3, 4]'), $mine));

    $this->assertSame([$active, $newer, $older], $ids);
  }
}
