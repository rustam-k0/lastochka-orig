<?php

namespace Tests\Feature\NetworkCatalog;

use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Пер-магазинные события цен и остатков в сетевом режиме (network_catalog).
 *
 * Контракт: карточка одна на сеть, цену и остаток несут отдельные события
 * price_updated/stock_updated со своим shop_code. Событие без shop_code
 * применить некуда — оно отбрасывается с логом (ретрай не поможет, в DLQ не идёт).
 *
 * Плюс хелперы публикации (toEvent) обязаны отдавать цену/остаток именно того
 * магазина, для которого публикуется событие, а не первую строку товара.
 */
class NetworkPriceStockEventsTest extends TestCase
{
  use DatabaseTransactions;

  private Shop $anchor;
  private Shop $shopA;
  private Shop $shopB;
  private Product $product;

  protected function setUp(): void
  {
    parent::setUp();
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    Cache::forget(NetworkCatalogMode::ANCHOR_CACHE_KEY);

    // 'default' не в $fillable у Shop — mass-assignment его молча отбросит,
    // поэтому якорь помечаем отдельным save() (см. NetworkCardConsumeTest).
    $existingAnchor = Shop::query()->where('default', 1)->first();
    if ($existingAnchor !== null) {
      $this->anchor = $existingAnchor;
    } else {
      $this->anchor = Shop::create(['title' => 'Anchor', 'code' => 'anchor-' . uniqid()]);
      $this->anchor->default = 1;
      $this->anchor->save();
    }

    // Код магазина уникален на каждый тест: resolveShopId кэширует code→id
    // статически, повторный code словил бы stale id после отката транзакции.
    $this->shopA = Shop::create(['title' => 'A', 'code' => 'net-a-' . uniqid()]);
    $this->shopB = Shop::create(['title' => 'B', 'code' => 'net-b-' . uniqid()]);

    $this->enableNetworkCatalog();

    // status_id и unit_type_id в products NOT NULL без дефолта — задаём явно.
    $this->product = Product::withoutEvents(fn () => Product::create([
      'title' => 'Пиво Разгуляйка светлое',
      'articul' => 'NET-' . mb_strtoupper(uniqid()),
      'shop_id' => $this->anchor->id,
      'status_id' => Status::CODE_ACTIVE,
      'unit_type_id' => Type::UNIT_PIECE,
    ]));
  }

  protected function tearDown(): void
  {
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    Cache::forget(NetworkCatalogMode::ANCHOR_CACHE_KEY);
    parent::tearDown();
  }

  private function enableNetworkCatalog(): void
  {
    $raw = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
    $modules = $raw ? (json_decode($raw, true) ?: []) : [];
    $modules['network_catalog'] = ['label' => 'Сетевой каталог', 'enabled' => true];
    DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );
    app(NetworkCatalogMode::class)->flushCache();
  }

  private function disableNetworkCatalog(): void
  {
    $raw = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
    $modules = json_decode((string) $raw, true) ?: [];
    $modules['network_catalog']['enabled'] = false;
    DB::table('admin_settings')->where('key', 'project_modules')
      ->update(['value' => json_encode($modules, JSON_UNESCAPED_UNICODE)]);
    app(NetworkCatalogMode::class)->flushCache();
  }

  /**
   * Магазин-сентинел легаси-пути: ResolvesShopCode::resolveShopId разворачивает
   * дефолтный shop_code в хардкод id=1 без проверки существования магазина.
   */
  private function ensureFallbackShop(): Shop
  {
    $shop = Shop::query()->find(1);
    if ($shop !== null) {
      return $shop;
    }

    $shop = new Shop();
    $shop->forceFill(['id' => 1, 'title' => 'Fallback', 'code' => 'fallback-' . uniqid()])->save();

    return $shop;
  }

  private function priceEvent(Shop $shop, float $price): array
  {
    return [
      'event_id' => (string) Str::uuid(),
      'event_type' => 'price_updated',
      'source' => '1c',
      'id' => 'guid-x',
      'sku' => 'NET-1',
      'shop_code' => $shop->code,
      'price' => $price,
    ];
  }

  public function test_price_events_from_two_shops_create_two_price_rows(): void
  {
    $t = app(ProductEventTransformer::class);
    $t->processPriceUpdatedEvent($this->product, $this->priceEvent($this->shopA, 100.0));
    $t->processPriceUpdatedEvent($this->product, $this->priceEvent($this->shopB, 120.0));

    $this->assertSame(2, $this->product->prices()->count());
    $this->assertEquals(100.0, (float) $this->product->prices()->where('shop_id', $this->shopA->id)->value('value'));
    $this->assertEquals(120.0, (float) $this->product->prices()->where('shop_id', $this->shopB->id)->value('value'));
  }

  public function test_price_event_without_shop_code_is_dropped_in_network_mode(): void
  {
    $t = app(ProductEventTransformer::class);
    $event = $this->priceEvent($this->shopA, 100.0);
    unset($event['shop_code']);
    $t->processPriceUpdatedEvent($this->product, $event);

    $this->assertSame(0, $this->product->prices()->count(), 'цена без магазина не должна записаться');
  }

  public function test_price_event_with_empty_shop_code_is_dropped_in_network_mode(): void
  {
    $t = app(ProductEventTransformer::class);
    // Пустая строка вместо отсутствующего ключа — реальный кейс от 1С/моста:
    // `??` её не отсекает (см. processRelatedData).
    $event = $this->priceEvent($this->shopA, 100.0);
    $event['shop_code'] = '';
    $t->processPriceUpdatedEvent($this->product, $event);

    $this->assertSame(0, $this->product->prices()->count(), 'пустой shop_code = магазина нет');
    $this->assertSame(0, Shop::query()->where('code', '')->count(), 'магазин-пустышка не должен создаваться');
  }

  public function test_stock_events_from_two_shops_create_two_remain_rows(): void
  {
    $t = app(ProductEventTransformer::class);
    $t->processStockUpdatedEvent($this->product, [
      'event_id' => (string) Str::uuid(), 'event_type' => 'stock_updated',
      'source' => '1c', 'id' => 'guid-x', 'sku' => 'NET-1',
      'shop_code' => $this->shopA->code, 'quantity' => 5,
    ]);
    $t->processStockUpdatedEvent($this->product, [
      'event_id' => (string) Str::uuid(), 'event_type' => 'stock_updated',
      'source' => '1c', 'id' => 'guid-x', 'sku' => 'NET-1',
      'shop_code' => $this->shopB->code, 'quantity' => 7,
    ]);

    $this->assertSame(2, $this->product->remains()->count());
    $this->assertSame(5, (int) Remain::where('product_id', $this->product->id)->where('shop_id', $this->shopA->id)->value('quantity'));
    $this->assertSame(7, (int) Remain::where('product_id', $this->product->id)->where('shop_id', $this->shopB->id)->value('quantity'));
  }

  public function test_stock_event_without_shop_code_is_dropped_in_network_mode(): void
  {
    $t = app(ProductEventTransformer::class);
    $t->processStockUpdatedEvent($this->product, [
      'event_id' => (string) Str::uuid(), 'event_type' => 'stock_updated',
      'source' => '1c', 'id' => 'guid-x', 'sku' => 'NET-1', 'quantity' => 5,
    ]);

    $this->assertSame(0, $this->product->remains()->count());
  }

  public function test_flag_off_keeps_legacy_fallback_for_events_without_shop_code(): void
  {
    $this->disableNetworkCatalog();
    $fallback = $this->ensureFallbackShop();

    $t = app(ProductEventTransformer::class);
    $priceEvent = $this->priceEvent($this->shopA, 100.0);
    unset($priceEvent['shop_code']);
    $t->processPriceUpdatedEvent($this->product, $priceEvent);
    $t->processStockUpdatedEvent($this->product, [
      'event_id' => (string) Str::uuid(), 'event_type' => 'stock_updated',
      'source' => '1c', 'id' => 'guid-x', 'sku' => 'NET-1', 'quantity' => 5,
    ]);

    $this->assertSame(1, $this->product->prices()->where('shop_id', $fallback->id)->count(), 'легаси: цена уходит в fallback-магазин');
    $this->assertSame(1, $this->product->remains()->where('shop_id', $fallback->id)->count(), 'легаси: остаток уходит в fallback-магазин');
  }

  public function test_publish_helpers_filter_by_shop(): void
  {
    // Две цены и два остатка у одного товара — хелперы публикации обязаны
    // фильтровать по переданному магазину, а не брать first().
    Price::withoutEvents(function () {
      foreach ([[$this->shopA, 100], [$this->shopB, 120]] as [$shop, $value]) {
        (new Price())->forceFill([
          'product_id' => $this->product->id, 'shop_id' => $shop->id,
          'type_id' => Type::PRICE_DEFAULT, 'value' => $value, 'step' => 1,
        ])->save();
      }
    });
    Remain::withoutEvents(function () {
      foreach ([[$this->shopA, 3], [$this->shopB, 9]] as [$shop, $qty]) {
        (new Remain())->forceFill([
          'product_id' => $this->product->id, 'shop_id' => $shop->id, 'quantity' => $qty,
        ])->save();
      }
    });

    $t = app(ProductEventTransformer::class);
    // toEvent с relatedModel = shopB должен отдать цену/остаток магазина B
    $event = $t->toEvent($this->product->fresh(), $this->shopB);

    $this->assertEquals(120.0, (float) $event['price']);
    $this->assertSame(9, (int) $event['quantity']);
  }
}
