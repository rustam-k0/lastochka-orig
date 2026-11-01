<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAssemblyZone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Фильтрация заказов сборщика по назначенной зоне сборки
 * (LASTOCHKI-JAMI-11208).
 *
 * Сборщик, привязанный к зоне, видит только заказы своей зоны.
 * Без привязки — все заказы. При выключенном модуле фильтр не действует.
 */
class OrderByAssemblyZoneScopeTest extends TestCase
{
  use DatabaseTransactions;

  private int $shopId;
  private int $clientId;

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

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Zone scope shop',
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
  }

  private function setModuleEnabled(bool $enabled): void
  {
    $value = json_encode(['assembly_zones' => ['enabled' => $enabled]], JSON_UNESCAPED_UNICODE);

    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => $value, 'type' => 'json', 'updated_at' => now(), 'created_at' => now()],
    );
  }

  private function insertZone(string $title): int
  {
    return \DB::table('assembly_zones')->insertGetId([
      'title' => $title . ' ' . uniqid(),
      'position' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function insertOrder(?int $zoneId): int
  {
    return \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'zscope-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $this->clientId,
      'status_id' => 2,
      'assembly_zone_id' => $zoneId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function makeCollectorOnShift(): User
  {
    $userId = \DB::table('users')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('shifts')->insert([
      'user_id' => $userId,
      'shop_id' => $this->shopId,
      'finished_at' => null,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $role = Role::firstOrCreate(['name' => Role::COLLECTOR, 'guard_name' => 'api']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::findOrFail($userId);
    $user->assignRole($role);

    Sanctum::actingAs($user, [], 'api');

    return $user;
  }

  private function bind(User $user, int $zoneId): void
  {
    UserAssemblyZone::create([
      'user_id'          => $user->id,
      'shop_id'          => $this->shopId,
      'assembly_zone_id' => $zoneId,
      'date'             => null,
    ]);
  }

  /** Хелпер: id заказов, видимых через scope (изолируемся от чужих данных по whereIn). */
  private function visibleIds(array $orderIds): array
  {
    return Order::query()
      ->byAssemblyZone()
      ->whereIn('id', $orderIds)
      ->pluck('id')
      ->all();
  }

  /** @test */
  public function collector_with_zone_sees_only_its_zone_orders(): void
  {
    $this->setModuleEnabled(true);

    $zoneA = $this->insertZone('Салаты');
    $zoneB = $this->insertZone('Горячее');
    $orderA = $this->insertOrder($zoneA);
    $orderB = $this->insertOrder($zoneB);
    $orderNoZone = $this->insertOrder(null);

    $user = $this->makeCollectorOnShift();
    $this->bind($user, $zoneA);

    $visible = $this->visibleIds([$orderA, $orderB, $orderNoZone]);

    $this->assertContains($orderA, $visible);
    $this->assertNotContains($orderB, $visible);
    $this->assertNotContains($orderNoZone, $visible);
  }

  /** @test */
  public function collector_without_binding_sees_all_orders(): void
  {
    $this->setModuleEnabled(true);

    $zoneA = $this->insertZone('Салаты');
    $orderA = $this->insertOrder($zoneA);
    $orderNoZone = $this->insertOrder(null);

    $this->makeCollectorOnShift(); // без привязки

    $visible = $this->visibleIds([$orderA, $orderNoZone]);

    $this->assertContains($orderA, $visible);
    $this->assertContains($orderNoZone, $visible);
  }

  /** @test */
  public function filter_is_disabled_when_module_off(): void
  {
    $this->setModuleEnabled(false);

    $zoneA = $this->insertZone('Салаты');
    $zoneB = $this->insertZone('Горячее');
    $orderA = $this->insertOrder($zoneA);
    $orderB = $this->insertOrder($zoneB);

    $user = $this->makeCollectorOnShift();
    $this->bind($user, $zoneA);

    $visible = $this->visibleIds([$orderA, $orderB]);

    // Модуль выключен — фильтр не применяется, видны оба.
    $this->assertContains($orderA, $visible);
    $this->assertContains($orderB, $visible);
  }
}
