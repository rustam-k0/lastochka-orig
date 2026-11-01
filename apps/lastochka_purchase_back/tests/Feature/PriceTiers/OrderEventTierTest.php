<?php

namespace Tests\Feature\PriceTiers;

use App\GraphQL\Mutations\Order\OrderShopUpdate;
use App\Models\Order;
use App\Services\Pricing\PriceTierSnapshot;
use App\Support\NetworkCatalogMode;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderEventTierTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  private int $shopId;
  private string $shopCode;
  private int $clientId;

  protected function setUp(): void
  {
    parent::setUp();

    Cache::forget(NetworkCatalogMode::CACHE_KEY);

    $this->shopId = $this->makeShop('PT event shop');
    $this->shopCode = (string) DB::table('shops')->where('id', $this->shopId)->value('code');
    $this->clientId = $this->makeClient();
  }

  protected function tearDown(): void
  {
    Cache::forget(NetworkCatalogMode::CACHE_KEY);

    parent::tearDown();
  }

  // --- вход ----------------------------------------------------------------

  /** @param array<string, mixed> $extra */
  private function eventItem(string $productTitle, array $extra = []): array
  {
    return array_merge([
      'id' => 'ext-item-' . uniqid(),
      'source' => 'storefront',
      'product_id' => 'ext-product-' . uniqid(),
      'product_source' => 'storefront',
      'product_name' => $productTitle,
      'quantity' => 5,
      'unit_price' => 199,
      'unit_price_effective' => 199,
    ], $extra);
  }

  private function snapshotArray(): array
  {
    return json_decode($this->snapshotJson(self::$LADDER_FROM_ONE, 279.0, 309.0, false), true);
  }

  /** @param array<int, array<string, mixed>> $items */
  private function process(Order $order, array $items): void
  {
    $transformer = app(OrderEventTransformer::class);
    $method = new \ReflectionMethod(OrderEventTransformer::class, 'processOrderItems');
    $method->setAccessible(true);
    $method->invoke($transformer, $order, $items, $this->shopCode);
  }

  private function productPrice(int $productId): float
  {
    return (float) DB::table('prices')->where('product_id', $productId)->where('shop_id', $this->shopId)->value('value');
  }

  public function test_new_item_is_created_with_snapshot_from_event(): void
  {
    $this->setPriceTiersFlag(true);
    $order = $this->makeOrder($this->shopId, $this->clientId);
    // Цены в магазине у товара ещё нет: блок цены создаст строку prices
    $product = $this->makeProduct(['title' => 'PT inbound ' . uniqid()]);

    $this->process($order, [$this->eventItem($product->title, ['price_tiers' => $this->snapshotArray()])]);

    $row = DB::table('order_items')->where('order_id', $order->id)->where('product_id', $product->id)->first();
    $this->assertNotNull($row, 'позиция создана');
    $this->assertSame(199.0, (float) $row->price_during_order);

    $saved = json_decode((string) $row->price_tiers, true);
    $this->assertIsArray($saved, 'снапшот из события не сохранён');
    $this->assertEquals(279.0, $saved['base_price']);
    $this->assertEquals(309.0, $saved['base_price_old']);
    $this->assertFalse($saved['base_is_eligible']);
    $this->assertCount(3, $saved['tiers']);

    $this->assertSame(279.0, $this->productPrice($product->id), 'в созданной строке prices.value лежит base_price, а не цена ступени 199');
  }

  public function test_existing_item_keeps_snapshot_without_key_and_clears_it_on_null(): void
  {
    $this->setPriceTiersFlag(true);
    $order = $this->makeOrder($this->shopId, $this->clientId);
    $product = $this->makeProduct(['title' => 'PT inbound ' . uniqid()]);
    $item = $this->makeOrderItem($order, $product, [
      'quantity' => 5, 'price' => 199, 'price_during_order' => 199,
      'price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE),
    ]);

    $this->process($order, [$this->eventItem($product->title)]);
    $this->assertNotNull($item->fresh()->price_tiers, 'ключа нет: снапшот не трогается');
    $this->assertSame(199.0, $this->productPrice($product->id), 'ключа нет: цена товара пишется по-старому');

    $this->process($order, [$this->eventItem($product->title, ['price_tiers' => null])]);
    $this->assertNull($item->fresh()->price_tiers, 'null в событии очищает снапшот');
  }

  public function test_flag_off_still_saves_snapshot_and_writes_base_price(): void
  {
    // Флаги сервисов расходятся в окне переключения: снапшот в событии значит, что
    // unit_price уже цена ступени, и в prices.value она попасть не должна
    $this->setPriceTiersFlag(false);
    $order = $this->makeOrder($this->shopId, $this->clientId);
    $product = $this->makeProduct(['title' => 'PT inbound ' . uniqid()]);

    $this->process($order, [$this->eventItem($product->title, ['price_tiers' => $this->snapshotArray()])]);

    $row = DB::table('order_items')->where('order_id', $order->id)->where('product_id', $product->id)->first();
    $this->assertNotNull($row->price_tiers, 'сохранение снапшота не зависит от локального флага');
    $this->assertSame(279.0, $this->productPrice($product->id), 'подстановка base_price не зависит от локального флага');
  }

  public function test_item_without_snapshot_writes_unit_price_as_before(): void
  {
    $this->setPriceTiersFlag(true);
    $order = $this->makeOrder($this->shopId, $this->clientId);
    $product = $this->makeProduct(['title' => 'PT inbound ' . uniqid()]);

    $this->process($order, [$this->eventItem($product->title)]);

    $row = DB::table('order_items')->where('order_id', $order->id)->where('product_id', $product->id)->first();
    $this->assertNull($row->price_tiers);
    $this->assertSame(199.0, $this->productPrice($product->id), 'поведение позиций без снапшота прежнее');
  }

  // --- выход ---------------------------------------------------------------

  /** @return array<int, array<string, mixed>> */
  private function eventItems(Order $order): array
  {
    return app(OrderEventTransformer::class)
      ->toEvent($order->fresh()->load(['orderItems.product', 'orderItems.originProduct']))['items'];
  }

  public function test_shop_change_event_carries_fresh_snapshot_and_stub_but_not_null_columns(): void
  {
    $this->setNetworkCatalog(true);
    $this->setPriceTiersFlag(true);
    Http::fake(['*' => Http::response(['code' => 200, 'message' => 'OK'], 200)]);

    $shopBId = $this->makeShop('PT event shop B');

    $tiered = $this->makeProduct(['shop_id' => $this->shopId]);
    $this->makePrice($tiered->id, $this->shopId, 300);
    $this->makePrice($tiered->id, $shopBId, 380);
    $this->makeLadder($tiered->id, $shopBId, [[1, 380], [3, 350]]);

    // У этого товара лесенка была только в старом магазине
    $lostLadder = $this->makeProduct(['shop_id' => $this->shopId]);
    $this->makePrice($lostLadder->id, $this->shopId, 100);
    $this->makePrice($lostLadder->id, $shopBId, 110);

    $plain = $this->makeProduct(['shop_id' => $this->shopId]);
    $this->makePrice($plain->id, $this->shopId, 50);
    $this->makePrice($plain->id, $shopBId, 55);

    $order = $this->makeOrder($this->shopId, $this->clientId);
    $tieredItem = $this->makeOrderItem($order, $tiered, ['quantity' => 3, 'price' => 300, 'price_during_order' => 300]);
    $lostLadderItem = $this->makeOrderItem($order, $lostLadder, [
      'quantity' => 2, 'price' => 90, 'price_during_order' => 90,
      'price_tiers' => $this->snapshotJson([['min_quantity' => 2, 'price' => 90]], 100.0),
    ]);
    $plainItem = $this->makeOrderItem($order, $plain, ['quantity' => 1, 'price' => 50, 'price_during_order' => 50]);

    app(OrderShopUpdate::class)(null, ['order_id' => $order->id, 'shop_id' => $shopBId]);

    $items = collect($this->eventItems($order))->keyBy('id');

    // Свежий снапшот лесенки нового магазина
    $this->assertSame(350.0, $items[(string) $tieredItem->id]['unit_price']);
    $this->assertSame(1050.0, $items[(string) $tieredItem->id]['line_total']);
    $this->assertEquals(380.0, $items[(string) $tieredItem->id]['price_tiers']['base_price']);
    $this->assertCount(2, $items[(string) $tieredItem->id]['price_tiers']['tiers']);

    // Смена магазина на магазин без лесенки: в колонке пустышка, ключ в событии
    // есть, снапшотом она не читается, цена обычная
    $lostPayload = $items[(string) $lostLadderItem->id];
    $this->assertEquals([
      'base_price' => 110.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [],
    ], $lostLadderItem->fresh()->price_tiers, 'в колонке пустышка');
    $this->assertArrayHasKey('price_tiers', $lostPayload, 'ключ в исходящем событии есть: витрина снимет свой снапшот');
    $this->assertEquals([], $lostPayload['price_tiers']['tiers']);
    $this->assertNull(PriceTierSnapshot::fromArray($lostPayload['price_tiers']), 'на пустой лесенке снапшота нет');
    $this->assertSame(110.0, $lostPayload['unit_price'], 'цена обычная');
    $this->assertSame(220.0, $lostPayload['line_total']);

    // Снапшота не было и нет: колонка NULL, набор ключей прежний
    $this->assertArrayNotHasKey('price_tiers', $items[(string) $plainItem->id]);
  }

  public function test_any_item_with_snapshot_in_column_carries_the_key_as_is(): void
  {
    // Правило зависит только от данных: событие смены статуса, собранное в другом
    // процессе (воркер outbox), даёт тот же payload. Витрина перезапишет свой
    // снапшот тем же значением: это безвредно.
    $this->setPriceTiersFlag(false);
    $order = $this->makeOrder($this->shopId, $this->clientId);
    $item = $this->makeOrderItem($order, $this->makeProduct(), [
      'quantity' => 5, 'price' => 199, 'price_during_order' => 199,
      'price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE),
    ]);

    $payload = collect($this->eventItems($order))->firstWhere('id', (string) $item->id);

    $this->assertEquals(json_decode($this->snapshotJson(self::$LADDER_FROM_ONE), true), $payload['price_tiers']);
    $this->assertSame(199.0, $payload['unit_price']);
    $this->assertSame(995.0, $payload['line_total']);
  }

  public function test_item_with_null_column_keeps_payload_keys(): void
  {
    $this->setPriceTiersFlag(true);
    $order = $this->makeOrder($this->shopId, $this->clientId);
    $item = $this->makeOrderItem($order, $this->makeProduct(), ['quantity' => 5, 'price' => 199, 'price_during_order' => 199]);

    $payload = collect($this->eventItems($order))->firstWhere('id', (string) $item->id);

    $this->assertArrayNotHasKey('price_tiers', $payload, 'на форках без фичи payload не меняется');
    $this->assertSame(
      // portion_size — размер порции в г/мл/шт для выгрузки невзвешенной весовой позиции в 1С
      ['id', 'source', 'product_id', 'product_source', 'product_name', 'quantity', 'unit_price', 'unit_price_effective', 'line_subtotal', 'line_discount_total', 'line_total', 'item_status', 'returned_quantity', 'portion_size'],
      array_keys($payload)
    );
  }
}
