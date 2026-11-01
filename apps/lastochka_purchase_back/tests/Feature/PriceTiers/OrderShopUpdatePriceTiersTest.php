<?php

namespace Tests\Feature\PriceTiers;

use App\GraphQL\Mutations\Order\OrderShopUpdate;
use App\Models\Order;
use App\Models\Product;
use App\Models\Status;
use App\Models\Type;
use App\Services\Pricing\OrderItemTierPricing;
use App\Services\Pricing\PriceTierSnapshot;
use App\Support\NetworkCatalogMode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Смена магазина заказа в сетевом каталоге (MID-308): лесенка принадлежит магазину,
 * поэтому позиция получает свежий снапшот лесенки НОВОГО магазина, а цена считается
 * по ступени для количества позиции.
 */
class OrderShopUpdatePriceTiersTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  private int $shopAId;
  private int $shopBId;
  private int $clientId;
  private Product $product;

  protected function setUp(): void
  {
    parent::setUp();

    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    $this->shopAId = $this->makeShop('PT shop A');
    $this->shopBId = $this->makeShop('PT shop B');
    $this->clientId = $this->makeClient();

    // Товар продаётся в обоих магазинах: A = 300, B = 380 с лесенкой 380/350/300
    $this->product = $this->makeProduct(['shop_id' => $this->shopAId]);
    $this->makePrice($this->product->id, $this->shopAId, 300);
    $this->makePrice($this->product->id, $this->shopBId, 380);
    $this->makeLadder($this->product->id, $this->shopBId, [[1, 380], [3, 350], [5, 300]]);
  }

  protected function tearDown(): void
  {
    Cache::forget(NetworkCatalogMode::CACHE_KEY);

    parent::tearDown();
  }

  /** Снапшот старого магазина A: 300 от 1, 270 от 3. */
  private function shopASnapshot(): string
  {
    return $this->snapshotJson([['min_quantity' => 1, 'price' => 300], ['min_quantity' => 3, 'price' => 270]], 300.0);
  }

  private function changeShop(Order $order, int $shopId): array
  {
    return app(OrderShopUpdate::class)(null, ['order_id' => $order->id, 'shop_id' => $shopId]);
  }

  public function test_shop_change_builds_fresh_snapshot_and_prices_item_by_quantity(): void
  {
    $this->setNetworkCatalog(true);
    $this->setPriceTiersFlag(true);

    $order = $this->makeOrder($this->shopAId, $this->clientId);
    $item = $this->makeOrderItem($order, $this->product, [
      'quantity' => 3, 'price' => 270, 'price_during_order' => 270, 'price_old' => 300,
      'is_eligible' => 0, 'price_tiers' => $this->shopASnapshot(),
    ]);

    $result = $this->changeShop($order, $this->shopBId);

    $this->assertSame(200, $result['code']);

    $fresh = $item->fresh();
    $this->assertSame(350.0, (float) $fresh->price_during_order, 'ступень «от 3» магазина B');
    $this->assertSame(350.0, (float) $fresh->price, 'price в purchase_back до сборки = цена ЗА ЕДИНИЦУ');
    $this->assertSame(380.0, (float) $fresh->price_old);
    $this->assertSame(0, (int) $fresh->is_eligible);
    $this->assertEquals([
      'base_price' => 380.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [
        ['min_quantity' => 1.0, 'price' => 380.0],
        ['min_quantity' => 3.0, 'price' => 350.0],
        ['min_quantity' => 5.0, 'price' => 300.0],
      ],
    ], PriceTierSnapshot::fromArray($fresh->price_tiers)->toArray());

    $this->assertSame(1050.0, (float) $order->fresh()->items_subtotal, '3 × 350');
  }

  public function test_second_shop_change_without_ladder_restores_eligible_flag(): void
  {
    // F1 (фикс-волна финального ревью, MID-308): ступень старого магазина
    // перестаёт применяться при следующей смене магазина без своей лесенки —
    // позиция обязана вернуть признак «участвует в процентном промокоде»,
    // иначе AssemblyFinish::percentDiscount() (delivery_back) выкидывает её
    // из базы скидки, хотя цена уже обычная.
    $this->setNetworkCatalog(true);
    $this->setPriceTiersFlag(true);

    $shopCId = $this->makeShop('PT shop C');
    $this->makePrice($this->product->id, $shopCId, 220);

    $order = $this->makeOrder($this->shopAId, $this->clientId);
    $item = $this->makeOrderItem($order, $this->product, ['quantity' => 5, 'price' => 300, 'price_during_order' => 300]);

    $this->changeShop($order, $this->shopBId);

    $afterB = $item->fresh();
    $this->assertSame(300.0, (float) $afterB->price_during_order, 'ступень «от 5» магазина B');
    $this->assertSame(380.0, (float) $afterB->price_old);
    $this->assertSame(0, (int) $afterB->is_eligible, 'дешёвая ступень: позиция вне процентного промокода');

    $this->changeShop($order, $shopCId);

    $afterC = $item->fresh();
    $this->assertSame(220.0, (float) $afterC->price_during_order, 'магазин C без лесенки: обычная цена');
    $this->assertSame(220.0, (float) $afterC->price);
    $this->assertNull($afterC->price_old);
    $this->assertSame(1, (int) $afterC->is_eligible, 'F1: обычная цена — признак промокода снова true');
    $this->assertEquals([
      'base_price' => 220.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [],
    ], $afterC->price_tiers, 'лесенка магазина B снята пустышкой');
    $this->assertSame(1100.0, (float) $order->fresh()->items_subtotal, '5 × 220');
  }

  public function test_quantity_on_first_tier_is_regular_price_and_eligible(): void
  {
    $this->setNetworkCatalog(true);
    $this->setPriceTiersFlag(true);

    $order = $this->makeOrder($this->shopAId, $this->clientId);
    $item = $this->makeOrderItem($order, $this->product, [
      'quantity' => 1, 'price' => 300, 'price_during_order' => 300, 'is_eligible' => 0,
    ]);

    $this->changeShop($order, $this->shopBId);

    $fresh = $item->fresh();
    $this->assertSame(380.0, (float) $fresh->price_during_order);
    $this->assertNull($fresh->price_old);
    $this->assertSame(1, (int) $fresh->is_eligible, 'первая ступень: позиция обычная');
    $this->assertNotNull($fresh->price_tiers, 'снапшот появляется и у позиции, которая пришла без него');
  }

  public function test_shop_without_ladder_leaves_stub_snapshot_and_regular_price(): void
  {
    $this->setNetworkCatalog(true);
    $this->setPriceTiersFlag(true);
    DB::table('price_tiers')->where('product_id', $this->product->id)->delete();
    // Лесенка осталась только у СТАРОГО магазина: к новому она отношения не имеет
    $this->makeLadder($this->product->id, $this->shopAId, [[1, 300], [3, 270]]);

    $order = $this->makeOrder($this->shopAId, $this->clientId);
    $item = $this->makeOrderItem($order, $this->product, [
      'quantity' => 3, 'price' => 270, 'price_during_order' => 270, 'price_old' => 300,
      'is_eligible' => 0, 'price_tiers' => $this->shopASnapshot(),
    ]);

    $this->changeShop($order, $this->shopBId);

    $fresh = $item->fresh();
    $this->assertSame(380.0, (float) $fresh->price_during_order, 'прежняя формула: обычная цена нового магазина');
    $this->assertSame(380.0, (float) $fresh->price);
    $this->assertNull($fresh->price_old);
    // F1 (фикс-волна финального ревью, MID-308): было assertSame(0, ...). У
    // позиции БЫЛ снапшот (шла по ступени старого магазина), свежего в новом
    // магазине нет — пустышка снимает ступень, позиция уже на обычной цене,
    // поэтому признак «участвует в процентном промокоде» обязан вернуться
    // в true, а не остаться от снятой дешёвой ступени старого магазина.
    $this->assertSame(1, (int) $fresh->is_eligible, 'ступень снята: позиция снова обычная');
    $this->assertSame(1140.0, (float) $order->fresh()->items_subtotal);

    // Снапшот был, лесенки в новом магазине нет: в колонке пустышка, а не NULL
    $this->assertEquals([
      'base_price' => 380.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [],
    ], $fresh->price_tiers);
    $this->assertNull(PriceTierSnapshot::fromArray($fresh->price_tiers), 'расчёты видят позицию без снапшота');
    $this->assertNull((new OrderItemTierPricing())->snapshotOf($fresh));
  }

  public function test_item_without_snapshot_stays_null_when_new_shop_has_no_ladder(): void
  {
    $this->setNetworkCatalog(true);
    $this->setPriceTiersFlag(true);
    DB::table('price_tiers')->where('product_id', $this->product->id)->delete();

    $order = $this->makeOrder($this->shopAId, $this->clientId);
    $item = $this->makeOrderItem($order, $this->product, ['quantity' => 3, 'price' => 300, 'price_during_order' => 300]);

    $this->changeShop($order, $this->shopBId);

    $fresh = $item->fresh();
    $this->assertSame(380.0, (float) $fresh->price_during_order);
    $this->assertNull($fresh->price_tiers, 'снапшота не было: пустышка не нужна, колонка остаётся NULL');
  }

  public function test_quantum_product_ladder_is_converted_to_portions(): void
  {
    $this->setNetworkCatalog(true);
    $this->setPriceTiersFlag(true);

    // Килограммовый товар с квантом 200 г: порция 100 ₽, «от 1 кг по 450 ₽ за кг» → «от 5 порций по 90 ₽»
    $product = $this->makeProduct([
      'shop_id' => $this->shopAId,
      'info_weight' => 200,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
      'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
    ]);
    $this->makePrice($product->id, $this->shopAId, 95);
    $this->makePrice($product->id, $this->shopBId, 100);
    $this->makeLadder($product->id, $this->shopBId, [[1, 450]]);

    $order = $this->makeOrder($this->shopAId, $this->clientId);
    $item = $this->makeOrderItem($order, $product, ['quantity' => 5, 'price' => 95, 'price_during_order' => 95]);

    $this->changeShop($order, $this->shopBId);

    $fresh = $item->fresh();
    $this->assertSame(90.0, (float) $fresh->price_during_order);
    $this->assertSame(100.0, (float) $fresh->price_old);
    $this->assertEquals([['min_quantity' => 5.0, 'price' => 90.0]], $fresh->price_tiers['tiers']);
  }

  public function test_canceled_and_priceless_items_are_not_touched(): void
  {
    $this->setNetworkCatalog(true);
    $this->setPriceTiersFlag(true);

    $absent = $this->makeProduct(['shop_id' => $this->shopAId]);
    $this->makePrice($absent->id, $this->shopAId, 500);

    $order = $this->makeOrder($this->shopAId, $this->clientId);
    $canceled = $this->makeOrderItem($order, $this->product, [
      'quantity' => 3, 'price' => 270, 'price_during_order' => 270,
      'price_tiers' => $this->shopASnapshot(), 'status_id' => Status::ORDER_ITEM_CANCELED,
    ]);
    $priceless = $this->makeOrderItem($order, $absent, [
      'quantity' => 2, 'price' => 500, 'price_during_order' => 500,
      'price_tiers' => $this->snapshotJson([['min_quantity' => 1, 'price' => 500]], 500.0),
    ]);

    $this->changeShop($order, $this->shopBId);

    $this->assertSame(270.0, (float) $canceled->fresh()->price_during_order);
    $this->assertNotNull($canceled->fresh()->price_tiers);
    $this->assertSame(500.0, (float) $priceless->fresh()->price_during_order, 'мягкий пропуск: цены в новом магазине нет');
    $this->assertNotNull($priceless->fresh()->price_tiers, 'и снапшот остаётся прежним вместе с ценой');
  }

  public function test_flag_off_builds_no_fresh_snapshot_and_replaces_the_old_shop_one_with_stub(): void
  {
    $this->setNetworkCatalog(true);
    $this->setPriceTiersFlag(false);

    $order = $this->makeOrder($this->shopAId, $this->clientId);
    $item = $this->makeOrderItem($order, $this->product, [
      'quantity' => 3, 'price' => 270, 'price_during_order' => 270, 'price_old' => 300,
      'is_eligible' => 0, 'price_tiers' => $this->shopASnapshot(),
    ]);

    DB::enableQueryLog();
    $this->changeShop($order, $this->shopBId);
    $tierTableReads = collect(DB::getQueryLog())
      ->filter(fn (array $query) => str_contains($query['query'], 'from `price_tiers`'))
      ->count();
    DB::disableQueryLog();

    $fresh = $item->fresh();
    $this->assertSame(380.0, (float) $fresh->price_during_order, 'обычная цена нового магазина: лесенка магазина B не применяется');
    $this->assertNull($fresh->price_old);
    $this->assertEquals([], $fresh->price_tiers['tiers'], 'лесенка старого магазина снята пустышкой: иначе сборка посчитала бы позицию по ней');
    $this->assertEquals(380.0, $fresh->price_tiers['base_price']);
    $this->assertNull(PriceTierSnapshot::fromArray($fresh->price_tiers));
    // F1 (фикс-волна финального ревью, MID-308): было assertSame(0, ...) — тот
    // самый баг. У позиции БЫЛ снапшот, свежего нет (флаг выключен), пустышка
    // несёт base_is_eligible: true, позиция уже на обычной цене магазина B —
    // признак промокода обязан вернуться в true, иначе percentDiscount()
    // (delivery_back) ошибочно держал бы её вне базы скидки.
    $this->assertSame(1, (int) $fresh->is_eligible);
    $this->assertSame(1140.0, (float) $order->fresh()->items_subtotal);
    $this->assertSame(0, $tierTableReads, 'при выключенном модуле ни одного запроса к таблице price_tiers');
  }

  public function test_flag_off_item_without_snapshot_is_processed_exactly_as_before(): void
  {
    $this->setNetworkCatalog(true);
    $this->setPriceTiersFlag(false);

    $order = $this->makeOrder($this->shopAId, $this->clientId);
    $item = $this->makeOrderItem($order, $this->product, [
      'quantity' => 3, 'price' => 300, 'price_during_order' => 300, 'price_old' => 350, 'is_eligible' => 0,
    ]);

    $this->changeShop($order, $this->shopBId);

    $fresh = $item->fresh();
    $this->assertSame(380.0, (float) $fresh->price_during_order);
    $this->assertNull($fresh->price_old);
    $this->assertNull($fresh->price_tiers, 'снапшота не было: колонка остаётся NULL, payload события прежний');
    $this->assertSame(0, (int) $fresh->is_eligible);
  }
}
