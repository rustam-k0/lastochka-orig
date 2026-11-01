<?php

namespace Tests\Feature\Transformers;

use App\Models\Product;
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
 * Bulk-путь обработки price_batch_updated для штучных (не-квантовых) товаров:
 * массовая запись цен вместо per-item транзакций, за фича-флагом
 * rabbitmq-events.bulk_price_batch. Весовые/квантовые остаются на полном пути.
 */
class ProductPriceBatchBulkTest extends TestCase
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
      'title' => 'Bulk-магазин',
      'code' => 'BLK_' . Str::random(6),
    ]);
  }

  protected function tearDown(): void
  {
    Model::reguard();
    parent::tearDown();
  }

  /**
   * Штучный товар: PIECE base-unit, без ручного кванта и квант-категории →
   * resolveQuantum() = null → bulk-eligible.
   */
  private function pieceProduct(string $sku): Product
  {
    return Product::create([
      'title' => 'Штучный ' . $sku,
      'articul' => $sku,
      'unit_type_id' => Type::UNIT_PIECE,
      'status_id' => 1,
      'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
      'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
      'shop_id' => $this->shop->id,
    ]);
  }

  private function linkMetadata(string $externalId, Product $product): void
  {
    EventSyncMetadata::create([
      'entity_type' => 'products',
      'source' => '1c',
      'external_id' => $externalId,
      'entity_id' => $product->id,
      'shop_code' => $this->shop->code,
    ]);
  }

  private function priceBatch(array $items): string
  {
    return app(ProductBatchHandler::class)->handle([
      'event_id' => (string) Str::uuid(),
      'event_timestamp' => now()->toIso8601String(),
      'event_type' => 'price_batch_updated',
      'event_source' => '1c',
      'source' => '1c',
      'shop_code' => $this->shop->code,
      'items' => $items,
    ]);
  }

  /** Обрабатывает один item по указанному товару с заданным флагом bulk. */
  private function processOne(Product $product, array $item, bool $bulk): void
  {
    config()->set('rabbitmq-events.bulk_price_batch', $bulk);
    $ext = 'ext-' . Str::random(8);
    $this->linkMetadata($ext, $product);
    $this->priceBatch([array_merge(['id' => $ext], $item)]);
  }

  private function priceRow(Product $product): \App\Models\Price
  {
    return \App\Models\Price::where('product_id', $product->id)
      ->where('shop_id', $this->shop->id)
      ->where('type_id', Type::PRICE_DEFAULT)
      ->firstOrFail();
  }

  private function assertPriceRowsEqual(\App\Models\Price $a, \App\Models\Price $b): void
  {
    foreach (['value', 'price_without_discount', 'price_per_small_unit', 'step', 'price_per_kg'] as $f) {
      $this->assertEqualsWithDelta(
        (float) $a->$f, (float) $b->$f, 0.0001,
        "Поле {$f} расходится: bulk={$a->$f} vs per-item={$b->$f}"
      );
    }
  }

  /** Весовой без фасовки: база кг, кванта нет → тоже bulk-eligible. */
  private function kilogramProduct(string $sku): Product
  {
    return Product::create([
      'title' => 'Весовой ' . $sku,
      'articul' => $sku,
      'unit_type_id' => Type::UNIT_FOOD,
      'status_id' => 1,
      'info_weight_type_id' => Type::INFO_WEIGHT_KILOGRAM,
      'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
      'shop_id' => $this->shop->id,
    ]);
  }

  public function test_bulk_kilogram_price_keeps_kopecks_like_per_item(): void
  {
    // 199.99 ₽/кг: ₽/г до 4 знаков давал 0.2000 → 200.00 ₽/кг.
    $bulk = $this->kilogramProduct('KG-BULK-' . Str::random(4));
    $item = $this->kilogramProduct('KG-ITEM-' . Str::random(4));

    $this->processOne($bulk, ['price' => 199.99], true);
    $this->processOne($item, ['price' => 199.99], false);

    $bulkRow = $this->priceRow($bulk);
    $this->assertSame(199.99, (float) $bulkRow->value);
    $this->assertSame(199.99, (float) $bulkRow->price_per_kg);
    $this->assertPriceRowsEqual($bulkRow, $this->priceRow($item));
  }

  public function test_bulk_matches_per_item_without_discount(): void
  {
    $bulk = $this->pieceProduct('EQ-ND-BULK-' . Str::random(4));
    $item = $this->pieceProduct('EQ-ND-ITEM-' . Str::random(4));

    $this->processOne($bulk, ['price' => 149.99], true);
    $this->processOne($item, ['price' => 149.99], false);

    $this->assertPriceRowsEqual($this->priceRow($bulk), $this->priceRow($item));
  }

  public function test_bulk_matches_per_item_with_discount(): void
  {
    $bulk = $this->pieceProduct('EQ-D-BULK-' . Str::random(4));
    $item = $this->pieceProduct('EQ-D-ITEM-' . Str::random(4));

    $this->processOne($bulk, ['price' => 200.00, 'discount_price' => 159.50], true);
    $this->processOne($item, ['price' => 200.00, 'discount_price' => 159.50], false);

    $bulkRow = $this->priceRow($bulk);
    $this->assertEqualsWithDelta(159.50, (float) $bulkRow->value, 0.0001);
    $this->assertEqualsWithDelta(200.00, (float) $bulkRow->price_without_discount, 0.0001);
    $this->assertPriceRowsEqual($bulkRow, $this->priceRow($item));
  }

  public function test_bulk_dispatches_product_updated_like_per_item(): void
  {
    \Illuminate\Support\Facades\Event::fake([\App\Events\ProductUpdated::class]);

    $bulk = $this->pieceProduct('EV-BULK-' . Str::random(4));
    $item = $this->pieceProduct('EV-ITEM-' . Str::random(4));

    $this->processOne($bulk, ['price' => 149.99], true);
    $this->processOne($item, ['price' => 149.99], false);

    $countFor = fn (Product $p) => collect(
      \Illuminate\Support\Facades\Event::dispatched(\App\Events\ProductUpdated::class)
    )->filter(fn ($e) => (int) ($e[0]->product->id ?? 0) === (int) $p->id)->count();

    $this->assertSame(
      $countFor($item), $countFor($bulk),
      "Число ProductUpdated должно совпадать: per-item={$countFor($item)}, bulk={$countFor($bulk)}"
    );
  }

  public function test_bulk_matches_per_item_product_state(): void
  {
    $bulk = $this->pieceProduct('PS-BULK-' . Str::random(4));
    $item = $this->pieceProduct('PS-ITEM-' . Str::random(4));

    $this->processOne($bulk, ['price' => 149.99], true);
    $this->processOne($item, ['price' => 149.99], false);

    $bulk->refresh();
    $item->refresh();

    $this->assertSame((int) $item->info_weight_type_id, (int) $bulk->info_weight_type_id);
    $this->assertSame((int) ($item->info_weight ?? 0), (int) ($bulk->info_weight ?? 0));
  }

  private function existingPrice(Product $product, float $value): void
  {
    \App\Models\Price::create([
      'product_id' => $product->id,
      'shop_id' => $this->shop->id,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => $value,
      'price_per_small_unit' => $value,
      'step' => 1,
    ]);
  }

  public function test_bulk_updates_existing_rows_in_bounded_queries(): void
  {
    $items = [];
    for ($i = 0; $i < 5; $i++) {
      $p = $this->pieceProduct('UPD-' . $i . '-' . Str::random(4));
      $this->existingPrice($p, 50.00); // старая цена уже есть
      $ext = 'ext-upd-' . $i . '-' . Str::random(4);
      $this->linkMetadata($ext, $p);
      $items[] = ['id' => $ext, 'price' => 100 + $i]; // меняем
    }

    DB::enableQueryLog();
    $this->priceBatch($items);
    $writes = collect(DB::getQueryLog())
      ->filter(fn ($q) => preg_match('/^\s*(insert|update)\b.+`?prices`?/i', $q['query']))
      ->count();
    DB::disableQueryLog();

    $this->assertLessThanOrEqual(3, $writes, "prices write-запросов на 5 изменений: {$writes}");
  }

  public function test_bulk_update_matches_per_item_value_and_event(): void
  {
    \Illuminate\Support\Facades\Event::fake([\App\Events\ProductUpdated::class]);

    $bulk = $this->pieceProduct('UEQ-BULK-' . Str::random(4));
    $item = $this->pieceProduct('UEQ-ITEM-' . Str::random(4));
    $this->existingPrice($bulk, 50.00);
    $this->existingPrice($item, 50.00);

    $this->processOne($bulk, ['price' => 175.00], true);
    $this->processOne($item, ['price' => 175.00], false);

    // Значение обновилось одинаково.
    $this->assertPriceRowsEqual($this->priceRow($bulk), $this->priceRow($item));
    $this->assertEqualsWithDelta(175.00, (float) $this->priceRow($bulk)->value, 0.0001);

    // Число ProductUpdated должно совпадать с per-item путём (для штучной цены
    // это 0: processPrice пишет value до applyToProduct, тот не видит изменения;
    // цены в storefront идут напрямую от 1c_parser, admin их не ре-публикует).
    $countFor = fn (Product $p) => collect(
      \Illuminate\Support\Facades\Event::dispatched(\App\Events\ProductUpdated::class)
    )->filter(fn ($e) => (int) ($e[0]->product->id ?? 0) === (int) $p->id)->count();

    $this->assertSame($countFor($item), $countFor($bulk), 'bulk должен диспатчить столько же ProductUpdated, сколько per-item');
  }

  public function test_bulk_matches_per_item_fill_percent(): void
  {
    $bulk = $this->pieceProduct('FP-BULK-' . Str::random(4));
    $item = $this->pieceProduct('FP-ITEM-' . Str::random(4));

    $this->processOne($bulk, ['price' => 149.99], true);
    $this->processOne($item, ['price' => 149.99], false);

    $this->assertSame(
      (int) $item->fresh()->fill_percent,
      (int) $bulk->fresh()->fill_percent,
      'fill_percent bulk-товара должен совпадать с per-item (цена участвует в формуле)'
    );
  }

  public function test_bulk_value_update_does_not_dispatch_fill_job(): void
  {
    // Обновление значения существующей цены не меняет наличие цены (value>0
    // и до, и после): ценовая компонента fill_percent та же, пересчёт не нужен,
    // fill-джоба не диспатчится (иначе на проде холостая нагрузка на воркер).
    \Illuminate\Support\Facades\Queue::fake();

    $p = $this->pieceProduct('FPU-' . Str::random(4));
    $this->existingPrice($p, 50.00);
    $ext = 'ext-fpu-' . Str::random(6);
    $this->linkMetadata($ext, $p);

    $this->priceBatch([['id' => $ext, 'price' => 180.00]]);

    \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RecalculateProductFillPercentJob::class);
  }

  public function test_bulk_dispatches_fill_recalc_job(): void
  {
    \Illuminate\Support\Facades\Queue::fake();

    $p = $this->pieceProduct('JOB-' . Str::random(4));
    $ext = 'ext-job-' . Str::random(6);
    $this->linkMetadata($ext, $p);

    $this->priceBatch([['id' => $ext, 'price' => 99.00]]);

    \Illuminate\Support\Facades\Queue::assertPushed(
      \App\Jobs\RecalculateProductFillPercentJob::class,
      fn ($job) => in_array($p->id, $job->productIds, true)
    );
  }

  public function test_bulk_skips_soft_deleted_product(): void
  {
    $product = $this->pieceProduct('TRASH-' . Str::random(4));
    $ext = 'ext-trash-' . Str::random(6);
    $this->linkMetadata($ext, $product);
    $product->delete(); // soft-delete: metadata ещё указывает сюда

    $this->priceBatch([['id' => $ext, 'price' => 99.00]]);

    $this->assertSame(
      0,
      \App\Models\Price::where('product_id', $product->id)->count(),
      'В soft-deleted товар цена писаться не должна (guard раздела 6 ТЗ)'
    );
  }

  public function test_shadow_mode_writes_via_per_item_and_logs_zero_divergence(): void
  {
    config()->set('rabbitmq-events.bulk_price_batch', false);
    config()->set('rabbitmq-events.bulk_price_batch_shadow', true);
    \Illuminate\Support\Facades\Log::spy();

    $p = $this->pieceProduct('SHW-' . Str::random(4));
    $ext = 'ext-shw-' . Str::random(6);
    $this->linkMetadata($ext, $p);

    $this->priceBatch([['id' => $ext, 'price' => 133.00]]);

    // Цена записана штатным (per-item) путём.
    $this->assertEqualsWithDelta(133.00, (float) $this->priceRow($p)->value, 0.0001);

    // Shadow сверил bulk-результат с фактом и залогировал 0 расхождений.
    // notice-уровень: проходит прод LOG_LEVEL=notice (info там режется).
    \Illuminate\Support\Facades\Log::shouldHaveReceived('notice')
      ->withArgs(fn ($message, $context = []) => $message === 'price_batch_shadow_compare'
        && ($context['compared'] ?? 0) > 0
        && ($context['diverged'] ?? -1) === 0)
      ->atLeast()->once();
  }

  public function test_bulk_path_writes_prices_in_bounded_queries(): void
  {
    $items = [];
    for ($i = 0; $i < 5; $i++) {
      $ext = 'ext-bulk-' . $i . '-' . Str::random(4);
      $this->linkMetadata($ext, $this->pieceProduct('BULK-' . $i . '-' . Str::random(4)));
      $items[] = ['id' => $ext, 'price' => 100 + $i];
    }

    DB::enableQueryLog();
    $result = $this->priceBatch($items);
    $writes = collect(DB::getQueryLog())
      ->filter(fn ($q) => preg_match('/^\s*(insert|update)\b.+`?prices`?/i', $q['query']))
      ->count();
    DB::disableQueryLog();

    $this->assertSame('ack', $result);
    // Per-item путь делал бы >=2 записи в prices на позицию (>=10 на 5 штук).
    // Bulk-путь укладывается в единицы запросов.
    $this->assertLessThanOrEqual(3, $writes, "prices write-запросов: {$writes}, ожидали bulk");
  }
}
