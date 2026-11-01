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
 * Сортировка списков заказов в мобильном приложении сотрудников.
 *
 * До фикса ни orders_paginate (курьер: «На сборке», «К доставке», «Доставлено»),
 * ни collector_orders_paginate (сборщик) не сортировали выдачу: заказы шли в порядке
 * поступления в курьерку (по id). На проде 17.09 заказ № 306 со слотом 19:30–20:00
 * стоял ниже № 303 со слотом 20:00–20:30, потому что 303 оформили раньше.
 *
 * Ожидаемый порядок — по началу слота доставки, при равных слотах по поступлению.
 */
class OrderSlotOrderingTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private int $shopId;
  private int $clientId;
  private User $courier;
  private User $collector;

  protected function setUp(): void
  {
    parent::setUp();

    // default=1 — fallback-фильтр ShopService(null) в scopeCurrentShop
    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Slot ordering shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
      'slot_max_orders' => 10, 'slot_time' => 30, 'is_popular' => 0,
      'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 0, 'is_bonus_enabled' => 0,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->clientId = \DB::table('clients')->insertGetId([
      'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->courier = $this->makeStaff(Role::COURIER);
    $this->collector = $this->makeStaff(Role::COLLECTOR);
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

  /**
   * @param \Carbon\Carbon|null $slotStart начало слота; null — заказ без слота
   */
  private function insertOrder(?\Carbon\Carbon $slotStart): int
  {
    $orderId = \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'sort-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => Type::DELIVERY_COURIER,
      'client_id' => $this->clientId,
      'status_id' => Status::ORDER_ASSEMBLED,
      'slot_start_date' => $slotStart,
      'slot_end_date' => $slotStart?->copy()->addMinutes(30),
      'created_at' => now(), 'updated_at' => now(),
    ]);

    // Собранный заказ виден сборщику только через свою сборку (Order::scopeAuth)
    \DB::table('assemblies')->insert([
      'order_id' => $orderId,
      'user_id' => $this->collector->id,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    return $orderId;
  }

  /**
   * Заказы прода 17.09: № 303 оформлен первым, но на слот 20:00,
   * № 306 оформлен позже, а слот у него самый ранний — 19:30.
   *
   * @return array{0:int,1:int,2:int,3:int} id заказов в порядке поступления
   */
  private function insertProdCase(): array
  {
    $today = now()->startOfDay();

    return [
      $this->insertOrder($today->copy()->setTime(20, 0)),  // № 303
      $this->insertOrder($today->copy()->setTime(19, 30)), // № 306
      $this->insertOrder($today->copy()->setTime(20, 0)),  // № 307
      $this->insertOrder($today->copy()->setTime(20, 30)), // № 308
    ];
  }

  /**
   * Взаимный порядок только своих заказов: в тестовой БД есть чужие строки.
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
   * @return array<int,int>
   */
  private function paginateIds(User $user, string $query, int $status): array
  {
    Sanctum::actingAs($user, [], 'api');

    $response = $this->graphQL(/** @lang GraphQL */ "
      query { {$query}(status: {$status}, first: 100) { data { id } } }
    ");

    return array_map('intval', array_column($response->json("data.{$query}.data") ?? [], 'id'));
  }

  /** @test */
  public function courier_orders_are_sorted_by_slot_start_not_by_creation()
  {
    [$o303, $o306, $o307, $o308] = $this->insertProdCase();
    $mine = [$o303, $o306, $o307, $o308];

    $ids = $this->paginateIds($this->courier, 'orders_paginate', Status::ORDER_ASSEMBLED);

    $this->assertSame([$o306, $o303, $o307, $o308], $this->onlyMine($ids, $mine));
  }

  /** @test */
  public function collector_orders_are_sorted_by_slot_start_not_by_creation()
  {
    [$o303, $o306, $o307, $o308] = $this->insertProdCase();
    $mine = [$o303, $o306, $o307, $o308];

    $ids = $this->paginateIds($this->collector, 'collector_orders_paginate', Status::ORDER_ASSEMBLED);

    $this->assertSame([$o306, $o303, $o307, $o308], $this->onlyMine($ids, $mine));
  }

  /**
   * Заказы с одинаковым слотом сохраняют порядок поступления — без второго
   * ключа сортировки MySQL вправе вернуть их вперемешку между страницами.
   *
   * @test
   */
  public function orders_with_equal_slots_keep_arrival_order()
  {
    $slot = now()->startOfDay()->setTime(21, 0);
    $first = $this->insertOrder($slot);
    $second = $this->insertOrder($slot);
    $third = $this->insertOrder($slot);
    $mine = [$first, $second, $third];

    $ids = $this->paginateIds($this->courier, 'orders_paginate', Status::ORDER_ASSEMBLED);

    $this->assertSame([$first, $second, $third], $this->onlyMine($ids, $mine));
  }

  /**
   * Заказ без слота (колонка nullable) уходит в конец списка, а не наверх,
   * как при сортировке MySQL по возрастанию с NULL.
   *
   * @test
   */
  public function orders_without_slot_go_last()
  {
    $withoutSlot = $this->insertOrder(null);
    $withSlot = $this->insertOrder(now()->startOfDay()->setTime(22, 0));
    $mine = [$withoutSlot, $withSlot];

    $ids = $this->paginateIds($this->courier, 'orders_paginate', Status::ORDER_ASSEMBLED);

    $this->assertSame([$withSlot, $withoutSlot], $this->onlyMine($ids, $mine));
  }
}
