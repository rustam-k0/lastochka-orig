<?php

namespace Tests\Feature\Transformers;

use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Transformers\ProductBatchEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Валидация конверта батч-событий товаров (validate-only трансформер).
 *
 * Контракт сетевого каталога (docs/specs/2026-08-09-1c-network-catalog-contract.md,
 * раздел 3): конверт `product.batch_updated` в сетевом режиме приходит БЕЗ
 * shop_code — карточка глобальна. Для price/stock-батчей shop_code обязателен
 * всегда (раздел 4-5). При выключенном флаге валидация — байт-в-байт легаси.
 */
class ProductBatchEnvelopeValidationTest extends TestCase
{
  use DatabaseTransactions;

  private ProductBatchEventTransformer $transformer;

  protected function setUp(): void
  {
    parent::setUp();
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    $this->transformer = new ProductBatchEventTransformer();
  }

  protected function tearDown(): void
  {
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    parent::tearDown();
  }

  private function setNetworkCatalog(bool $enabled): void
  {
    $raw = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
    $modules = $raw ? (json_decode($raw, true) ?: []) : [];
    $modules['network_catalog'] = ['label' => 'Сетевой каталог', 'enabled' => $enabled];
    DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );
    app(NetworkCatalogMode::class)->flushCache();
  }

  private function envelope(string $eventType, array $overrides = []): array
  {
    return array_merge([
      'event_id' => (string) Str::uuid(),
      'event_timestamp' => now()->toIso8601String(),
      'event_type' => $eventType,
      'event_source' => '1c',
      'source' => '1c',
      'items' => [
        ['id' => (string) Str::uuid(), 'sku' => 'SKU-1', 'title' => 'Товар', 'unit_type' => 'piece'],
      ],
    ], $overrides);
  }

  public function test_batch_updated_envelope_without_shop_code_is_valid_in_network_mode(): void
  {
    $this->setNetworkCatalog(true);

    $this->assertTrue($this->transformer->validate($this->envelope('batch_updated')));
  }

  public function test_batch_updated_envelope_with_shop_code_stays_valid_in_network_mode(): void
  {
    $this->setNetworkCatalog(true);

    $this->assertTrue($this->transformer->validate(
      $this->envelope('batch_updated', ['shop_code' => 'shop-legacy'])
    ));
  }

  public function test_batch_updated_envelope_without_shop_code_is_rejected_when_flag_off(): void
  {
    $this->setNetworkCatalog(false);

    $this->assertFalse($this->transformer->validate($this->envelope('batch_updated')));
  }

  public function test_price_and_stock_batch_envelopes_require_shop_code_even_in_network_mode(): void
  {
    $this->setNetworkCatalog(true);

    $this->assertFalse($this->transformer->validate($this->envelope('price_batch_updated')));
    $this->assertFalse($this->transformer->validate($this->envelope('stock_batch_updated')));
  }
}
