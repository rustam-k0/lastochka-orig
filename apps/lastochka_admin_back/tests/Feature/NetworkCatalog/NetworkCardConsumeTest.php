<?php

namespace Tests\Feature\NetworkCatalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Консьюмер карточек товара в сетевом режиме (network_catalog).
 *
 * Контракт: карточка товара глобальна для сети — приезжает БЕЗ shop_code и
 * БЕЗ price, владельцем становится якорный магазин. Цены/остатки приходят
 * отдельными пер-магазинными событиями. Легаси-поток (карточка с shop_code и
 * ценой) остаётся рабочим: карточка всё равно живёт в якоре, а цена/остаток
 * ложатся магазину события.
 *
 * При выключенном флаге поведение обязано быть прежним: shop_code и price
 * обязательны, карточка принадлежит магазину события.
 */
class NetworkCardConsumeTest extends TestCase
{
  use DatabaseTransactions;

  private Shop $anchor;
  private Shop $shopA;

  protected function setUp(): void
  {
    parent::setUp();
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    Cache::forget(NetworkCatalogMode::ANCHOR_CACHE_KEY);

    // 'default' не в $fillable у Shop — mass-assignment его молча отбросит,
    // поэтому якорь помечаем отдельным save() (см. NetworkCatalogModeTest).
    $existingAnchor = Shop::query()->where('default', 1)->first();
    if ($existingAnchor !== null) {
      $this->anchor = $existingAnchor;
    } else {
      $this->anchor = Shop::create(['title' => 'Anchor', 'code' => 'anchor-' . uniqid()]);
      $this->anchor->default = 1;
      $this->anchor->save();
    }

    // Код магазина уникален на каждый тест: resolveShopId кэширует code→id
    // статически, повторный code словил бы stale id после отката транзакции.
    $this->shopA = Shop::create(['title' => 'Shop A', 'code' => 'shop-a-' . uniqid()]);

    $this->enableNetworkCatalog();
  }

  protected function tearDown(): void
  {
    Cache::forget(NetworkCatalogMode::CACHE_KEY);
    Cache::forget(NetworkCatalogMode::ANCHOR_CACHE_KEY);
    parent::tearDown();
  }

  private function enableNetworkCatalog(): void
  {
    $raw = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
    $modules = $raw ? (json_decode($raw, true) ?: []) : [];
    $modules['network_catalog'] = ['label' => 'Сетевой каталог', 'enabled' => true];
    DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_at' => now()]
    );
    app(NetworkCatalogMode::class)->flushCache();
  }

  private function disableNetworkCatalog(): void
  {
    $raw = DB::table('admin_settings')->where('key', 'project_modules')->value('value');
    $modules = json_decode((string) $raw, true) ?: [];
    $modules['network_catalog']['enabled'] = false;
    DB::table('admin_settings')->where('key', 'project_modules')
      ->update(['value' => json_encode($modules, JSON_UNESCAPED_UNICODE)]);
    app(NetworkCatalogMode::class)->flushCache();
  }

  /** Payload сетевой карточки: без shop_code и без price. */
  private function networkCardPayload(array $overrides = []): array
  {
    return array_merge([
      'event_id' => (string) Str::uuid(),
      'event_type' => 'created',
      'event_source' => '1c',
      'source' => '1c',
      'id' => 'guid-' . uniqid(),
      'sku' => 'SKU-' . mb_strtoupper(uniqid()),
      'title' => 'Водка Разгуляйка 0.5л',
      'unit_type' => 'piece',
      // 1С кладёт `active` в каждое полное событие; без него status_id
      // не проставится (модельные события в консьюмере заглушены).
      'active' => true,
    ], $overrides);
  }

  /** Имитация пайплайна пакета для создания. */
  private function consumeCreate(ProductEventTransformer $t, array $payload): Product
  {
    $this->assertTrue($t->validate($payload), 'событие должно пройти валидацию');
    $attributes = $t->fromEvent($payload);
    $attributes = $t->beforeCreate($payload, $attributes) ?? $attributes;
    // Точно как EventConsumer: forceFill + save с заглушёнными модельными событиями.
    $product = Product::withoutEvents(function () use ($attributes) {
      $instance = new Product();
      $instance->forceFill($attributes);
      $instance->save();

      return $instance;
    });
    $t->afterCreate($product, $payload);

    return $product->fresh();
  }

  public function test_card_without_shop_code_and_price_is_valid_and_anchored(): void
  {
    $t = app(ProductEventTransformer::class);
    $product = $this->consumeCreate($t, $this->networkCardPayload());

    $this->assertSame($this->anchor->id, $product->shop_id);
  }

  public function test_card_without_shop_code_creates_no_price_and_no_remain(): void
  {
    $t = app(ProductEventTransformer::class);
    // Цена/остаток в карточке без shop_code — «бесхозные»: класть их некуда,
    // магазина в событии нет. Консьюмер обязан их проигнорировать.
    $product = $this->consumeCreate($t, $this->networkCardPayload([
      'price' => 499.90,
      'quantity' => 7,
    ]));

    $this->assertSame(0, $product->prices()->count());
    $this->assertSame(0, $product->remains()->count());
  }

  public function test_legacy_card_with_shop_code_keeps_anchor_shop_id(): void
  {
    $t = app(ProductEventTransformer::class);
    $payload = $this->networkCardPayload([
      'shop_code' => $this->shopA->code,
      'price' => 499.90,
    ]);
    $product = $this->consumeCreate($t, $payload);

    // Карточка в якоре, а цена легла магазину события
    $this->assertSame($this->anchor->id, $product->shop_id);
    $this->assertSame(1, $product->prices()->where('shop_id', $this->shopA->id)->count());
  }

  public function test_update_from_shop_does_not_rewrite_anchor_shop_id(): void
  {
    $t = app(ProductEventTransformer::class);
    $payload = $this->networkCardPayload();
    $product = $this->consumeCreate($t, $payload);

    // Апдейт той же карточки от магазина A (легаси-путь)
    $updatePayload = array_merge($payload, [
      'event_id' => (string) Str::uuid(),
      'event_type' => 'updated',
      'shop_code' => $this->shopA->code,
      'price' => 555.0,
      'title' => 'Водка Разгуляйка 0.5л (обновлено)',
    ]);
    $t->afterUpdate($product, $updatePayload);

    $this->assertSame($this->anchor->id, $product->fresh()->shop_id, 'shop_id карточки не должен перезаписываться');
    $this->assertSame(1, $product->prices()->where('shop_id', $this->shopA->id)->count(), 'цена магазина события пишется как раньше');
  }

  public function test_empty_shop_code_does_not_create_placeholder_shop(): void
  {
    $shopsBefore = Shop::query()->count();

    $t = app(ProductEventTransformer::class);
    // 1С/мост вполне может прислать пустую строку вместо отсутствующего ключа:
    // `??` её не отсекает, и раньше она доезжала до createShopByCode('').
    $product = $this->consumeCreate($t, $this->networkCardPayload([
      'shop_code' => '',
      'price' => 250.0,
      'quantity' => 3,
    ]));

    $this->assertSame($this->anchor->id, $product->shop_id);
    $this->assertSame(0, Shop::query()->where('code', '')->count(), 'магазин-пустышка с пустым code не должен создаваться');
    $this->assertSame($shopsBefore, Shop::query()->count(), 'новых магазинов событие создавать не должно');
    $this->assertSame(0, $product->prices()->count());
    $this->assertSame(0, $product->remains()->count());
  }

  public function test_category_of_network_card_is_bound_to_anchor_shop(): void
  {
    $externalCategoryId = 'cat-' . uniqid();
    $category = Category::create(['title' => 'Крепкий алкоголь ' . uniqid()]);
    EventSyncMetadata::create([
      'entity_type' => 'categories',
      'entity_id' => $category->id,
      'source' => '1c',
      'external_id' => $externalCategoryId,
      'shop_code' => 'default',
      'last_event_id' => (string) Str::uuid(),
      'last_event_timestamp' => now(),
    ]);

    $t = app(ProductEventTransformer::class);
    $product = $this->consumeCreate($t, $this->networkCardPayload([
      'category_id' => $externalCategoryId,
      'category_source' => '1c',
    ]));

    $this->assertSame($this->anchor->id, $product->shop_id);
    $this->assertTrue(
      ShopCategory::query()->where('shop_id', $this->anchor->id)->where('category_id', $category->id)->exists(),
      'категория карточки должна быть привязана к якорному магазину'
    );
    $this->assertSame(
      0,
      ShopCategory::query()->where('shop_id', '!=', $this->anchor->id)->where('category_id', $category->id)->count(),
      'привязки к постороннему магазину (хардкод id=1) быть не должно'
    );
  }

  public function test_updated_card_without_shop_code_and_price_is_valid(): void
  {
    $t = app(ProductEventTransformer::class);

    $this->assertTrue(
      $t->validate($this->networkCardPayload(['event_type' => 'updated'])),
      'updated-событие сетевой карточки валидно без shop_code и price'
    );
  }

  public function test_flag_off_keeps_legacy_behavior(): void
  {
    // выключаем флаг — beforeCreate обязан резолвить магазин из shop_code
    $this->disableNetworkCatalog();

    $t = app(ProductEventTransformer::class);
    $payload = $this->networkCardPayload(['shop_code' => $this->shopA->code, 'price' => 100.0]);
    $product = $this->consumeCreate($t, $payload);

    $this->assertSame($this->shopA->id, $product->shop_id, 'легаси: карточка принадлежит магазину события');
    $this->assertSame(1, $product->prices()->where('shop_id', $this->shopA->id)->count());
  }

  public function test_flag_off_requires_shop_code_and_price(): void
  {
    $this->disableNetworkCatalog();

    $t = app(ProductEventTransformer::class);
    $this->assertFalse($t->validate($this->networkCardPayload()), 'без флага событие без shop_code/price невалидно');
  }
}
