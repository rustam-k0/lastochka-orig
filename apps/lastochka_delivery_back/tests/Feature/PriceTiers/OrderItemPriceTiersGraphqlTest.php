<?php

namespace Tests\Feature\PriceTiers;

use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class OrderItemPriceTiersGraphqlTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests, PriceTiersFixtures;

  private const QUERY = '
    query ($id: Int!) {
      order(id: $id) {
        id
        items {
          id
          price_tiers { base_price base_price_old base_is_eligible tiers { min_quantity price } }
        }
      }
    }
  ';

  private int $orderId;
  private int $tieredItemId;
  private int $plainItemId;
  private User $collector;

  protected function setUp(): void
  {
    parent::setUp();

    // default = 1: fallback-фильтр ShopService(null) в scopeCurrentShop
    $shopId = $this->insertShop(['default' => 1]);

    $this->collector = User::findOrFail($this->insertUser());
    $this->collector->assignRole(Role::findOrCreate('collector', 'api'));
    \DB::table('shifts')->insert([
      'user_id' => $this->collector->id, 'shop_id' => $shopId, 'finished_at' => null,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->orderId = $this->insertOrder($shopId, $this->insertClient(), [
      'status_id' => Status::ORDER_CREATED,
      'slot_start_date' => now(),
      'slot_end_date' => now()->addHour(),
    ]);
    $this->tieredItemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'quantity' => 5, 'price' => 199, 'price_during_order' => 199,
      'price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE, 279.0, 309.0, false),
    ]);
    $this->plainItemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'quantity' => 1, 'price' => 100, 'price_during_order' => 100,
    ]);
  }

  /** @return array<int, array<string, mixed>|null> price_tiers по id позиции */
  private function priceTiersByItem(): array
  {
    Sanctum::actingAs($this->collector, [], 'api');

    $response = $this->graphQL(self::QUERY, ['id' => $this->orderId]);
    $response->assertJsonMissingPath('errors');

    return collect($response->json('data.order.items'))
      ->mapWithKeys(fn (array $item) => [(int) $item['id'] => $item['price_tiers']])
      ->all();
  }

  public function test_item_with_snapshot_exposes_price_tiers(): void
  {
    $this->setPriceTiersFlag(true);

    $tiers = $this->priceTiersByItem();

    $this->assertEquals([
      'base_price' => 279.0,
      'base_price_old' => 309.0,
      'base_is_eligible' => false,
      'tiers' => [
        ['min_quantity' => 1.0, 'price' => 279.0],
        ['min_quantity' => 2.0, 'price' => 249.0],
        ['min_quantity' => 5.0, 'price' => 199.0],
      ],
    ], $tiers[$this->tieredItemId]);
    $this->assertNull($tiers[$this->plainItemId]);
  }

  public function test_existing_snapshot_is_exposed_even_when_flag_is_off(): void
  {
    $this->setPriceTiersFlag(false);

    $tiers = $this->priceTiersByItem();

    $this->assertEquals(279.0, $tiers[$this->tieredItemId]['base_price']);
    $this->assertNull($tiers[$this->plainItemId]);
  }

  public function test_broken_snapshot_is_null_not_a_graphql_error(): void
  {
    $this->setPriceTiersFlag(true);
    \DB::table('order_items')->where('id', $this->tieredItemId)
      ->update(['price_tiers' => json_encode(['base_price' => 279])]);

    $this->assertNull($this->priceTiersByItem()[$this->tieredItemId]);
  }

  public function test_stub_snapshot_is_exposed_with_empty_tiers(): void
  {
    $this->setPriceTiersFlag(true);
    \DB::table('order_items')->where('id', $this->tieredItemId)
      ->update(['price_tiers' => $this->snapshotJson([], 380.0)]);

    $this->assertEquals([
      'base_price' => 380.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [],
    ], $this->priceTiersByItem()[$this->tieredItemId]);
  }
}
