<?php

namespace Tests\Unit;

use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Type;
use App\Services\ProductShopPrice\ProductShopPriceService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductShopPriceServiceTest extends TestCase
{
  use DatabaseTransactions;

  protected Shop $shop;
  protected Product $product;

  protected function setUp(): void
  {
    parent::setUp();

    // Сетевой каталог включается через кэш-ключ NetworkCatalogMode (rememberForever)
    Cache::forever(\App\NetworkCatalog\Support\NetworkCatalogMode::CACHE_KEY, true);

    $this->shop = Shop::create([
      'title' => 'Test Shop ' . uniqid(),
      'code' => 'test-shop-' . uniqid(),
      'id_1c' => 0,
      'default' => false,
      'slot_max_orders' => 10,
      'slot_time' => 30,
      'payment_type_id' => 1,
      'is_popular' => false,
    ]);

    $this->product = Product::create([
      'title' => 'Test Product ' . uniqid(),
      'shop_id' => $this->shop->id,
      'unit_type_id' => 1,
      'status_id' => 1,
      'fill_percent' => 0,
      'publish_after_unlock' => false,
    ]);
  }

  protected function tearDown(): void
  {
    Cache::forget(\App\NetworkCatalog\Support\NetworkCatalogMode::CACHE_KEY);
    parent::tearDown();
  }

  public function test_save_upserts_price_and_remain_and_publishes_events(): void
  {
    $service = app(ProductShopPriceService::class);

    $priceRow = $service->save($this->product, $this->shop, 199.99, 50);

    $this->assertSame(199.99, (float) $priceRow->value);
    $this->assertSame($this->shop->id, (int) $priceRow->shop_id);
    $this->assertSame(Type::PRICE_DEFAULT, (int) $priceRow->type_id);

    $remain = Remain::where('product_id', $this->product->id)
      ->where('shop_id', $this->shop->id)
      ->first();
    $this->assertNotNull($remain);
    $this->assertSame(50, (int) $remain->quantity);

    $priceEvent = EventOutbox::where('entity_type', 'products')
      ->where('entity_id', $this->product->id)
      ->where('event_type', 'price_updated')
      ->latest('id')
      ->first();
    $this->assertNotNull($priceEvent);
    $this->assertSame('product.price_updated', $priceEvent->routing_key);
    $this->assertSame($this->shop->code, $priceEvent->payload['shop_code']);
    $this->assertSame(199.99, (float) $priceEvent->payload['price']);

    $stockEvent = EventOutbox::where('entity_type', 'products')
      ->where('entity_id', $this->product->id)
      ->where('event_type', 'stock_updated')
      ->latest('id')
      ->first();
    $this->assertNotNull($stockEvent);
    $this->assertSame(50, (int) $stockEvent->payload['quantity']);
    $this->assertSame($this->shop->code, $stockEvent->payload['shop_code']);
  }

  public function test_save_updates_existing_row_and_preserves_step(): void
  {
    Price::create([
      'product_id' => $this->product->id,
      'shop_id' => $this->shop->id,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => 100,
      'step' => 3,
    ]);

    $service = app(ProductShopPriceService::class);
    $priceRow = $service->save($this->product, $this->shop, 250.0, 10);

    $this->assertSame(250.0, (float) $priceRow->value);
    $this->assertSame(3, (int) $priceRow->step);
    // Дубль не создан
    $this->assertSame(1, Price::where('product_id', $this->product->id)
      ->where('shop_id', $this->shop->id)
      ->where('type_id', Type::PRICE_DEFAULT)
      ->count());
  }

  public function test_save_rejects_non_positive_price(): void
  {
    $service = app(ProductShopPriceService::class);

    try {
      $service->save($this->product, $this->shop, -10.0, 5);
      $this->fail('Ожидалось InvalidArgumentException для неположительной цены');
    } catch (\InvalidArgumentException $e) {
      $this->assertSame('Цена должна быть больше нуля', $e->getMessage());
    }

    // Строки prices/remains не созданы
    $this->assertSame(0, Price::where('product_id', $this->product->id)
      ->where('shop_id', $this->shop->id)
      ->count());

    $this->assertNull(Remain::where('product_id', $this->product->id)
      ->where('shop_id', $this->shop->id)
      ->first());

    // Событий в outbox нет
    $this->assertSame(0, EventOutbox::where('entity_type', 'products')
      ->where('entity_id', $this->product->id)
      ->count());
  }
}
