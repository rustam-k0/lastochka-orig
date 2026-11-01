<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Окно доставки в выдаче сборщика и курьера при модуле «Оформление заказа
 * без слотов» (checkout_without_slots, MID-285).
 *
 * Клиент оформляет доставку без выбора времени, доставка выполняется за
 * 15–30 минут, поэтому вместо часового слота приложение показывает окно
 * «момент холдирования средств → +30 минут» и обратный отсчёт до его конца.
 * Момент холда присылает витрина в payment_held_at; без него точка отсчёта —
 * появление заказа в курьерке. Самовывоз в модуль не входит: клиент выбирает
 * время выдачи, слот там реальный.
 *
 * В БД колонки слота остаются нетронутыми: на slot_start_date завязаны
 * scopeDaily и сортировка FIFO.
 */
class OrderDeliveryWindowWithoutSlotsModuleTest extends TestCase
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
      'title' => 'Slot gate shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
      'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
      'is_pickup_enabled' => 1, 'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 0, 'is_bonus_enabled' => 0,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->clientId = \DB::table('clients')->insertGetId([
      'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $userId = \DB::table('users')->insertGetId([
      'name' => 'Collector', 'surname' => 'Тестовый ' . uniqid(),
      'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    \DB::table('shifts')->insert([
      'user_id' => $userId, 'shop_id' => $this->shopId, 'finished_at' => null,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->collector = User::findOrFail($userId);
    $this->collector->assignRole(Role::findOrCreate('collector', 'api'));
  }

  protected function tearDown(): void
  {
    // Мемо живёт на процесс, а откат транзакции его не трогает — не оставляем
    // соседним тестам сьюта значение из этого
    $this->forgetModuleMemo();

    parent::tearDown();
  }

  private function forgetModuleMemo(): void
  {
    $memo = new \ReflectionProperty(Order::class, 'checkoutWithoutSlotsMemo');
    $memo->setAccessible(true);
    $memo->setValue(null, null);
  }

  /**
   * @param  array<string, mixed>  $overrides
   */
  private function insertOrder(int $typeId, array $overrides = []): int
  {
    return \DB::table('orders')->insertGetId(array_merge([
      'shop_id' => $this->shopId,
      'uuid' => 'slot-gate-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => $typeId,
      'client_id' => $this->clientId,
      'status_id' => Status::ORDER_CREATED,
      'slot_start_date' => '2026-08-21 19:00:00',
      'slot_end_date' => '2026-08-21 20:00:00',
      'created_at' => '2026-08-21 18:05:00',
      'updated_at' => now(),
    ], $overrides));
  }

  private function setModule(bool $enabled): void
  {
    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode(['checkout_without_slots' => ['label' => 'Оформление доставки без слотов', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
    );

    // Флаг мемоизируется на процесс — без сброса второй тест прогона увидел
    // бы значение из первого (PHPUnit держит один процесс на весь сьют)
    $this->forgetModuleMemo();
  }

  private function slotOf(int $orderId): array
  {
    Sanctum::actingAs($this->collector, [], 'api');

    $response = $this->graphQL(/** @lang GraphQL */ '
      query ($id: Int!) { order(id: $id) { id slot_start_date slot_end_date } }
    ', ['id' => $orderId]);

    return $response->json('data.order') ?? [];
  }

  /** @test */
  public function delivery_window_starts_at_payment_hold_when_module_enabled()
  {
    $this->setModule(true);
    $orderId = $this->insertOrder(Type::DELIVERY_COURIER, [
      'payment_held_at' => '2026-08-21 18:17:00',
    ]);

    $slot = $this->slotOf($orderId);

    $this->assertSame($orderId, (int) $slot['id']);
    $this->assertSame('2026-08-21 18:17:00', $slot['slot_start_date']);
    $this->assertSame('2026-08-21 18:47:00', $slot['slot_end_date']);
  }

  /** @test */
  public function delivery_window_falls_back_to_order_creation_without_hold()
  {
    // Наличные: холда нет вовсе, точка отсчёта — появление заказа в курьерке
    $this->setModule(true);
    $orderId = $this->insertOrder(Type::DELIVERY_COURIER, [
      'payment_held_at' => null,
      'created_at' => '2026-08-21 18:05:00',
    ]);

    $slot = $this->slotOf($orderId);

    $this->assertSame('2026-08-21 18:05:00', $slot['slot_start_date']);
    $this->assertSame('2026-08-21 18:35:00', $slot['slot_end_date']);
  }

  /** @test */
  public function sub_order_inherits_delivery_window_from_parent()
  {
    // Подзаказ сплита создаётся позже родителя, а доставляется тем же окном
    $this->setModule(true);

    $parentId = $this->insertOrder(Type::DELIVERY_COURIER, [
      'payment_held_at' => '2026-08-21 18:17:00',
    ]);

    $childId = $this->insertOrder(Type::DELIVERY_COURIER, [
      'payment_held_at' => null,
      'parent_order_id' => $parentId,
      'sub_number' => 1,
      'created_at' => '2026-08-21 18:40:00',
    ]);

    $slot = $this->slotOf($childId);

    $this->assertSame('2026-08-21 18:17:00', $slot['slot_start_date']);
    $this->assertSame('2026-08-21 18:47:00', $slot['slot_end_date']);
  }

  /** @test */
  public function pickup_order_keeps_slot_when_module_enabled()
  {
    $this->setModule(true);
    $orderId = $this->insertOrder(Type::DELIVERY_PICKUP, [
      'payment_held_at' => '2026-08-21 18:17:00',
    ]);

    $slot = $this->slotOf($orderId);

    $this->assertSame('2026-08-21 19:00:00', $slot['slot_start_date']);
    $this->assertSame('2026-08-21 20:00:00', $slot['slot_end_date']);
  }

  /** @test */
  public function delivery_order_keeps_slot_when_module_disabled()
  {
    $this->setModule(false);
    $orderId = $this->insertOrder(Type::DELIVERY_COURIER, [
      'payment_held_at' => '2026-08-21 18:17:00',
    ]);

    $slot = $this->slotOf($orderId);

    $this->assertSame('2026-08-21 19:00:00', $slot['slot_start_date']);
    $this->assertSame('2026-08-21 20:00:00', $slot['slot_end_date']);
  }

  /** @test */
  public function slot_columns_stay_in_database()
  {
    $this->setModule(true);
    $orderId = $this->insertOrder(Type::DELIVERY_COURIER, [
      'payment_held_at' => '2026-08-21 18:17:00',
    ]);

    $this->slotOf($orderId);

    // scopeDaily и сортировка FIFO читают колонку напрямую — она обязана жить
    $this->assertDatabaseHas('orders', [
      'id' => $orderId,
      'slot_start_date' => '2026-08-21 19:00:00',
      'slot_end_date' => '2026-08-21 20:00:00',
    ]);
  }

  /** @test */
  public function order_list_shows_delivery_window()
  {
    // Карточки «Новые / В работе / Собрано» — тот же тип Order, но через
    // список: проверяем, что окно доезжает и туда, а не только в деталь
    $this->setModule(true);

    $heldAt = now()->startOfHour()->addMinutes(17);
    $orderId = $this->insertOrder(Type::DELIVERY_COURIER, [
      'payment_held_at' => $heldAt->format('Y-m-d H:i:s'),
      // scopeDaily отбирает новые заказы по колонке слота — она остаётся живой
      'slot_start_date' => now()->format('Y-m-d H:i:s'),
      'slot_end_date' => now()->addHour()->format('Y-m-d H:i:s'),
      'created_at' => now()->format('Y-m-d H:i:s'),
    ]);

    Sanctum::actingAs($this->collector, [], 'api');

    $response = $this->graphQL(/** @lang GraphQL */ '
      query ($status: Int) {
        orders_paginate(status: $status, first: 100) {
          data { id slot_start_date slot_end_date }
        }
      }
    ', ['status' => Status::ORDER_CREATED]);

    $row = collect($response->json('data.orders_paginate.data') ?? [])
      ->firstWhere('id', (string) $orderId) ?? [];

    $this->assertNotEmpty($row, 'Заказ не попал в список заказов сборщика');
    $this->assertSame($heldAt->format('Y-m-d H:i:s'), $row['slot_start_date']);
    $this->assertSame($heldAt->copy()->addMinutes(30)->format('Y-m-d H:i:s'), $row['slot_end_date']);
  }
}
