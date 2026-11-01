<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OrderActionPermissionTest extends TestCase
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
      'email' => uniqid('oap_') . '@test.local',
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

  private function customGlobal(): array
  {
    return ['scope_type' => Role::SCOPE_GLOBAL, 'is_system' => false];
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

  private function makeOrder(?int $clientId = null): Order
  {
    return Order::factory()->create([
      'shop_id' => $this->makeShop('OAP test shop ' . uniqid()),
      'client_id' => $clientId ?? $this->makeClient(),
      'payment_type_id' => 1,
    ]);
  }

  public function test_staff_without_permission_cannot_change_shop(): void
  {
    $this->setFlag('1');
    [, $token] = $this->makeStaff('role_no_change_shop', $this->customGlobal());
    $order = $this->makeOrder();
    $shop = $this->makeShop('OAP target shop');

    $resp = $this->gql($token, 'mutation { OrderShopUpdate(order_id: ' . $order->id . ', shop_id: ' . $shop . ') { code } }');

    $this->assertStringContainsString(
      'Нет доступа к действию: change_shop',
      json_encode($resp->json('errors'), JSON_UNESCAPED_UNICODE)
    );
  }

  public function test_staff_without_permission_cannot_cancel(): void
  {
    $this->setFlag('1');
    [, $token] = $this->makeStaff('role_no_cancel', $this->customGlobal());
    $order = $this->makeOrder();

    $resp = $this->gql($token, 'mutation { OrderCancel(order_id: ' . $order->id . ') { code } }');

    // Блокирует либо директива, либо правило OrderCanBeCanceled — оба запрещают
    $this->assertNotNull($resp->json('errors'));
    $this->assertNull($resp->json('data.OrderCancel'));
  }

  public function test_staff_with_permission_can_cancel(): void
  {
    Http::fake();
    $this->setFlag('1');
    [, $token, $role] = $this->makeStaff('role_can_cancel', $this->customGlobal());
    $role->givePermissionTo(Permission::findOrCreate('orders.cancel', 'api'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $order = $this->makeOrder();

    $resp = $this->gql($token, 'mutation { OrderCancel(order_id: ' . $order->id . ', custom_reason: "Проверка прав") { code } }');

    $resp->assertJsonPath('data.OrderCancel.code', 200);
  }

  public function test_admin_bypasses_permission_check(): void
  {
    Http::fake();
    $this->setFlag('1');
    [, $token] = $this->makeStaff(Role::ADMIN);
    $order = $this->makeOrder();

    $resp = $this->gql($token, 'mutation { OrderCancel(order_id: ' . $order->id . ', custom_reason: "Проверка прав") { code } }');

    $resp->assertJsonPath('data.OrderCancel.code', 200);
  }

  public function test_client_can_still_cancel_own_order(): void
  {
    Http::fake();
    $this->setFlag('1');

    $clientId = $this->makeClient();
    $client = Client::findOrFail($clientId);
    $token = $client->createToken('test')->plainTextToken;

    $order = $this->makeOrder($clientId);

    $resp = $this->gql($token, 'mutation { OrderCancel(order_id: ' . $order->id . ') { code } }');

    $resp->assertJsonPath('data.OrderCancel.code', 200);
  }

  public function test_flag_off_keeps_old_behavior_for_shop_update(): void
  {
    Http::fake();
    $this->setFlag('0');
    // api-роль: при выключенном флаге staff-мутации доступны как раньше (без прав)
    [, $token] = $this->makeStaff(Role::API);
    $order = $this->makeOrder();
    $shop = $this->makeShop('OAP flag off shop');

    $resp = $this->gql($token, 'mutation { OrderShopUpdate(order_id: ' . $order->id . ', shop_id: ' . $shop . ') { code } }');

    $resp->assertJsonMissingPath('errors');
  }

  public function test_export_forbidden_without_permission_when_flag_on(): void
  {
    $this->setFlag('1');
    [, $token] = $this->makeStaff('role_no_export', $this->customGlobal());

    $this->postJson('/purchase/admin/orders/export', [], ['Authorization' => 'Bearer ' . $token])
      ->assertStatus(403);
  }

  public function test_export_allowed_with_permission_when_flag_on(): void
  {
    $this->setFlag('1');
    [, $token, $role] = $this->makeStaff('role_can_export', $this->customGlobal());
    $role->givePermissionTo(Permission::findOrCreate('orders.export', 'api'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->postJson('/purchase/admin/orders/export', [], ['Authorization' => 'Bearer ' . $token])
      ->assertStatus(200);
  }

  public function test_export_flag_off_old_role_check(): void
  {
    $this->setFlag('0');
    [, $token] = $this->makeStaff(Role::ADMIN);

    $this->postJson('/purchase/admin/orders/export', [], ['Authorization' => 'Bearer ' . $token])
      ->assertStatus(200);
  }
}
