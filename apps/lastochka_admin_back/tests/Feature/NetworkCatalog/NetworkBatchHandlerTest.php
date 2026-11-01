<?php

namespace Tests\Feature\NetworkCatalog;

use App\Models\Product;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NetworkBatchHandlerTest extends TestCase
{
  use DatabaseTransactions;

  private Shop $anchor;
  private Shop $shopA;
  private Product $product;

  protected function setUp(): void
  {
    parent::setUp();
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    Cache::forget(NetworkCatalogMode::ANCHOR_CACHE_KEY);

    // 'default' не в $fillable у Shop — mass-assignment его молча отбросит,
    // поэтому якорь помечаем отдельным save() (см. NetworkPriceStockEventsTest).
    $existingAnchor = Shop::query()->where('default', 1)->first();
    if ($existingAnchor !== null) {
      $this->anchor = $existingAnchor;
    } else {
      $this->anchor = Shop::create(['title' => 'Anchor', 'code' => 'anchor-' . uniqid()]);
      $this->anchor->default = 1;
      $this->anchor->save();
    }
    $this->shopA = Shop::create(['title' => 'A', 'code' => 'batch-a-' . uniqid()]);

    $modules = json_decode((string) DB::table('admin_settings')->where('key', 'project_modules')->value('value'), true) ?: [];
    $modules['network_catalog'] = ['label' => 'Сетевой каталог', 'enabled' => true];
    DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );
    app(NetworkCatalogMode::class)->flushCache();

    // status_id и unit_type_id в products NOT NULL без дефолта — задаём явно.
    $this->product = Product::withoutEvents(fn () => Product::create([
      'title' => 'Коньяк Разгуляйка 5*',
      'articul' => 'BATCH-' . mb_strtoupper(uniqid()),
      'shop_id' => $this->anchor->id,
      'status_id' => Status::CODE_ACTIVE,
      'unit_type_id' => Type::UNIT_PIECE,
    ]));

    // Metadata сетевой карточки: shop_code якорного события (или отсутствует).
    // Важно: НЕ shop_code магазина A.
    EventSyncMetadata::create([
      'entity_type' => 'products',
      'source' => '1c',
      'external_id' => 'guid-batch-1',
      'shop_code' => null,
      'entity_id' => $this->product->id,
      'last_event_id' => (string) Str::uuid(),
      'last_event_timestamp' => now()->subMinute(),
    ]);
  }

  protected function tearDown(): void
  {
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    Cache::forget(NetworkCatalogMode::ANCHOR_CACHE_KEY);
    parent::tearDown();
  }

  public function test_price_batch_resolves_product_globally_and_writes_shop_price(): void
  {
    $handler = app(ProductBatchHandler::class);
    $result = $handler->handle([
      'event_id' => (string) Str::uuid(),
      'event_timestamp' => now()->toIso8601String(),
      'event_type' => 'price_batch_updated',
      'event_source' => '1c',
      'source' => '1c',
      'shop_code' => $this->shopA->code,
      'items' => [
        ['id' => 'guid-batch-1', 'sku' => 'BATCH-1', 'price' => 777.0],
      ],
    ]);

    $this->assertSame('ack', $result);
    $this->assertEquals(
      777.0,
      (float) $this->product->prices()->where('shop_id', $this->shopA->id)->value('value'),
      'цена батча должна лечь магазину из конверта при глобальном резолве товара'
    );
  }

  public function test_stock_batch_resolves_product_globally_and_writes_shop_remain(): void
  {
    $handler = app(ProductBatchHandler::class);
    $result = $handler->handle([
      'event_id' => (string) Str::uuid(),
      'event_timestamp' => now()->toIso8601String(),
      'event_type' => 'stock_batch_updated',
      'event_source' => '1c',
      'source' => '1c',
      'shop_code' => $this->shopA->code,
      'items' => [
        ['id' => 'guid-batch-1', 'sku' => 'BATCH-1', 'quantity' => 42],
      ],
    ]);

    $this->assertSame('ack', $result);
    $this->assertSame(
      42,
      (int) $this->product->remains()->where('shop_id', $this->shopA->id)->value('quantity')
    );
  }
}
