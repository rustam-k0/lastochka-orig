<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\ShopService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Открепление магазина от сотрудника в админке — это soft delete строки
 * user_shops (admin_back UserShop использует SoftDeletes). Relation
 * User::shops() и построенный на ней ShopService обязаны игнорировать
 * откреплённые магазины, иначе курьер видит заказы чужих магазинов.
 */
class UserShopsSoftDeleteTest extends TestCase
{
  use DatabaseTransactions;

  private int $aliveShopId;
  private int $detachedShopId;
  private User $user;

  protected function setUp(): void
  {
    parent::setUp();

    $this->aliveShopId = $this->insertShop('Alive shop');
    $this->detachedShopId = $this->insertShop('Detached shop');

    $userId = \DB::table('users')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    $this->user = User::findOrFail($userId);

    \DB::table('user_shops')->insert([
      [
        'user_id' => $userId,
        'shop_id' => $this->aliveShopId,
        'deleted_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
      ],
      [
        'user_id' => $userId,
        'shop_id' => $this->detachedShopId,
        'deleted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
      ],
    ]);
  }

  public function test_shops_relation_excludes_soft_deleted_pivot_rows(): void
  {
    $shopIds = $this->user->shops()->pluck('shops.id')->all();

    $this->assertContains($this->aliveShopId, $shopIds);
    $this->assertNotContains($this->detachedShopId, $shopIds);
  }

  public function test_current_shop_scope_hides_orders_of_detached_shops(): void
  {
    $clientId = \DB::table('clients')->insertGetId([
      'is_authorized' => 1,
      'newsletter_subscribed' => 0,
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $aliveOrderId = $this->insertOrder($this->aliveShopId, $clientId);
    $detachedOrderId = $this->insertOrder($this->detachedShopId, $clientId);

    $this->app->instance(ShopService::class, new ShopService($this->user));

    $visibleIds = Order::query()
      ->whereIn('id', [$aliveOrderId, $detachedOrderId])
      ->currentShop()
      ->pluck('id')
      ->all();

    $this->assertContains($aliveOrderId, $visibleIds);
    $this->assertNotContains($detachedOrderId, $visibleIds);
  }

  private function insertShop(string $title): int
  {
    return \DB::table('shops')->insertGetId([
      'title' => $title . ' ' . uniqid(),
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

  private function insertOrder(int $shopId, int $clientId): int
  {
    return \DB::table('orders')->insertGetId([
      'shop_id' => $shopId,
      'uuid' => 'ushop-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $clientId,
      'status_id' => 2,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }
}
