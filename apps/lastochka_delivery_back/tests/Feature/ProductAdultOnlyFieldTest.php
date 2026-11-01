<?php

namespace Tests\Feature;

use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Флаг «Товар 18+», отданный сборщику через GraphQL.
 * Колонка products.adult_only пишется admin_back'ом (общая БД) —
 * delivery_back читает её напрямую, без доп. RabbitMQ-ветки.
 */
class ProductAdultOnlyFieldTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private int $shopId;

  protected function setUp(): void
  {
    parent::setUp();

    // default=1 — fallback-фильтр ShopService(null) в scopeCurrentShop
    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'AdultOnly shop',
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
  }

  private function insertProduct(bool $adultOnly): int
  {
    return \DB::table('products')->insertGetId([
      'shop_id' => $this->shopId,
      'title' => 'Вино красное сухое '.uniqid(),
      'unit_type_id' => Type::UNIT_PIECE,
      'status_id' => Status::CODE_ACTIVE,
      'adult_only' => $adultOnly,
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

  public function test_graphql_product_exposes_adult_only_true(): void
  {
    $productId = $this->insertProduct(true);

    $this->actingAsCollectorOnShift();

    $response = $this->graphQL(/** @lang GraphQL */ '
      query ($id: Int!) {
        product(id: $id) {
          id
          adult_only
        }
      }
    ', ['id' => $productId]);

    $response->assertJson([
      'data' => [
        'product' => [
          'id' => $productId,
          'adult_only' => true,
        ],
      ],
    ]);
  }

  public function test_graphql_product_adult_only_false_by_default(): void
  {
    $productId = $this->insertProduct(false);

    $this->actingAsCollectorOnShift();

    $response = $this->graphQL(/** @lang GraphQL */ '
      query ($id: Int!) {
        product(id: $id) {
          id
          adult_only
        }
      }
    ', ['id' => $productId]);

    $response->assertJson([
      'data' => [
        'product' => [
          'id' => $productId,
          'adult_only' => false,
        ],
      ],
    ]);
  }
}
