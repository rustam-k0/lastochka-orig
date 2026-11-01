<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Зона сборки в выдаче заказа сборщику (LASTOCHKI-JAMI-11151).
 *
 * Подзаказы после сплита несут orders.assembly_zone_id, но GraphQL отдавал
 * только числовой id. Мобилке сборщика нужно название зоны («1 этаж»),
 * которое настраивается админом в assembly_zones.title.
 */
class OrderAssemblyZoneTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

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

    // default=1 — fallback-фильтр ShopService(null) в scopeCurrentShop
    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Zone shop',
      'id_1c' => 999997,
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

  private function insertZone(string $title, int $position = 1): int
  {
    return \DB::table('assembly_zones')->insertGetId([
      'title' => $title,
      'position' => $position,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function insertOrder(?int $zoneId): int
  {
    return \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'zone-' . uniqid(),
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

  private function actingAsCollectorOnShift(): void
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

    Sanctum::actingAs(\App\Models\User::findOrFail($userId), [], 'api');
  }

  public function test_assembly_zone_relation_returns_zone_title(): void
  {
    $zoneId = $this->insertZone('1 этаж');
    $orderId = $this->insertOrder($zoneId);

    $order = Order::findOrFail($orderId);

    $this->assertNotNull($order->assemblyZone);
    $this->assertSame('1 этаж', $order->assemblyZone->title);
  }

  public function test_assembly_zone_is_null_when_order_has_no_zone(): void
  {
    $order = Order::findOrFail($this->insertOrder(null));

    $this->assertNull($order->assemblyZone);
  }

  public function test_graphql_order_exposes_assembly_zone(): void
  {
    $zoneId = $this->insertZone('2 этаж', 2);
    $orderId = $this->insertOrder($zoneId);

    $this->actingAsCollectorOnShift();

    $response = $this->graphQL(/** @lang GraphQL */ '
      query ($id: Int) {
        order(id: $id) {
          id
          assembly_zone_id
          assemblyZone {
            id
            title
          }
        }
      }
    ', ['id' => $orderId]);

    $response->assertJson([
      'data' => [
        'order' => [
          'id' => $orderId,
          'assembly_zone_id' => $zoneId,
          'assemblyZone' => [
            'id' => $zoneId,
            'title' => '2 этаж',
          ],
        ],
      ],
    ]);
  }

  public function test_graphql_assembly_zone_is_null_for_order_without_zone(): void
  {
    $orderId = $this->insertOrder(null);

    $this->actingAsCollectorOnShift();

    $response = $this->graphQL(/** @lang GraphQL */ '
      query ($id: Int) {
        order(id: $id) {
          id
          assemblyZone {
            id
            title
          }
        }
      }
    ', ['id' => $orderId]);

    $response->assertJson([
      'data' => [
        'order' => [
          'id' => $orderId,
          'assemblyZone' => null,
        ],
      ],
    ]);
  }
}
