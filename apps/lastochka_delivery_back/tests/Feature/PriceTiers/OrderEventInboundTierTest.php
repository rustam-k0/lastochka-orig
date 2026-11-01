<?php

namespace Tests\Feature\PriceTiers;

use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Входящее событие заказа (MID-308): снапшот лесенки позиции сохраняется как есть
 * через array_key_exists, а запись цены товара из заказа берёт base_price снапшота:
 * иначе заказ «5 шт по 199» опустил бы обычную цену товара с 279 до 199, а её
 * живьём читают замены, добавление товара сборщиком и смена магазина заказа.
 */
class OrderEventInboundTierTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  private int $shopId;
  private string $shopCode;
  private int $productId;
  private string $productTitle;
  private Order $order;
  private int $orderItemId;

  protected function setUp(): void
  {
    parent::setUp();

    $this->shopCode = 'pt-in-' . uniqid();
    $this->shopId = $this->insertShop(['code' => $this->shopCode]);

    // Товар резолвится по названию: event_sync_metadata для него ещё нет
    $this->productTitle = 'Tier inbound product ' . uniqid();
    $this->productId = $this->insertProduct(['title' => $this->productTitle, 'shop_id' => $this->shopId]);
    $this->insertPrice($this->productId, $this->shopId, 300);

    $orderId = $this->insertOrder($this->shopId, $this->insertClient());
    $this->order = Order::findOrFail($orderId);

    // Позиция уже есть в заказе: событие найдёт её по product_id (ветка обновления)
    $this->orderItemId = $this->insertOrderItem($orderId, $this->productId, [
      'quantity' => 1,
      'price' => 279,
      'price_during_order' => 279,
    ]);
  }

  /** @param array<string, mixed> $extra */
  private function eventItem(array $extra = []): array
  {
    return array_merge([
      'id' => 'ext-item-' . uniqid(),
      'source' => 'storefront',
      'product_id' => 'ext-product-' . uniqid(),
      'product_source' => 'storefront',
      'product_name' => $this->productTitle,
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
  private function process(array $items): void
  {
    $transformer = new OrderEventTransformer();
    $method = new \ReflectionMethod($transformer, 'processOrderItems');
    $method->setAccessible(true);
    $method->invoke($transformer, $this->order, $items, $this->shopCode);
  }

  private function itemRow(): object
  {
    return \DB::table('order_items')->where('id', $this->orderItemId)->first();
  }

  private function productPrice(): float
  {
    return (float) \DB::table('prices')
      ->where('product_id', $this->productId)
      ->where('shop_id', $this->shopId)
      ->value('value');
  }

  public function test_snapshot_from_event_is_saved_on_item(): void
  {
    $this->setPriceTiersFlag(true);

    $this->process([$this->eventItem(['price_tiers' => $this->snapshotArray()])]);

    $row = $this->itemRow();
    $this->assertSame(5, (int) $row->quantity);
    $this->assertSame(199.0, (float) $row->price_during_order);

    $saved = json_decode((string) $row->price_tiers, true);
    $this->assertIsArray($saved, 'снапшот из события не сохранён');
    $this->assertEquals(279.0, $saved['base_price']);
    $this->assertEquals(309.0, $saved['base_price_old']);
    $this->assertFalse($saved['base_is_eligible']);
    $this->assertEquals([1.0, 2.0, 5.0], array_column($saved['tiers'], 'min_quantity'));
  }

  public function test_event_without_key_keeps_existing_snapshot(): void
  {
    $this->setPriceTiersFlag(true);
    \DB::table('order_items')->where('id', $this->orderItemId)
      ->update(['price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE)]);

    // Сервисы общей БД ключ не шлют: снапшот витрины затирать нельзя
    $this->process([$this->eventItem()]);

    $this->assertNotNull($this->itemRow()->price_tiers);
  }

  public function test_null_in_event_clears_snapshot(): void
  {
    $this->setPriceTiersFlag(true);
    \DB::table('order_items')->where('id', $this->orderItemId)
      ->update(['price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE)]);

    $this->process([$this->eventItem(['price_tiers' => null])]);

    $this->assertNull($this->itemRow()->price_tiers);
  }

  public function test_broken_snapshot_is_stored_as_null(): void
  {
    $this->setPriceTiersFlag(true);

    $this->process([$this->eventItem(['price_tiers' => ['base_price' => 279]])]);

    $this->assertNull($this->itemRow()->price_tiers, 'битый снапшот = позиция без лесенки, формулы прежние');
    $this->assertSame(199.0, $this->productPrice(), 'без валидного снапшота цена товара пишется по-старому');
  }

  public function test_product_price_gets_base_price_when_item_has_snapshot(): void
  {
    $this->setPriceTiersFlag(true);

    $this->process([$this->eventItem(['price_tiers' => $this->snapshotArray()])]);

    $this->assertSame(279.0, $this->productPrice(), 'в prices.value обычная цена товара, а не цена ступени 199');
  }

  public function test_product_price_gets_unit_price_when_item_has_no_snapshot(): void
  {
    $this->setPriceTiersFlag(true);

    $this->process([$this->eventItem()]);

    $this->assertSame(199.0, $this->productPrice(), 'поведение позиций без снапшота прежнее');
  }

  public function test_flag_off_still_saves_snapshot_and_writes_base_price(): void
  {
    // Флаги витрины и курьерки живут в разных БД и в окне переключения расходятся.
    // Снапшот в событии = unit_price позиции уже цена ступени: его нужно сохранить,
    // а в prices.value положить base_price, иначе цена ступени станет ценой товара.
    $this->setPriceTiersFlag(false);

    $this->process([$this->eventItem(['price_tiers' => $this->snapshotArray()])]);

    $this->assertNotNull($this->itemRow()->price_tiers, 'сохранение снапшота не зависит от локального флага');
    $this->assertSame(279.0, $this->productPrice(), 'подстановка base_price не зависит от локального флага');
  }
}
