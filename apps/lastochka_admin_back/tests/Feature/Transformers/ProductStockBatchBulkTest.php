<?php

namespace Tests\Feature\Transformers;

use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Type;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bulk-путь stock_batch_updated: массовая запись остатков в remains через
 * upsert. Стоковый путь без кванта/событий/fill-percent, поэтому все позиции
 * bulk-пригодны; сохраняется нормализация отрицательных к 0 и guard от trashed.
 */
class ProductStockBatchBulkTest extends TestCase
{
  use DatabaseTransactions;

  private Shop $shop;

  protected function setUp(): void
  {
    parent::setUp();
    Model::unguard();
    config()->set('rabbitmq-events.enabled', false);
    config()->set('rabbitmq-events.bulk_price_batch', true);

    $this->shop = Shop::create([
      'title' => 'Bulk-сток',
      'code' => 'BSK_' . Str::random(6),
    ]);
  }

  protected function tearDown(): void
  {
    Model::reguard();
    parent::tearDown();
  }

  private function product(string $sku): Product
  {
    return Product::create([
      'title' => 'Товар ' . $sku,
      'articul' => $sku,
      'unit_type_id' => Type::UNIT_PIECE,
      'status_id' => 1,
      'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
      'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
      'shop_id' => $this->shop->id,
    ]);
  }

  private function link(string $externalId, Product $product): void
  {
    EventSyncMetadata::create([
      'entity_type' => 'products',
      'source' => '1c',
      'external_id' => $externalId,
      'entity_id' => $product->id,
      'shop_code' => $this->shop->code,
    ]);
  }

  private function stockBatch(array $items): void
  {
    app(ProductBatchHandler::class)->handle([
      'event_id' => (string) Str::uuid(),
      'event_timestamp' => now()->toIso8601String(),
      'event_type' => 'stock_batch_updated',
      'event_source' => '1c',
      'source' => '1c',
      'shop_code' => $this->shop->code,
      'items' => $items,
    ]);
  }

  private function remainFor(Product $p): ?Remain
  {
    return Remain::where('product_id', $p->id)->where('shop_id', $this->shop->id)->first();
  }

  private function processOne(Product $p, int $quantity, bool $bulk): void
  {
    config()->set('rabbitmq-events.bulk_price_batch', $bulk);
    $ext = 'ext-' . Str::random(8);
    $this->link($ext, $p);
    $this->stockBatch([['id' => $ext, 'quantity' => $quantity]]);
  }

  /**
   * Остаток весового товара из 1С — дробные кг (0.828). Bulk-upsert и
   * stockUnchanged раньше приводили к int: 0.828 → 0, а повторный батч с тем
   * же 0.828 считался «без изменений» против сохранённого 0.
   */
  public function test_fractional_kg_stock_is_stored_in_both_paths(): void
  {
    $bulk = $this->product('ST-KG-BULK-' . Str::random(4));
    $single = $this->product('ST-KG-ONE-' . Str::random(4));

    $extBulk = 'ext-kgb-' . Str::random(6);
    $this->link($extBulk, $bulk);
    config()->set('rabbitmq-events.bulk_price_batch', true);
    $this->stockBatch([['id' => $extBulk, 'quantity' => 0.828]]);

    $extOne = 'ext-kgo-' . Str::random(6);
    $this->link($extOne, $single);
    config()->set('rabbitmq-events.bulk_price_batch', false);
    $this->stockBatch([['id' => $extOne, 'quantity' => 2.168]]);

    $this->assertEqualsWithDelta(0.828, (float) $this->remainFor($bulk)->quantity, 0.0005);
    $this->assertEqualsWithDelta(2.168, (float) $this->remainFor($single)->quantity, 0.0005);
  }

  public function test_bulk_writes_stock_in_bounded_queries(): void
  {
    $items = [];
    for ($i = 0; $i < 5; $i++) {
      $ext = 'ext-st-' . $i . '-' . Str::random(4);
      $this->link($ext, $this->product('ST-' . $i . '-' . Str::random(4)));
      $items[] = ['id' => $ext, 'quantity' => 10 + $i];
    }

    DB::enableQueryLog();
    $this->stockBatch($items);
    $writes = collect(DB::getQueryLog())
      ->filter(fn ($q) => preg_match('/^\s*(insert|update)\b.+`?remains`?/i', $q['query']))
      ->count();
    DB::disableQueryLog();

    $this->assertLessThanOrEqual(2, $writes, "remains write-запросов: {$writes}, ожидали bulk");
  }

  public function test_bulk_matches_per_item_positive_quantity(): void
  {
    $bulk = $this->product('SPOS-BULK-' . Str::random(4));
    $item = $this->product('SPOS-ITEM-' . Str::random(4));

    $this->processOne($bulk, 42, true);
    $this->processOne($item, 42, false);

    $this->assertSame((int) $this->remainFor($item)->quantity, (int) $this->remainFor($bulk)->quantity);
    $this->assertSame(42, (int) $this->remainFor($bulk)->quantity);
  }

  public function test_bulk_normalizes_negative_quantity_like_per_item(): void
  {
    $bulk = $this->product('SNEG-BULK-' . Str::random(4));
    $item = $this->product('SNEG-ITEM-' . Str::random(4));

    $this->processOne($bulk, -7, true);
    $this->processOne($item, -7, false);

    $this->assertSame(0, (int) $this->remainFor($bulk)->quantity, 'отрицательный остаток → 0');
    $this->assertSame((int) $this->remainFor($item)->quantity, (int) $this->remainFor($bulk)->quantity);
  }

  public function test_bulk_updates_existing_remain(): void
  {
    $p = $this->product('SUPD-' . Str::random(4));
    Remain::create(['product_id' => $p->id, 'shop_id' => $this->shop->id, 'quantity' => 100]);

    $this->processOne($p, 5, true);

    $this->assertSame(5, (int) $this->remainFor($p)->quantity);
    $this->assertSame(1, Remain::where('product_id', $p->id)->where('shop_id', $this->shop->id)->count());
  }

  public function test_bulk_skips_soft_deleted_product(): void
  {
    $p = $this->product('STRASH-' . Str::random(4));
    $ext = 'ext-st-trash-' . Str::random(6);
    $this->link($ext, $p);
    $p->delete();

    $this->stockBatch([['id' => $ext, 'quantity' => 9]]);

    $this->assertNull($this->remainFor($p), 'в soft-deleted товар остаток не пишется');
  }
}
