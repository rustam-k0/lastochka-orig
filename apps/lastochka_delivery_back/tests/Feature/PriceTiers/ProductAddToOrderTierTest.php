<?php

namespace Tests\Feature\PriceTiers;

use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Services\FcmNotificationService;
use App\Services\Pricing\PriceTierSnapshot;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * ProductAddToOrder (MID-308): новая позиция сборщика получает свежий снапшот из
 * живой лесенки магазина заказа; повторное добавление сливается с ней, и ступень
 * выбирается по новому суммарному количеству.
 */
class ProductAddToOrderTierTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests, PriceTiersFixtures;

  private const M_PRODUCT_ADD = '
    mutation ProductAddToOrder($assembly_id: Int!, $product_id: Int!, $quantity: Int!) {
      ProductAddToOrder(assembly_id: $assembly_id, product_id: $product_id, quantity: $quantity) { code message }
    }
  ';

  private int $shopId;
  private int $orderId;
  private int $assemblyId;
  private User $collector;

  protected function setUp(): void
  {
    parent::setUp();

    $this->mock(FcmNotificationService::class)
      ->shouldReceive('sendToUser')->andReturnNull()
      ->shouldReceive('sendToUsers')->andReturnNull();

    $this->shopId = $this->insertShop();

    // User в этом репо guarded: сырой insert + findOrFail
    $this->collector = User::findOrFail($this->insertUser());
    $this->collector->assignRole(Role::findOrCreate('collector', 'api'));
    \DB::table('shifts')->insert([
      'user_id' => $this->collector->id,
      'shop_id' => $this->shopId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // Заказ на сборке с одной клиентской позицией на 1000 ₽
    $this->orderId = $this->insertOrder($this->shopId, $this->insertClient(), ['amount' => 1000.0]);
    $this->assemblyId = $this->insertAssembly($this->orderId, $this->collector->id);

    $clientProductId = $this->insertProduct();
    $this->insertPrice($clientProductId, $this->shopId, 1000);
    $clientItemId = $this->insertOrderItem($this->orderId, $clientProductId, [
      'price' => 1000,
      'price_during_order' => 1000,
    ]);
    $this->insertAssemblyItem($this->assemblyId, $clientItemId);
  }

  /** Товар 279 ₽ с остатком и, по желанию, лесенкой 279/249/199 в магазине заказа. */
  private function tieredProduct(bool $withLadder = true): int
  {
    $productId = $this->insertProduct(['shop_id' => $this->shopId]);
    $this->insertPrice($productId, $this->shopId, 279);

    \DB::table('remains')->insert([
      'product_id' => $productId,
      'shop_id' => $this->shopId,
      'quantity' => 100,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    if ($withLadder) {
      $this->insertLadder($productId, $this->shopId, [[1, 279], [2, 249], [5, 199]]);
    }

    return $productId;
  }

  private function add(int $productId, int $quantity): void
  {
    $this->actingAs($this->collector, 'api');

    $response = $this->graphQL(self::M_PRODUCT_ADD, [
      'assembly_id' => $this->assemblyId,
      'product_id' => $productId,
      'quantity' => $quantity,
    ]);

    $this->assertSame(200, $response->json('data.ProductAddToOrder.code'), json_encode($response->json(), JSON_UNESCAPED_UNICODE));
  }

  private function addedItem(int $productId): object
  {
    $items = \DB::table('order_items')
      ->where('order_id', $this->orderId)
      ->where('product_id', $productId)
      ->whereNull('deleted_at')
      ->get();

    $this->assertCount(1, $items, 'добавка сборщика должна быть одной строкой');

    return $items->first();
  }

  private function orderAmount(): float
  {
    return (float) \DB::table('orders')->where('id', $this->orderId)->value('amount');
  }

  public function test_new_item_gets_fresh_snapshot_and_first_tier(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->tieredProduct();

    $this->add($productId, 1);

    $item = $this->addedItem($productId);
    $this->assertSame(279.0, (float) $item->price_during_order);
    $this->assertSame(279.0, (float) $item->price);
    $this->assertNull($item->price_old);
    $this->assertSame(1, (int) $item->is_eligible);
    $this->assertSame(1, (int) $item->is_supplemented);
    $this->assertSame(Status::ORDER_ITEM_WAITING, (int) $item->status_id);
    $this->assertEquals([
      'base_price' => 279.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [
        ['min_quantity' => 1.0, 'price' => 279.0],
        ['min_quantity' => 2.0, 'price' => 249.0],
        ['min_quantity' => 5.0, 'price' => 199.0],
      ],
    ], PriceTierSnapshot::fromArray($item->price_tiers)->toArray());
    $this->assertEqualsWithDelta(1279.0, $this->orderAmount(), 0.001);
  }

  public function test_new_item_with_five_units_gets_cheapest_tier_and_discount_flags(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->tieredProduct();

    $this->add($productId, 5);

    $item = $this->addedItem($productId);
    $this->assertSame(199.0, (float) $item->price_during_order);
    $this->assertSame(995.0, (float) $item->price);
    $this->assertSame(279.0, (float) $item->price_old);
    $this->assertSame(0, (int) $item->is_eligible, 'дешёвая ступень = позиция со скидкой: промокод на неё не действует');
    $this->assertEqualsWithDelta(1995.0, $this->orderAmount(), 0.001);
  }

  public function test_merge_recalculates_tier_by_total_quantity(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->tieredProduct();

    $this->add($productId, 1);
    $this->add($productId, 1);

    $item = $this->addedItem($productId);
    $this->assertSame(2, (int) $item->quantity);
    $this->assertSame(249.0, (float) $item->price_during_order, 'ступень «от 2» действует на ОБЕ единицы');
    $this->assertSame(498.0, (float) $item->price);
    $this->assertSame(279.0, (float) $item->price_old);
    $this->assertSame(0, (int) $item->is_eligible);
    $this->assertEqualsWithDelta(1498.0, $this->orderAmount(), 0.001, 'amount вырос на разницу итогов строки: 279 + 219');
  }

  public function test_merge_can_lower_order_amount_when_tier_gets_cheaper(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->tieredProduct();

    $this->add($productId, 4);
    $this->assertEqualsWithDelta(1996.0, $this->orderAmount(), 0.001, '4 × 249');

    $this->add($productId, 1);

    $item = $this->addedItem($productId);
    $this->assertSame(5, (int) $item->quantity);
    $this->assertSame(995.0, (float) $item->price, '5 × 199');
    $this->assertEqualsWithDelta(1995.0, $this->orderAmount(), 0.001, 'дельта отрицательная: 995 − 996');
  }

  public function test_product_without_ladder_keeps_old_formula(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->tieredProduct(false);

    $this->add($productId, 1);
    $this->add($productId, 1);

    $item = $this->addedItem($productId);
    $this->assertNull($item->price_tiers);
    $this->assertSame(279.0, (float) $item->price_during_order);
    $this->assertSame(558.0, (float) $item->price);
    $this->assertEqualsWithDelta(1558.0, $this->orderAmount(), 0.001);
  }

  public function test_merge_uses_existing_snapshot_even_when_flag_is_switched_off(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->tieredProduct();
    $this->add($productId, 1);

    // Модуль выключили посреди сборки: новая добавка снапшот бы не получила, но
    // у существующей он уже есть, и слияние обязано считать по нему
    $this->setPriceTiersFlag(false);
    $this->add($productId, 1);

    $item = $this->addedItem($productId);
    $this->assertSame(2, (int) $item->quantity);
    $this->assertSame(249.0, (float) $item->price_during_order);
    $this->assertSame(498.0, (float) $item->price);
    $this->assertEqualsWithDelta(1498.0, $this->orderAmount(), 0.001);
  }

  public function test_flag_off_keeps_old_formula_and_writes_no_snapshot(): void
  {
    $this->setPriceTiersFlag(false);
    $productId = $this->tieredProduct();

    $this->add($productId, 1);
    $this->add($productId, 4);

    $item = $this->addedItem($productId);
    $this->assertNull($item->price_tiers, 'при выключенном модуле снапшот не создаётся');
    $this->assertSame(279.0, (float) $item->price_during_order);
    $this->assertSame(1395.0, (float) $item->price, '5 × 279');
    $this->assertNull($item->price_old);
    $this->assertEqualsWithDelta(2395.0, $this->orderAmount(), 0.001);
  }

  /**
   * Число обращений к ТАБЛИЦЕ price_tiers среди пойманных SQL-запросов.
   *
   * У order_items есть одноимённая КОЛОНКА price_tiers: наивный поиск подстроки
   * «price_tiers» в тексте запроса даст ложное срабатывание на INSERT/UPDATE
   * order_items. Матчим обращение именно к таблице.
   */
  private function priceTiersTableQueriesCount(array $queries): int
  {
    return collect($queries)
      ->filter(fn (array $query) => (bool) preg_match('/(from|into|update)\s+`price_tiers`/i', $query['query']))
      ->count();
  }

  public function test_flag_off_new_item_never_queries_price_tiers_table(): void
  {
    $this->setPriceTiersFlag(false);
    $productId = $this->tieredProduct(); // лесенка в price_tiers ЕСТЬ, но модуль выключен

    \DB::enableQueryLog();
    $this->add($productId, 1);
    $queries = \DB::getQueryLog();
    \DB::disableQueryLog();

    $item = $this->addedItem($productId);
    $this->assertNull($item->price_tiers, 'при выключенном модуле снапшот не создаётся');
    $this->assertSame(279.0, (float) $item->price_during_order);
    $this->assertSame(279.0, (float) $item->price);
    $this->assertSame(
      0,
      $this->priceTiersTableQueriesCount($queries),
      'при выключенном модуле добавка сборщика не должна ходить в таблицу price_tiers'
    );
  }

  public function test_merge_never_queries_price_tiers_table(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->tieredProduct();
    $this->add($productId, 1);

    \DB::enableQueryLog();
    $this->add($productId, 1);
    $queries = \DB::getQueryLog();
    \DB::disableQueryLog();

    $item = $this->addedItem($productId);
    $this->assertSame(2, (int) $item->quantity);
    $this->assertSame(
      0,
      $this->priceTiersTableQueriesCount($queries),
      'слияние считает по снапшоту существующей позиции, свежий снапшот магазина ему не нужен'
    );
  }
}
