<?php

namespace Tests\Feature;

use App\Models\Status;
use App\Models\Type;
use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Время готовности товара, отданное сборщику через GraphQL (модуль product_ready_time).
 *
 * Колонка products.ready_fixed_time заполняется в админке (admin_back) и лежит
 * в общей БД курьерки — delivery_back читает её напрямую, как plu и
 * effective_stock_mode. Отличие от витрины: там availableFrom обнуляется после
 * наступления времени, здесь ready_fixed_time отдаётся весь день — сборщику
 * это постоянный признак товара, а не временный тег.
 */
class ProductReadyTimeFieldTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private int $shopId;

  protected function setUp(): void
  {
    parent::setUp();

    // default=1 — fallback-фильтр ShopService(null) в scopeCurrentShop
    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Ready time shop',
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

  protected function tearDown(): void
  {
    Carbon::setTestNow();
    ModuleConfigService::invalidateCache();

    parent::tearDown();
  }

  private function setReadyTimeModule(bool $enabled): void
  {
    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      [
        'value' => json_encode([
          'product_ready_time' => ['label' => 'Время готовности товаров', 'enabled' => $enabled],
        ]),
        'type' => 'json',
        'updated_at' => now(),
        'created_at' => now(),
      ]
    );

    ModuleConfigService::invalidateCache();
  }

  private function insertProduct(?string $readyFixedTime): int
  {
    return \DB::table('products')->insertGetId([
      'shop_id' => $this->shopId,
      'title' => 'Салат оливье '.uniqid(),
      'unit_type_id' => Type::UNIT_PIECE,
      'status_id' => Status::CODE_ACTIVE,
      'ready_fixed_time' => $readyFixedTime,
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

  private function queryProduct(int $productId): \Illuminate\Testing\TestResponse
  {
    return $this->graphQL(/** @lang GraphQL */ '
      query ($id: Int!) {
        product(id: $id) {
          id
          ready_fixed_time
          ready_at
          is_ready
        }
      }
    ', ['id' => $productId]);
  }

  public function test_product_not_ready_yet_exposes_time_and_is_ready_false(): void
  {
    $this->setReadyTimeModule(true);
    Carbon::setTestNow(Carbon::parse('2026-07-30 09:00:00', config('app.timezone')));

    $productId = $this->insertProduct('14:30:00');
    $this->actingAsCollectorOnShift();

    $this->queryProduct($productId)->assertJson([
      'data' => [
        'product' => [
          'id' => $productId,
          'ready_fixed_time' => '14:30',
          'ready_at' => '2026-07-30 14:30:00',
          'is_ready' => false,
        ],
      ],
    ]);
  }

  /**
   * После наступления времени готовности плашка остаётся (готовность ежедневная),
   * меняется только is_ready — предупреждение сборщику больше не показывается.
   */
  public function test_time_stays_after_ready_moment_and_is_ready_true(): void
  {
    $this->setReadyTimeModule(true);
    Carbon::setTestNow(Carbon::parse('2026-07-30 15:00:00', config('app.timezone')));

    $productId = $this->insertProduct('14:30:00');
    $this->actingAsCollectorOnShift();

    $this->queryProduct($productId)->assertJson([
      'data' => [
        'product' => [
          'id' => $productId,
          'ready_fixed_time' => '14:30',
          'ready_at' => '2026-07-30 14:30:00',
          'is_ready' => true,
        ],
      ],
    ]);
  }

  public function test_product_without_ready_time_is_ready(): void
  {
    $this->setReadyTimeModule(true);

    $productId = $this->insertProduct(null);
    $this->actingAsCollectorOnShift();

    $this->queryProduct($productId)->assertJson([
      'data' => [
        'product' => [
          'id' => $productId,
          'ready_fixed_time' => null,
          'ready_at' => null,
          'is_ready' => true,
        ],
      ],
    ]);
  }

  public function test_fields_are_empty_when_module_disabled(): void
  {
    $this->setReadyTimeModule(false);
    Carbon::setTestNow(Carbon::parse('2026-07-30 09:00:00', config('app.timezone')));

    $productId = $this->insertProduct('14:30:00');
    $this->actingAsCollectorOnShift();

    $this->queryProduct($productId)->assertJson([
      'data' => [
        'product' => [
          'id' => $productId,
          'ready_fixed_time' => null,
          'ready_at' => null,
          'is_ready' => true,
        ],
      ],
    ]);
  }

  /**
   * Поля доступны и на товаре внутри сборки — там, где мобилка сборщика
   * реально их читает: assembly_items → order_item → product.
   */
  public function test_fields_exposed_on_assembly_item_product(): void
  {
    $this->setReadyTimeModule(true);
    Carbon::setTestNow(Carbon::parse('2026-07-30 09:00:00', config('app.timezone')));

    $productId = $this->insertProduct('14:30:00');
    $this->actingAsCollectorOnShift();

    $clientId = \DB::table('clients')->insertGetId([
      'is_authorized' => 1,
      'newsletter_subscribed' => 0,
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $orderId = \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'ready-time-'.uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $clientId,
      'status_id' => Status::ORDER_PREPARING,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $userId = \DB::table('shifts')->where('shop_id', $this->shopId)->value('user_id');

    $assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $userId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => 1,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('assembly_items')->insert([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'quantity' => 1,
      'status_id' => Status::PRODUCT_WAIT,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $response = $this->graphQL(/** @lang GraphQL */ '
      query ($assembly_id: Int!) {
        assembly_items(assembly_id: $assembly_id) {
          order_item {
            product {
              id
              ready_fixed_time
              ready_at
              is_ready
            }
          }
        }
      }
    ', ['assembly_id' => $assemblyId]);

    $response->assertJson([
      'data' => [
        'assembly_items' => [
          [
            'order_item' => [
              'product' => [
                'id' => $productId,
                'ready_fixed_time' => '14:30',
                'ready_at' => '2026-07-30 14:30:00',
                'is_ready' => false,
              ],
            ],
          ],
        ],
      ],
    ]);
  }
}
