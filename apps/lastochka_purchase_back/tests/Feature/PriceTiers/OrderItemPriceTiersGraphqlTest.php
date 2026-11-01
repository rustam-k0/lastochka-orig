<?php

namespace Tests\Feature\PriceTiers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderItemPriceTiersGraphqlTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  private int $orderId;
  private int $tieredItemId;
  private int $plainItemId;
  private string $token;

  protected function setUp(): void
  {
    parent::setUp();

    $order = $this->makeOrder($this->makeShop('PT graphql shop'), $this->makeClient());
    $this->orderId = $order->id;
    $this->tieredItemId = $this->makeOrderItem($order, $this->makeProduct(), [
      'quantity' => 5, 'price' => 199, 'price_during_order' => 199, 'price_old' => 279,
      'price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE, 279.0, 309.0, false),
    ])->id;
    $this->plainItemId = $this->makeOrderItem($order, $this->makeProduct(), [
      'quantity' => 1, 'price' => 100, 'price_during_order' => 100,
    ])->id;

    // Роль admin, а не кастомная: global scope onlyAdmin пропускает кастомные роли
    // только при включённом role_access_matrix_enabled
    $userId = DB::table('users')->insertGetId([
      'name' => 'PT',
      'email' => uniqid('pt_') . '@test.local',
      'password' => bcrypt('secret'),
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
    $user->assignRole(Role::findOrCreate(Role::ADMIN, 'api'));
    $this->token = $user->createToken('test')->plainTextToken;
  }

  /** @return array<int, array<string, mixed>|null> price_tiers по id позиции */
  private function priceTiersByItem(): array
  {
    $query = '{ admin_order_items_paginate(order_id: ' . $this->orderId . ') { data { id price_old '
      . 'price_tiers { base_price base_price_old base_is_eligible tiers { min_quantity price } } } } }';

    $response = $this->postJson('/graphql-purchase', ['query' => $query], [
      'Authorization' => 'Bearer ' . $this->token,
    ]);
    $response->assertJsonMissingPath('errors');

    return collect($response->json('data.admin_order_items_paginate.data'))
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
    DB::table('order_items')->where('id', $this->tieredItemId)
      ->update(['price_tiers' => json_encode(['base_price' => 279])]);

    $this->assertNull($this->priceTiersByItem()[$this->tieredItemId]);
  }

  public function test_stub_snapshot_is_exposed_with_empty_tiers(): void
  {
    $this->setPriceTiersFlag(true);
    DB::table('order_items')->where('id', $this->tieredItemId)->update([
      'price_tiers' => json_encode(\App\Services\Pricing\OrderItemTierPricing::stubSnapshot(380.0)),
    ]);

    $this->assertEquals([
      'base_price' => 380.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [],
    ], $this->priceTiersByItem()[$this->tieredItemId]);
  }
}
