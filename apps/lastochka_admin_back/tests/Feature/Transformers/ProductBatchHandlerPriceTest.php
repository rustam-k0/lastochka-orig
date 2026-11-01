<?php

namespace Tests\Feature\Transformers;

use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use App\Services\Quantum\CategoryQuantumService;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * product.price_batch_updated (admin): bulk-резолв metadata, diff по прайсу
 * магазина (unchanged = no-op без квант-пересчёта), изменённые позиции идут
 * через processPriceUpdatedEvent (processPrice + квант + fill-percent).
 */
class ProductBatchHandlerPriceTest extends TestCase
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

  private function createProduct(string $externalId, ?float $priceValue = null): Product
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

    if ($priceValue !== null) {
      Price::create([
        'product_id' => $product->id,
        'shop_id' => $this->shop->id,
        'type_id' => Type::PRICE_DEFAULT,
        'value' => $priceValue,
        'step' => 1,
      ]);
    }

    return $product;
  }

  private function handle(array $overrides = []): string
  {
    return app(ProductBatchHandler::class)->handle(array_merge([
      'event_id' => (string) Str::uuid(),
      'event_timestamp' => now()->toIso8601String(),
      'event_type' => 'price_batch_updated',
      'event_source' => '1c',
      'source' => '1c',
      'shop_code' => $this->shop->code,
      'items' => [],
    ], $overrides));
  }

  private function priceOf(Product $product): ?Price
  {
    return Price::where('product_id', $product->id)
      ->where('shop_id', $this->shop->id)
      ->where('type_id', Type::PRICE_DEFAULT)
      ->first();
  }

  private function metadataFor(string $externalId): EventSyncMetadata
  {
    return EventSyncMetadata::where('entity_type', 'products')
      ->where('source', '1c')
      ->where('shop_code', $this->shop->code)
      ->where('external_id', $externalId)
      ->firstOrFail();
  }

  public function test_updates_changed_price_and_skips_unchanged_without_quantum(): void
  {
    $changed = $this->createProduct('ext-a', 100.00);
    $same = $this->createProduct('ext-b', 200.00);

    // Квант-пересчёт должен дёрнуться РОВНО один раз — для изменённой позиции
    $quantum = $this->mock(CategoryQuantumService::class);
    $quantum->shouldReceive('applyToProduct')->once();

    $eventId = (string) Str::uuid();
    $result = $this->handle([
      'event_id' => $eventId,
      'items' => [
        ['id' => 'ext-a', 'sku' => 'ART-ext-a', 'price' => 149.99, 'old_price' => 100.00],
        ['id' => 'ext-b', 'sku' => 'ART-ext-b', 'price' => 200.00],
      ],
    ]);

    $this->assertSame('ack', $result);
    $this->assertEqualsWithDelta(149.99, (float) $this->priceOf($changed)->value, 0.001);
    $this->assertEqualsWithDelta(200.00, (float) $this->priceOf($same)->value, 0.001);

    // metadata bump у обеих позиций
    $this->assertSame($eventId, $this->metadataFor('ext-a')->last_event_id);
    $this->assertSame($eventId, $this->metadataFor('ext-b')->last_event_id);
  }

  public function test_discount_price_applies_like_single_event(): void
  {
    $product = $this->createProduct('ext-d', 329.00);

    $this->handle([
      'items' => [
        ['id' => 'ext-d', 'sku' => 'ART-ext-d', 'price' => 329.00, 'discount_price' => 259.00],
      ],
    ]);

    $price = $this->priceOf($product);
    $this->assertEqualsWithDelta(259.00, (float) $price->value, 0.001);
    $this->assertEqualsWithDelta(329.00, (float) $price->price_without_discount, 0.001);
  }

  public function test_product_without_price_row_goes_full_path_and_creates_price(): void
  {
    $product = $this->createProduct('ext-new'); // без Price

    $this->handle([
      'items' => [['id' => 'ext-new', 'sku' => 'ART-ext-new', 'price' => 77.00]],
    ]);

    $this->assertEqualsWithDelta(77.00, (float) $this->priceOf($product)->value, 0.001);
  }

  public function test_missing_metadata_is_skipped_and_rest_processed(): void
  {
    $known = $this->createProduct('ext-a', 100.00);

    $result = $this->handle([
      'items' => [
        ['id' => 'ext-ghost', 'sku' => 'ART-ghost', 'price' => 1.00],
        ['id' => 'ext-a', 'sku' => 'ART-ext-a', 'price' => 111.00],
      ],
    ]);

    $this->assertSame('ack', $result);
    $this->assertEqualsWithDelta(111.00, (float) $this->priceOf($known)->value, 0.001);
  }

  public function test_stale_envelope_is_skipped(): void
  {
    $product = $this->createProduct('ext-a', 100.00); // metadata timestamp = -1 час

    $this->handle([
      'event_timestamp' => now()->subHours(2)->toIso8601String(),
      'items' => [['id' => 'ext-a', 'sku' => 'ART-ext-a', 'price' => 1.00]],
    ]);

    $this->assertEqualsWithDelta(100.00, (float) $this->priceOf($product)->value, 0.001);
  }

  public function test_redelivery_of_same_message_is_noop(): void
  {
    $product = $this->createProduct('ext-a', 100.00);

    $payload = [
      'event_id' => (string) Str::uuid(),
      'items' => [['id' => 'ext-a', 'sku' => 'ART-ext-a', 'price' => 149.99]],
    ];

    $this->assertSame('ack', $this->handle($payload));
    $this->assertSame('ack', $this->handle($payload));

    $this->assertEqualsWithDelta(149.99, (float) $this->priceOf($product)->value, 0.001);
  }

  public function test_broken_item_does_not_fail_batch(): void
  {
    $a = $this->createProduct('ext-a', 100.00);
    $b = $this->createProduct('ext-b', 200.00);

    $result = $this->handle([
      'items' => [
        ['id' => 'ext-a', 'sku' => 'ART-ext-a'], // нет price
        ['id' => 'ext-b', 'sku' => 'ART-ext-b', 'price' => 222.00],
      ],
    ]);

    $this->assertSame('ack', $result);
    $this->assertEqualsWithDelta(100.00, (float) $this->priceOf($a)->value, 0.001);
    $this->assertEqualsWithDelta(222.00, (float) $this->priceOf($b)->value, 0.001);
  }
}
