<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OrderAdminAccessTest extends TestCase
{
  use DatabaseTransactions;

  private function setFlag(string $value): void
  {
    AdminSetting::setValue('role_access_matrix_enabled', $value, 'boolean');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
  }

  /**
   * @return array{0: User, 1: string, 2: Role}
   */
  private function makeStaff(string $roleName, array $roleAttrs = []): array
  {
    $role = Role::findOrCreate($roleName, 'api');
    if ($roleAttrs !== []) {
      $role->forceFill($roleAttrs)->save();
    }

    $userId = \DB::table('users')->insertGetId([
      'name' => 'Test',
      'email' => uniqid('oa_') . '@test.local',
      'password' => bcrypt('secret'),
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token, $role];
  }

  private function gql(string $token, string $query)
  {
    return $this->postJson('/graphql-purchase', ['query' => $query], [
      'Authorization' => 'Bearer ' . $token,
    ]);
  }

  // ShopFactory протухла (колонка name вместо title) — сырой insert
  private function makeShop(string $title): int
  {
    return \DB::table('shops')->insertGetId([
      'title' => $title,
      'id_1c' => random_int(900000, 999999),
      'default' => 0,
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

  private function makeClient(): int
  {
    return \DB::table('clients')->insertGetId([
      'is_authorized' => 1,
      'newsletter_subscribed' => 0,
      'password' => bcrypt('secret'),
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function makeOrder(int $shopId): Order
  {
    return Order::factory()->create([
      'shop_id' => $shopId,
      'client_id' => $this->makeClient(),
      'payment_type_id' => 1,
    ]);
  }

  public function test_custom_global_role_authenticates_and_sees_all_orders_when_flag_on(): void
  {
    $this->setFlag('1');
    [, $token] = $this->makeStaff('role_tovaroved_read', [
      'scope_type' => Role::SCOPE_GLOBAL, 'is_system' => false,
    ]);

    $shopA = $this->makeShop('OA test shop A');
    $shopB = $this->makeShop('OA test shop B');
    $this->makeOrder($shopA);
    $this->makeOrder($shopB);

    $resp = $this->gql($token, '{ admin_orders_paginate(first: 10) { paginatorInfo { total } } }');

    $resp->assertJsonMissingPath('errors');
    $this->assertGreaterThanOrEqual(2, $resp->json('data.admin_orders_paginate.paginatorInfo.total'));
  }

  public function test_custom_role_cannot_authenticate_when_flag_off(): void
  {
    $this->setFlag('0');
    [, $token] = $this->makeStaff('role_tovaroved_off', [
      'scope_type' => Role::SCOPE_GLOBAL, 'is_system' => false,
    ]);

    $resp = $this->gql($token, '{ admin_orders_paginate(first: 1) { paginatorInfo { total } } }');

    $this->assertNotNull($resp->json('errors'));
  }

  public function test_courier_still_cannot_authenticate_when_flag_on(): void
  {
    $this->setFlag('1');
    // courier — системная global-роль: расширение scope её НЕ касается
    [, $token] = $this->makeStaff(Role::COURIER, [
      'scope_type' => Role::SCOPE_GLOBAL, 'is_system' => true,
    ]);

    $resp = $this->gql($token, '{ admin_orders_paginate(first: 1) { paginatorInfo { total } } }');

    $this->assertNotNull($resp->json('errors'));
  }

  public function test_shop_scope_user_sees_only_own_shop_orders(): void
  {
    $this->setFlag('1');
    [$user, $token] = $this->makeStaff('role_shop_scope_test', [
      'scope_type' => Role::SCOPE_SHOP, 'is_system' => false,
    ]);

    $own = $this->makeShop('OA test shop own');
    $other = $this->makeShop('OA test shop other');
    \DB::table('user_shops')->insert([
      'user_id' => $user->id,
      'shop_id' => $own,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    $this->makeOrder($own);
    $this->makeOrder($other);

    $resp = $this->gql($token, '{ admin_orders_paginate(first: 10) { paginatorInfo { total } } }');

    $resp->assertJsonMissingPath('errors');
    $this->assertEquals(1, $resp->json('data.admin_orders_paginate.paginatorInfo.total'));
  }
}
