<?php

namespace Tests\Feature\NetworkCatalog;

use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Type;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NetworkCatalogResetCommandTest extends TestCase
{
  use DatabaseTransactions;

  protected function setUp(): void
  {
    parent::setUp();
    Cache::forget(NetworkCatalogMode::CACHE_KEY);

    $modules = json_decode((string) DB::table('admin_settings')->where('key', 'project_modules')->value('value'), true) ?: [];
    $modules['network_catalog'] = ['label' => 'Сетевой каталог', 'enabled' => true];
    DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );
    app(NetworkCatalogMode::class)->flushCache();
  }

  protected function tearDown(): void
  {
    Cache::forget(NetworkCatalogMode::CACHE_KEY);

    // TRUNCATE в команде — implicit commit, DatabaseTransactions такое не
    // откатывает (см. примечание в брифе задачи 6). Без явного возврата флага
    // в выключенное состояние успешный прогон test_purges_catalog_tables
    // навсегда оставляет network_catalog.enabled=true в mendev_test и валит
    // соседние тесты (например NetworkCatalogModeTest::migration_seeded_module_disabled).
    $modules = json_decode((string) DB::table('admin_settings')->where('key', 'project_modules')->value('value'), true) ?: [];
    if (($modules['network_catalog']['enabled'] ?? null) !== false) {
      $modules['network_catalog']['enabled'] = false;
      DB::table('admin_settings')->where('key', 'project_modules')
        ->update(['value' => json_encode($modules, JSON_UNESCAPED_UNICODE)]);
    }

    parent::tearDown();
  }

  public function test_refuses_without_confirm_flag(): void
  {
    $this->artisan('network-catalog:reset')->assertExitCode(1);
  }

  public function test_refuses_when_module_disabled(): void
  {
    $modules = json_decode((string) DB::table('admin_settings')->where('key', 'project_modules')->value('value'), true) ?: [];
    $modules['network_catalog']['enabled'] = false;
    DB::table('admin_settings')->where('key', 'project_modules')
      ->update(['value' => json_encode($modules, JSON_UNESCAPED_UNICODE)]);
    app(NetworkCatalogMode::class)->flushCache();

    $this->artisan('network-catalog:reset --confirm')->assertExitCode(1);
  }

  public function test_purges_catalog_tables(): void
  {
    $shop = Shop::create(['title' => 'S', 'code' => 'reset-' . uniqid()]);
    $product = Product::withoutEvents(fn () => Product::create([
      'title' => 'Товар на удаление', 'articul' => 'DEL-' . uniqid(), 'shop_id' => $shop->id,
      // withoutEvents глушит static::creating() из boot() Product — дефолты
      // unit_type_id/status_id оттуда не проставятся, задаём явно (см. паттерн
      // в QuarantineExportTest::makeProduct).
      'unit_type_id' => Type::UNIT_PIECE, 'status_id' => \App\Models\Status::CODE_ACTIVE,
    ]));
    Price::withoutEvents(fn () => (new Price())->forceFill([
      'product_id' => $product->id, 'shop_id' => $shop->id,
      'type_id' => Type::PRICE_DEFAULT, 'value' => 1, 'step' => 1,
    ])->save());
    Remain::withoutEvents(fn () => (new Remain())->forceFill([
      'product_id' => $product->id, 'shop_id' => $shop->id, 'quantity' => 1,
    ])->save());
    EventSyncMetadata::create([
      'entity_type' => 'products', 'source' => '1c', 'external_id' => 'guid-del',
      'shop_code' => $shop->code, 'entity_id' => $product->id,
      'last_event_id' => (string) Str::uuid(), 'last_event_timestamp' => now(),
    ]);

    $this->artisan('network-catalog:reset --confirm')->assertExitCode(0);

    $this->assertSame(0, DB::table('products')->count());
    $this->assertSame(0, DB::table('prices')->count());
    $this->assertSame(0, DB::table('remains')->count());
    $this->assertSame(0, DB::table('event_sync_metadata')->where('entity_type', 'products')->count());
    // магазины НЕ трогаем
    $this->assertNotSame(0, DB::table('shops')->count());
  }

  /**
   * --with-orders обязан чистить и синк-метаданные заказов: TRUNCATE orders
   * сбрасывает AUTO_INCREMENT, и уцелевшие event_sync_metadata указывают либо
   * в никуда («Entity not found» → событие теряется после ретраев), либо в
   * ЧУЖУЮ новую строку с переиспользованным id (тихий мёрж). Оба сценария
   * пойманы на dev (MID-235: заказы витрины №4/№5 не доехали, №2 смёржился
   * в e2e-артефакт).
   */
  public function test_with_orders_purges_order_sync_metadata_and_outbox(): void
  {
    foreach (['orders', 'order_items'] as $entityType) {
      EventSyncMetadata::create([
        'entity_type' => $entityType, 'source' => 'storefront', 'external_id' => 'stale-' . uniqid(),
        'shop_code' => 'mendev', 'entity_id' => 999999999,
        'last_event_id' => (string) Str::uuid(), 'last_event_timestamp' => now(),
      ]);
    }
    DB::table('event_outbox')->insert([
      'entity_type' => 'orders', 'entity_id' => 999999999, 'event_type' => 'updated',
      'event_id' => (string) Str::uuid(), 'routing_key' => 'order.updated',
      'payload' => '{}', 'created_at' => now(),
    ]);
    // Метадаты других сущностей переживают чистку заказов
    $customerMeta = EventSyncMetadata::create([
      'entity_type' => 'customers', 'source' => 'storefront', 'external_id' => 'keep-' . uniqid(),
      'shop_code' => 'mendev', 'entity_id' => 1,
      'last_event_id' => (string) Str::uuid(), 'last_event_timestamp' => now(),
    ]);

    $this->artisan('network-catalog:reset --confirm --with-orders')->assertExitCode(0);

    $this->assertSame(0, DB::table('event_sync_metadata')->whereIn('entity_type', ['orders', 'order_items'])->count());
    $this->assertSame(0, DB::table('event_outbox')->whereIn('entity_type', ['orders', 'order_items'])->count());
    $this->assertNotNull(EventSyncMetadata::find($customerMeta->id));
  }
}
