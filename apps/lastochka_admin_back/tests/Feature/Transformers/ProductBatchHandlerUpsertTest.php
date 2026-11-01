<?php

namespace Tests\Feature\Transformers;

use App\Models\Product;
use App\Models\Shop;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * product.batch_updated (admin): делегирование позиций в штатный
 * EventConsumer — upsert карточек со всеми механиками пайплайна.
 */
class ProductBatchHandlerUpsertTest extends TestCase
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

  private function handle(array $overrides = []): string
  {
    return app(ProductBatchHandler::class)->handle(array_merge([
      'event_id' => (string) Str::uuid(),
      'event_timestamp' => now()->toIso8601String(),
      'event_type' => 'batch_updated',
      'event_source' => '1c',
      'source' => '1c',
      'shop_code' => $this->shop->code,
      'items' => [],
    ], $overrides));
  }

  public function test_creates_new_product_with_metadata(): void
  {
    $sku = 'BATCH-NEW-' . Str::random(5);

    $result = $this->handle([
      'items' => [
        [
          'id' => 'ext-new-' . Str::random(5),
          'sku' => $sku,
          'title' => 'Новый товар из батча',
          'price' => 149.99,
          'quantity' => 10,
          'active' => true,
          // unit_type обязателен в ProductEventTransformer::$requiredFields
          // для стандартных created/updated событий (не трогаем трансформер).
          'unit_type' => 'piece',
        ],
      ],
    ]);

    $this->assertSame('ack', $result);

    // fromEvent маппит sku → articul (ProductEventTransformer:370); sku
    // уникальный, коллизий с синтетическим суффиксом не будет.
    $product = Product::where('articul', $sku)->firstOrFail();
    $this->assertSame('Новый товар из батча', $product->title);

    $meta = EventSyncMetadata::where('entity_type', 'products')
      ->where('source', '1c')
      ->where('shop_code', $this->shop->code)
      ->where('entity_id', $product->id)
      ->first();
    $this->assertNotNull($meta, 'metadata должна быть создана штатным пайплайном');
  }

  public function test_redelivery_of_batch_message_is_full_noop(): void
  {
    $sku = 'BATCH-REDELIVERY-' . Str::random(5);

    // Один и тот же payload (фиксированный event_id) отправляем дважды —
    // имитация retry/redelivery пакета. Второй вызов должен быть
    // полным no-op: карточка создаётся один раз, title не меняется.
    $payload = [
      'event_id' => (string) Str::uuid(),
      'items' => [
        [
          'id' => 'ext-redelivery-' . Str::random(5),
          'sku' => $sku,
          'title' => 'Товар для проверки повторной доставки',
          'price' => 99.99,
          'active' => true,
          'unit_type' => 'piece',
        ],
      ],
    ];

    $first = $this->handle($payload);
    $this->assertSame('ack', $first);

    $second = $this->handle($payload);
    $this->assertSame('ack', $second);

    $this->assertSame(1, Product::where('articul', $sku)->count());
    $this->assertSame(
      'Товар для проверки повторной доставки',
      Product::where('articul', $sku)->firstOrFail()->title
    );
  }

  public function test_updates_existing_product_on_new_event_id(): void
  {
    $sku = 'BATCH-UPDATE-' . Str::random(5);
    $externalId = 'ext-update-' . Str::random(5);

    $first = $this->handle([
      'items' => [
        [
          'id' => $externalId,
          'sku' => $sku,
          'title' => 'Исходное название',
          'price' => 100.00,
          'active' => true,
          'unit_type' => 'piece',
        ],
      ],
    ]);
    $this->assertSame('ack', $first);

    $product = Product::where('articul', $sku)->firstOrFail();
    $this->assertSame('Исходное название', $product->title);

    // Второй handle() — с новым event_id (генерируется заново по умолчанию
    // в this->handle()) и изменённым title по тому же external id: upsert
    // через штатный пайплайн, а не создание второй карточки.
    $second = $this->handle([
      'items' => [
        [
          'id' => $externalId,
          'sku' => $sku,
          'title' => 'Обновлённое название',
          'price' => 120.00,
          'active' => true,
          'unit_type' => 'piece',
        ],
      ],
    ]);
    $this->assertSame('ack', $second);

    $this->assertSame(1, Product::where('articul', $sku)->count());
    $this->assertSame(
      'Обновлённое название',
      Product::where('articul', $sku)->firstOrFail()->title
    );
    $this->assertSame($product->id, Product::where('articul', $sku)->firstOrFail()->id);
  }

  public function test_invalid_item_does_not_fail_batch(): void
  {
    $sku = 'BATCH-OK-' . Str::random(5);

    $result = $this->handle([
      'items' => [
        ['id' => 'ext-broken-' . Str::random(5)], // нет title — не пройдёт validate
        [
          'id' => 'ext-ok-' . Str::random(5),
          'sku' => $sku,
          'title' => 'Валидный товар',
          'price' => 10.00,
          'active' => true,
          'unit_type' => 'piece',
        ],
      ],
    ]);

    $this->assertSame('ack', $result);
    $this->assertTrue(Product::where('articul', $sku)->exists(), 'валидная позиция обработана');
  }
}
