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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * product.stock_batch_updated (admin): Remain per shop, отрицательные
 * остатки нормализуются к 0 (как processQuantity), unchanged = no-op.
 */
class ProductBatchHandlerStockTest extends TestCase
{
  use DatabaseTransactions;

  private Shop $shop;

  protected function setUp(): void
  {
    parent::setUp();
    Model::unguard();
    config()->set('rabbitmq-events.enabled', false);

    $this->shop = Shop::create([
      'title' => 'Батч-магазин',
      'code' => 'BT_' . Str::random(6),
    ]);
  }

  protected function tearDown(): void
  {
    Model::reguard();
    parent::tearDown();
  }

  private function createProduct(string $externalId, ?int $remainQty = null): Product
  {
    $product = Product::create([
      'title' => 'Товар ' . $externalId,
      'articul' => 'ART-' . $externalId,
      'unit_type_id' => Type::UNIT_FOOD,
      'status_id' => 1,
      'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
      'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
      'shop_id' => $this->shop->id,
    ]);

    EventSyncMetadata::create([
      'entity_type' => 'products',
      'entity_id' => $product->id,
      'source' => '1c',
      'external_id' => $externalId,
      'shop_code' => $this->shop->code,
      'last_event_id' => (string) Str::uuid(),
      'last_event_timestamp' => now()->subHour(),
    ]);

    if ($remainQty !== null) {
      Remain::create([
        'product_id' => $product->id,
        'shop_id' => $this->shop->id,
        'quantity' => $remainQty,
      ]);
    }

    return $product;
  }

  private function handle(array $overrides = []): string
  {
    return app(ProductBatchHandler::class)->handle(array_merge([
      'event_id' => (string) Str::uuid(),
      'event_timestamp' => now()->toIso8601String(),
      'event_type' => 'stock_batch_updated',
      'event_source' => '1c',
      'source' => '1c',
      'shop_code' => $this->shop->code,
      'warehouse_id' => 'wh-1',
      'warehouse_name' => 'Склад 1',
      'items' => [],
    ], $overrides));
  }

  private function remainOf(Product $product): ?Remain
  {
    return Remain::where('product_id', $product->id)
      ->where('shop_id', $this->shop->id)
      ->first();
  }

  public function test_updates_remains_and_creates_missing_row(): void
  {
    $existing = $this->createProduct('ext-a', 5);
    $withoutRemain = $this->createProduct('ext-b');

    $result = $this->handle([
      'items' => [
        ['id' => 'ext-a', 'sku' => 'ART-ext-a', 'quantity' => 12],
        ['id' => 'ext-b', 'sku' => 'ART-ext-b', 'quantity' => 3],
      ],
    ]);

    $this->assertSame('ack', $result);
    $this->assertSame(12, (int) $this->remainOf($existing)->quantity);
    $this->assertSame(3, (int) $this->remainOf($withoutRemain)->quantity);
  }

  public function test_negative_quantity_normalized_to_zero(): void
  {
    $product = $this->createProduct('ext-a', 5);

    $this->handle([
      'items' => [['id' => 'ext-a', 'sku' => 'ART-ext-a', 'quantity' => -7]],
    ]);

    $this->assertSame(0, (int) $this->remainOf($product)->quantity);
  }

  public function test_unchanged_quantity_still_bumps_metadata(): void
  {
    $product = $this->createProduct('ext-a', 5);

    $eventId = (string) Str::uuid();
    $this->handle([
      'event_id' => $eventId,
      'items' => [['id' => 'ext-a', 'sku' => 'ART-ext-a', 'quantity' => 5]],
    ]);

    $this->assertSame(5, (int) $this->remainOf($product)->quantity);
    $this->assertSame($eventId, EventSyncMetadata::where('external_id', 'ext-a')
      ->where('shop_code', $this->shop->code)->firstOrFail()->last_event_id);
  }

  public function test_missing_quantity_is_error_but_batch_survives(): void
  {
    $a = $this->createProduct('ext-a', 5);
    $b = $this->createProduct('ext-b', 5);

    $result = $this->handle([
      'items' => [
        ['id' => 'ext-a', 'sku' => 'ART-ext-a'], // нет quantity
        ['id' => 'ext-b', 'sku' => 'ART-ext-b', 'quantity' => 42],
      ],
    ]);

    $this->assertSame('ack', $result);
    $this->assertSame(5, (int) $this->remainOf($a)->quantity);
    $this->assertSame(42, (int) $this->remainOf($b)->quantity);
  }
}
