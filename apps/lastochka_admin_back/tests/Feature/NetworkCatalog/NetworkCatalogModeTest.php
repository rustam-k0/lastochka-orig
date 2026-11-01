<?php

namespace Tests\Feature\NetworkCatalog;

use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NetworkCatalogModeTest extends TestCase
{
  use DatabaseTransactions;

  protected function setUp(): void
  {
    parent::setUp();
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    Cache::forget(NetworkCatalogMode::ANCHOR_CACHE_KEY);
  }

  protected function tearDown(): void
  {
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    Cache::forget(NetworkCatalogMode::ANCHOR_CACHE_KEY);
    parent::tearDown();
  }

  private function setModules(array $modules): void
  {
    DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );
  }

  public function test_disabled_by_default(): void
  {
    $this->setModules([]);
    $this->assertFalse(app(NetworkCatalogMode::class)->isEnabled());
  }

  public function test_enabled_when_module_on(): void
  {
    $this->setModules(['network_catalog' => ['label' => 'Сетевой каталог', 'enabled' => true]]);
    $this->assertTrue(app(NetworkCatalogMode::class)->isEnabled());
  }

  public function test_flush_cache_rereads_value(): void
  {
    $this->setModules(['network_catalog' => ['label' => 'x', 'enabled' => false]]);
    $mode = app(NetworkCatalogMode::class);
    $this->assertFalse($mode->isEnabled());

    $this->setModules(['network_catalog' => ['label' => 'x', 'enabled' => true]]);
    $this->assertFalse($mode->isEnabled(), 'кэш ещё держит false');
    $mode->flushCache();
    $this->assertTrue($mode->isEnabled());
  }

  public function test_anchor_shop_is_default_shop(): void
  {
    // уникальный code на каждый тест: resolveShopId статически кэширует code->id.
    // Если в базе уже есть default-магазин — ассертим его id (см. правку в брифе:
    // конфликт с уже существующим default=1 магазином в тестовой БД).
    $existing = Shop::query()->where('default', 1)->value('id');
    if ($existing !== null) {
      $this->assertSame((int) $existing, app(NetworkCatalogMode::class)->anchorShopId());
      return;
    }
    // 'default' не в $fillable у Shop (сеттится только напрямую/миграциями),
    // поэтому mass-assignment через create() его молча отбросит — выставляем отдельно.
    $shop = Shop::create(['title' => 'Anchor', 'code' => 'anchor-' . uniqid()]);
    $shop->default = 1;
    $shop->save();
    $this->assertSame($shop->id, app(NetworkCatalogMode::class)->anchorShopId());
  }

  public function test_migration_seeded_module_disabled(): void
  {
    // модуль посеян миграцией и выключен (fail-safe форков)
    $raw = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
    $modules = json_decode($raw, true) ?: [];
    $this->assertArrayHasKey('network_catalog', $modules);
    $this->assertFalse((bool) data_get($modules, 'network_catalog.enabled'));
  }
}
