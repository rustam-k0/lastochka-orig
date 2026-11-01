<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserAssemblyZone;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Push о новом заказе уходит только сборщикам соответствующей зоны
 * (плюс сборщикам без назначенной зоны) — LASTOCHKI-JAMI-11208.
 */
class PushNotificationZoneTest extends TestCase
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
      'title' => 'Push zone shop',
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

    $value = json_encode(['assembly_zones' => ['enabled' => true]], JSON_UNESCAPED_UNICODE);
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

  private function makeCollector(?int $zoneId): int
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
    User::findOrFail($userId)->assignRole($role);

    if ($zoneId !== null) {
      UserAssemblyZone::create([
        'user_id'          => $userId,
        'shop_id'          => $this->shopId,
        'assembly_zone_id' => $zoneId,
        'date'             => null,
      ]);
    }

    return $userId;
  }

  private function insertOrder(int $zoneId): int
  {
    return \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'push-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $this->clientId,
      'status_id' => 1, // ORDER_CREATED — формируется текст уведомления
      'assembly_zone_id' => $zoneId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /** @test */
  public function push_targets_only_zone_collectors_and_unbound(): void
  {
    $zoneA = $this->insertZone('Салаты');
    $zoneB = $this->insertZone('Горячее');

    $collectorA = $this->makeCollector($zoneA);
    $collectorB = $this->makeCollector($zoneB);
    $collectorNone = $this->makeCollector(null);

    $orderId = $this->insertOrder($zoneA);

    $captured = [];
    $mock = \Mockery::mock(FcmNotificationService::class);
    $mock->shouldReceive('sendToUsers')
      ->once()
      ->andReturnUsing(function (array $userIds) use (&$captured) {
        $captured = $userIds;
      });
    $this->app->instance(FcmNotificationService::class, $mock);

    $this->postJson('/backend-delivery/notification', [
      'token'    => config('services.system.token'),
      'order_id' => $orderId,
    ])->assertOk();

    sort($captured);
    $expected = [$collectorA, $collectorNone];
    sort($expected);

    $this->assertSame($expected, $captured);
    $this->assertNotContains($collectorB, $captured);
  }
}
