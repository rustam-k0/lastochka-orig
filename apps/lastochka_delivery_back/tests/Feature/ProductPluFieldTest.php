<?php

namespace Tests\Feature;

use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * PLU-код товара, отданный сборщику через GraphQL.
 * Колонка products.plu пишется admin_back'ом (общая БД aladdin_custom) —
 * delivery_back читает её напрямую, без доп. RabbitMQ-ветки.
 * См. spec docs/superpowers/specs/2026-07-01-1c-plu-code-design.md
 */
class ProductPluFieldTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private int $shopId;

  protected function setUp(): void
  {
    parent::setUp();

    // default=1 — fallback-фильтр ShopService(null) в scopeCurrentShop
    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'PLU shop',
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

  private function insertProduct(?string $plu): int
  {
    return \DB::table('products')->insertGetId([
      'shop_id' => $this->shopId,
      'title' => 'Яблоки Голден '.uniqid(),
      'unit_type_id' => Type::UNIT_PIECE,
      'status_id' => Status::CODE_ACTIVE,
      'plu' => $plu,
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

  public function test_graphql_product_exposes_plu(): void
  {
    $productId = $this->insertProduct('4126');

    $this->actingAsCollectorOnShift();

    $response = $this->graphQL(/** @lang GraphQL */ '
      query ($id: Int!) {
        product(id: $id) {
          id
          plu
        }
      }
    ', ['id' => $productId]);

    $response->assertJson([
      'data' => [
        'product' => [
          'id' => $productId,
          'plu' => '4126',
        ],
      ],
    ]);
  }

  public function test_graphql_product_plu_is_null_when_unset(): void
  {
    $productId = $this->insertProduct(null);

    $this->actingAsCollectorOnShift();

    $response = $this->graphQL(/** @lang GraphQL */ '
      query ($id: Int!) {
        product(id: $id) {
          id
          plu
        }
      }
    ', ['id' => $productId]);

    $response->assertJson([
      'data' => [
        'product' => [
          'id' => $productId,
          'plu' => null,
        ],
      ],
    ]);
  }
}
