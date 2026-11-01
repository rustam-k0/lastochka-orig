# Сетевой маппинг категорий 1С — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Единый (сетевой) справочник 1С-категорий и единый маппинг на витринные категории для всей сети магазинов, за проектным флагом `category_1c_network_mapping` (default off — на форках поведение бит-в-бит текущее).

**Architecture:** Все события `category_1c.*` при включённом флаге нормализуются к якорному `shop_id` (схема БД не меняется, UNIQUE `(shop_id, external_id, deleted_at)` даёт дедуп). Резолв категорий у товаров всех магазинов идёт по якорю, Apply обходит товары всей сети, витрины распространяются на все магазины асинхронным job'ом. Спека: `docs/superpowers/specs/2026-07-23-category-1c-network-mapping-design.md`.

**Tech Stack:** Laravel 9 / PHP 8.1 (admin_back), Vue 3 Options API (admin_front), comrades/laravel-rabbitmq-events (`global_scope`), PHPUnit + DatabaseTransactions на локальной БД `mendev_test`.

## Global Constraints

- Флаг = projectModules-модуль `category_1c_network_mapping` c ключом `anchor_shop_id`; «сетевой режим включён» ⇔ `ModuleConfigService::getCategory1cNetworkAnchorShopId() !== null`.
- Флаг выключен → ни одно поведение не меняется (форк-безопасность, broadcast sync-core).
- Схема БД НЕ меняется, миграций нет.
- Тесты запускать ТОЛЬКО локально и ТОЛЬКО через `--filter` (общая БД `mendev_test`, baseline-падения фоновых тестов игнорировать — см. memory `mendev-local-testing`).
- Работа в ветке `feat/category-1c-network-mapping` (admin_back и admin_front). Push/merge — только по команде пользователя.
- Названия классов/методов/команд — строго из спеки: `isCategory1cNetworkMappingEnabled`, `getCategory1cNetworkAnchorShopId`, `PropagateShowcaseCategoriesToShopsJob`, `category-1c:normalize-network-scope`.
- Стиль отступов файла сохранять (Category1cEventTransformer — 4 пробела, ProductEventTransformer/LinkToShowcase/Category.php — 2 пробела).
- Комментарии в коде — только там, где ограничение не видно из кода; без «сделано по спеке такой-то» в каждой строке.

---

### Task 0: Ветка

**Files:** нет изменений кода.

- [ ] **Step 1: Создать ветку admin_back**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_admin_back
git checkout master && git pull
git checkout -b feat/category-1c-network-mapping
```

- [ ] **Step 2: Создать ветку admin_front**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_admin_front
git checkout master && git pull
git checkout -b feat/category-1c-network-mapping
```

---

### Task 1: ModuleConfigService — флаг и якорь

**Files:**
- Modify: `app/Services/ModuleConfigService.php`
- Create: `tests/Support/InteractsWithCategory1cNetworkMode.php`
- Test: `tests/Unit/Services/ModuleConfigServiceCategory1cNetworkTest.php`

**Interfaces:**
- Produces: `ModuleConfigService::isCategory1cNetworkMappingEnabled(): bool`; `ModuleConfigService::getCategory1cNetworkAnchorShopId(): ?int` (null = сетевой режим выключен/якорь некорректен); тест-трейт `Tests\Support\InteractsWithCategory1cNetworkMode` с `enableCategory1cNetworkMode(int $anchorShopId)` / `disableCategory1cNetworkMode()`.

- [ ] **Step 1: Написать тест-трейт**

Создать `tests/Support/InteractsWithCategory1cNetworkMode.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AdminSetting;
use App\Services\ModuleConfigService;

/**
 * Включение/выключение сетевого режима категорий 1С в тестах.
 * После каждой записи настройки сбрасывает кеш ModuleConfigService.
 *
 * tearDown обязателен: кеш (array driver) живёт весь процесс PHPUnit, а
 * DatabaseTransactions откатывает только БД — без сброса включённый режим
 * «протёк» бы в следующие тесты (включая тесты без этого трейта, создающие
 * Shop → ShopObserver увидел бы устаревший якорь).
 */
trait InteractsWithCategory1cNetworkMode
{
    protected function tearDown(): void
    {
        ModuleConfigService::invalidateCache();
        parent::tearDown();
    }

    protected function enableCategory1cNetworkMode(int $anchorShopId): void
    {
        $this->writeCategory1cNetworkModule([
            'label' => 'Сетевой маппинг категорий 1С',
            'enabled' => true,
            'anchor_shop_id' => $anchorShopId,
        ]);
    }

    protected function disableCategory1cNetworkMode(): void
    {
        $this->writeCategory1cNetworkModule([
            'label' => 'Сетевой маппинг категорий 1С',
            'enabled' => false,
        ]);
    }

    protected function writeCategory1cNetworkModule(?array $module): void
    {
        $config = $module === null
            ? []
            : [ModuleConfigService::CATEGORY_1C_NETWORK_KEY => $module];

        AdminSetting::setValue(
            ModuleConfigService::PROJECT_MODULES_KEY,
            json_encode($config),
            'json'
        );
        ModuleConfigService::invalidateCache();
    }
}
```

- [ ] **Step 2: Написать падающий тест**

Создать `tests/Unit/Services/ModuleConfigServiceCategory1cNetworkTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class ModuleConfigServiceCategory1cNetworkTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    public function test_disabled_module_returns_false_and_null_anchor(): void
    {
        $this->disableCategory1cNetworkMode();

        $this->assertFalse(ModuleConfigService::isCategory1cNetworkMappingEnabled());
        $this->assertNull(ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }

    public function test_missing_module_returns_false_and_null_anchor(): void
    {
        $this->writeCategory1cNetworkModule(null);

        $this->assertFalse(ModuleConfigService::isCategory1cNetworkMappingEnabled());
        $this->assertNull(ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }

    public function test_enabled_with_anchor_returns_true_and_anchor(): void
    {
        $this->enableCategory1cNetworkMode(5);

        $this->assertTrue(ModuleConfigService::isCategory1cNetworkMappingEnabled());
        $this->assertSame(5, ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }

    public function test_enabled_without_anchor_returns_null_anchor(): void
    {
        $this->writeCategory1cNetworkModule([
            'label' => 'x',
            'enabled' => true,
        ]);

        $this->assertTrue(ModuleConfigService::isCategory1cNetworkMappingEnabled());
        $this->assertNull(ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }

    public function test_enabled_with_invalid_anchor_returns_null_anchor(): void
    {
        $this->writeCategory1cNetworkModule([
            'label' => 'x',
            'enabled' => true,
            'anchor_shop_id' => 'not-a-number',
        ]);

        $this->assertNull(ModuleConfigService::getCategory1cNetworkAnchorShopId());

        $this->writeCategory1cNetworkModule([
            'label' => 'x',
            'enabled' => true,
            'anchor_shop_id' => 0,
        ]);

        $this->assertNull(ModuleConfigService::getCategory1cNetworkAnchorShopId());
    }
}
```

- [ ] **Step 3: Запустить тест — убедиться что падает**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_admin_back
vendor/bin/phpunit --filter=ModuleConfigServiceCategory1cNetworkTest
```
Expected: FAIL — `Undefined constant ModuleConfigService::CATEGORY_1C_NETWORK_KEY` / method not found.

- [ ] **Step 4: Реализация в ModuleConfigService**

В `app/Services/ModuleConfigService.php` после констант `ASSEMBLY_COST_*` добавить:

```php
    public const CATEGORY_1C_NETWORK_KEY = 'category_1c_network_mapping';
    public const CACHE_KEY_CATEGORY_1C_NETWORK_ENABLED = 'module_config:admin:category_1c_network_enabled';
    public const CACHE_KEY_CATEGORY_1C_NETWORK_ANCHOR = 'module_config:admin:category_1c_network_anchor';
```

После `isAssemblyCostEnabled()` добавить (по образцу соседей):

```php
    /**
     * Включён ли сетевой маппинг категорий 1С (единый справочник на всю сеть).
     * Fail-safe: любые исключения → false.
     */
    public static function isCategory1cNetworkMappingEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_CATEGORY_1C_NETWORK_ENABLED,
                now()->addMinutes(5),
                fn () => self::readBooleanModule(self::CATEGORY_1C_NETWORK_KEY)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read category_1c_network_mapping flag, defaulting to false', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Якорный магазин сетевого справочника категорий 1С.
     *
     * null = сетевой режим фактически выключен: модуль выключен, либо
     * anchor_shop_id отсутствует/некорректен (fail-safe — магазин не угадываем).
     * В кеше null представлен как 0 (Cache::remember не различает null и miss).
     */
    public static function getCategory1cNetworkAnchorShopId(): ?int
    {
        try {
            $anchor = (int) Cache::remember(
                self::CACHE_KEY_CATEGORY_1C_NETWORK_ANCHOR,
                now()->addMinutes(5),
                function () {
                    if (!self::readBooleanModule(self::CATEGORY_1C_NETWORK_KEY)) {
                        return 0;
                    }

                    $module = self::readModuleArray(self::CATEGORY_1C_NETWORK_KEY);
                    $value = $module['anchor_shop_id'] ?? null;

                    if (!is_numeric($value) || (int) $value <= 0) {
                        Log::warning('category_1c_network_mapping enabled but anchor_shop_id is missing or invalid — network mode treated as disabled');

                        return 0;
                    }

                    return (int) $value;
                }
            );

            return $anchor > 0 ? $anchor : null;
        } catch (Throwable $e) {
            Log::warning('Failed to read category_1c_network_mapping anchor, defaulting to null', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
```

В `invalidateCache()` добавить:

```php
        Cache::forget(self::CACHE_KEY_CATEGORY_1C_NETWORK_ENABLED);
        Cache::forget(self::CACHE_KEY_CATEGORY_1C_NETWORK_ANCHOR);
```

После `readBooleanModule()` добавить:

```php
    /**
     * Читает объект модуля из project_modules JSON blob как есть.
     */
    private static function readModuleArray(string $moduleKey): ?array
    {
        $raw = AdminSetting::getValue(self::PROJECT_MODULES_KEY);

        if (!$raw) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        $module = $decoded[$moduleKey] ?? null;

        return is_array($module) ? $module : null;
    }
```

- [ ] **Step 5: Запустить тест — PASS**

```bash
vendor/bin/phpunit --filter=ModuleConfigServiceCategory1cNetworkTest
```
Expected: OK (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/ModuleConfigService.php tests/Support/InteractsWithCategory1cNetworkMode.php tests/Unit/Services/ModuleConfigServiceCategory1cNetworkTest.php
git commit -m "feat: флаг сетевого маппинга категорий 1С в ModuleConfigService"
```

---

### Task 2: Category1cEventTransformer — нормализация к якорю + global_scope

**Files:**
- Modify: `app/Transformers/Category1cEventTransformer.php` (fromEvent ~строка 51, findExistingEntity ~120)
- Create: `app/Services/Category1c/NetworkScopeConfigurator.php`
- Modify: `app/Providers/AppServiceProvider.php` (boot)
- Test: `tests/Unit/Category1c/Category1cNetworkTransformerTest.php`

**Interfaces:**
- Consumes: `ModuleConfigService::getCategory1cNetworkAnchorShopId()` (Task 1), трейт `InteractsWithCategory1cNetworkMode`.
- Produces: `NetworkScopeConfigurator::apply(): void` — включает `rabbitmq-events.entities.categories_1c.global_scope` при сетевом режиме; вызывается из `AppServiceProvider::boot()`.

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Unit/Category1c/Category1cNetworkTransformerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Category1c;

use App\Models\Category1c;
use App\Models\Shop;
use App\Services\Category1c\NetworkScopeConfigurator;
use App\Transformers\Category1cEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class Category1cNetworkTransformerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    public function test_from_event_uses_anchor_shop_in_network_mode(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $attrs = (new Category1cEventTransformer())->fromEvent([
            'id' => 'guid-net-1',
            'shop_code' => $other->code,
            'title' => 'Молоко',
        ]);

        $this->assertSame($anchor->id, $attrs['shop_id']);
    }

    public function test_from_event_resolves_shop_code_when_network_mode_off(): void
    {
        $other = $this->makeShop('other-' . uniqid());
        $this->disableCategory1cNetworkMode();

        $attrs = (new Category1cEventTransformer())->fromEvent([
            'id' => 'guid-net-2',
            'shop_code' => $other->code,
            'title' => 'Молоко',
        ]);

        $this->assertSame($other->id, $attrs['shop_id']);
    }

    public function test_find_existing_entity_matches_anchor_row_for_any_shop_code(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $existing = Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-net-3',
        ]);

        $found = (new Category1cEventTransformer())->findExistingEntity(
            ['id' => 'guid-net-3', 'shop_code' => $other->code],
            []
        );

        $this->assertNotNull($found);
        $this->assertSame($existing->id, $found->id);
    }

    public function test_find_existing_entity_is_shop_scoped_when_network_mode_off(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->disableCategory1cNetworkMode();

        Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-net-4',
        ]);

        $found = (new Category1cEventTransformer())->findExistingEntity(
            ['id' => 'guid-net-4', 'shop_code' => $other->code],
            []
        );

        $this->assertNull($found);
    }

    public function test_configurator_enables_global_scope_only_in_network_mode(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());

        $this->disableCategory1cNetworkMode();
        config(['rabbitmq-events.entities.categories_1c.global_scope' => false]);
        NetworkScopeConfigurator::apply();
        $this->assertFalse(config('rabbitmq-events.entities.categories_1c.global_scope'));

        $this->enableCategory1cNetworkMode($anchor->id);
        NetworkScopeConfigurator::apply();
        $this->assertTrue(config('rabbitmq-events.entities.categories_1c.global_scope'));
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

```bash
vendor/bin/phpunit --filter=Category1cNetworkTransformerTest
```
Expected: FAIL — `Class "App\Services\Category1c\NetworkScopeConfigurator" not found`, и network-тесты fromEvent/findExistingEntity падают на assert.

- [ ] **Step 3: Реализация**

3a. Создать `app/Services/Category1c/NetworkScopeConfigurator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Category1c;

use App\Services\ModuleConfigService;

/**
 * При включённом сетевом режиме включает global_scope пакета для categories_1c:
 * метадата-идентичность (source, external_id) без shop_code — одна запись
 * справочника на все магазины сети.
 *
 * Вызывается из AppServiceProvider::boot(). Воркеры-консьюмеры долгоживущие:
 * после переключения флага нужен рестарт supervisor-группы *_rabbitmq.
 */
class NetworkScopeConfigurator
{
    public static function apply(): void
    {
        if (ModuleConfigService::getCategory1cNetworkAnchorShopId() !== null) {
            config(['rabbitmq-events.entities.categories_1c.global_scope' => true]);
        }
    }
}
```

3b. В `app/Providers/AppServiceProvider.php` в `boot()` рядом с регистрацией observers добавить:

```php
    \App\Services\Category1c\NetworkScopeConfigurator::apply();
```

3c. В `app/Transformers/Category1cEventTransformer.php` добавить импорт `use App\Services\ModuleConfigService;`. В `fromEvent()` заменить блок

```php
        if (array_key_exists('shop_code', $eventData) && is_string($eventData['shop_code']) && $eventData['shop_code'] !== '') {
            $attrs['shop_id'] = $this->resolveShopId($eventData['shop_code']);
        }
```

на

```php
        $anchorShopId = ModuleConfigService::getCategory1cNetworkAnchorShopId();

        if ($anchorShopId !== null) {
            // Сетевой режим: весь справочник живёт под якорным магазином,
            // shop_code события игнорируется (в конверте остаётся обязательным).
            $attrs['shop_id'] = $anchorShopId;
        } elseif (array_key_exists('shop_code', $eventData) && is_string($eventData['shop_code']) && $eventData['shop_code'] !== '') {
            $attrs['shop_id'] = $this->resolveShopId($eventData['shop_code']);
        }
```

3d. `findExistingEntity()` заменить целиком на:

```php
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $shopCode = $eventData['shop_code'] ?? null;
        $externalId = $eventData['id'] ?? null;

        if ($externalId === null || $externalId === '') {
            return null;
        }

        $shopId = ModuleConfigService::getCategory1cNetworkAnchorShopId();

        if ($shopId === null) {
            if (!is_string($shopCode) || $shopCode === '') {
                return null;
            }

            try {
                $shopId = $this->resolveShopId($shopCode);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return Category1c::withTrashed()
            ->where('shop_id', $shopId)
            ->where('external_id', (string) $externalId)
            ->first();
    }
```

- [ ] **Step 4: Запустить — PASS**

```bash
vendor/bin/phpunit --filter=Category1cNetworkTransformerTest
```
Expected: OK (5 tests).

- [ ] **Step 5: Регрессия существующих тестов трансформера**

```bash
vendor/bin/phpunit --filter=Category1cEventTransformerTest
```
Expected: OK (падения, существовавшие до ветки, допустимы только если они в baseline — см. Global Constraints).

- [ ] **Step 6: Commit**

```bash
git add app/Transformers/Category1cEventTransformer.php app/Services/Category1c/NetworkScopeConfigurator.php app/Providers/AppServiceProvider.php tests/Unit/Category1c/Category1cNetworkTransformerTest.php
git commit -m "feat: нормализация категорий 1С к якорному магазину в сетевом режиме"
```

---

### Task 3: ProductEventTransformer — сетевой резолв category_1c у товаров

**Files:**
- Modify: `app/Transformers/ProductEventTransformer.php` (~строки 478–496, блок `category_1c_external_id`; стиль файла — 2 пробела)
- Test: `tests/Unit/Category1c/ProductEventTransformerNetworkCategoryTest.php`

**Interfaces:**
- Consumes: `ModuleConfigService::getCategory1cNetworkAnchorShopId()`.
- Produces: товар любого магазина получает `category_1c_id` сетевой (якорной) записи. Покрывает одиночные события и батчи (`ProductBatchHandler` переиспользует этот трансформер).

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Unit/Category1c/ProductEventTransformerNetworkCategoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Category1c;

use App\Models\Category1c;
use App\Models\Shop;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class ProductEventTransformerNetworkCategoryTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    public function test_resolves_category_from_anchor_for_foreign_shop_product(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $cat1c = Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-prod-net-1',
        ]);

        $attrs = (new ProductEventTransformer())->fromEvent([
            'shop_code' => $other->code,
            'category_1c_external_id' => 'guid-prod-net-1',
        ]);

        $this->assertSame($cat1c->id, $attrs['category_1c_id']);
        $this->assertNull($attrs['pending_category_1c_external_id']);
    }

    public function test_stores_pending_when_anchor_category_missing(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $attrs = (new ProductEventTransformer())->fromEvent([
            'shop_code' => $other->code,
            'category_1c_external_id' => 'guid-prod-net-missing',
        ]);

        $this->assertNull($attrs['category_1c_id']);
        $this->assertSame('guid-prod-net-missing', $attrs['pending_category_1c_external_id']);
    }

    public function test_shop_scoped_resolution_when_network_mode_off(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->disableCategory1cNetworkMode();

        Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-prod-net-2',
        ]);

        $attrs = (new ProductEventTransformer())->fromEvent([
            'shop_code' => $other->code,
            'category_1c_external_id' => 'guid-prod-net-2',
        ]);

        $this->assertNull($attrs['category_1c_id']);
        $this->assertSame('guid-prod-net-2', $attrs['pending_category_1c_external_id']);
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

```bash
vendor/bin/phpunit --filter=ProductEventTransformerNetworkCategoryTest
```
Expected: FAIL первые два теста (категория чужого магазина не находится → category_1c_id null/pending).

- [ ] **Step 3: Реализация**

В `app/Transformers/ProductEventTransformer.php` добавить импорт `use App\Services\ModuleConfigService;` (если отсутствует). Заменить внутри блока `category_1c_external_id` строки

```php
        $shopCode = $data['shop_code'] ?? config('rabbitmq-events.shop_code', 'default');
        $externalId = (string) $data['category_1c_external_id'];
        $cat1c = Category1c::where('shop_id', $this->resolveShopId((string) $shopCode))
          ->where('external_id', $externalId)
          ->first();
```

на

```php
        $externalId = (string) $data['category_1c_external_id'];
        $cat1cShopId = ModuleConfigService::getCategory1cNetworkAnchorShopId();
        if ($cat1cShopId === null) {
          $shopCode = $data['shop_code'] ?? config('rabbitmq-events.shop_code', 'default');
          $cat1cShopId = $this->resolveShopId((string) $shopCode);
        }
        $cat1c = Category1c::where('shop_id', $cat1cShopId)
          ->where('external_id', $externalId)
          ->first();
```

- [ ] **Step 4: Запустить — PASS**

```bash
vendor/bin/phpunit --filter=ProductEventTransformerNetworkCategoryTest
```
Expected: OK (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Transformers/ProductEventTransformer.php tests/Unit/Category1c/ProductEventTransformerNetworkCategoryTest.php
git commit -m "feat: сетевой резолв category_1c_external_id у товаров всех магазинов"
```

---

### Task 4: Category1cObserver — кросс-магазинный resolvePending

**Files:**
- Modify: `app/Observers/Category1cObserver.php` (`resolvePending`, ~строка 52)
- Test: `tests/Unit/Observers/Category1cObserverNetworkTest.php`

**Interfaces:**
- Consumes: `ModuleConfigService::getCategory1cNetworkAnchorShopId()`.
- Produces: pending-товары ВСЕХ магазинов дозаполняются при приезде сетевой Category1c.

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Unit/Observers/Category1cObserverNetworkTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Models\Category1c;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class Category1cObserverNetworkTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    private function makePendingProduct(Shop $shop, string $externalId): Product
    {
        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Pending product ' . uniqid();
        $product->pending_category_1c_external_id = $externalId;
        $product->save();

        return $product;
    }

    public function test_resolves_pending_products_of_all_shops_in_network_mode(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $shopB = $this->makeShop('shop-b-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $productA = $this->makePendingProduct($shopA, 'guid-obs-net-1');
        $productB = $this->makePendingProduct($shopB, 'guid-obs-net-1');

        $category = Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-obs-net-1',
        ]);

        $this->assertSame($category->id, $productA->fresh()->category_1c_id);
        $this->assertSame($category->id, $productB->fresh()->category_1c_id);
        $this->assertNull($productA->fresh()->pending_category_1c_external_id);
    }

    public function test_resolves_only_same_shop_products_when_network_mode_off(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $this->disableCategory1cNetworkMode();

        $productAnchor = $this->makePendingProduct($anchor, 'guid-obs-net-2');
        $productA = $this->makePendingProduct($shopA, 'guid-obs-net-2');

        $category = Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-obs-net-2',
        ]);

        $this->assertSame($category->id, $productAnchor->fresh()->category_1c_id);
        $this->assertNull($productA->fresh()->category_1c_id);
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

```bash
vendor/bin/phpunit --filter=Category1cObserverNetworkTest
```
Expected: FAIL первый тест — товар чужого магазина остаётся pending.

- [ ] **Step 3: Реализация**

В `app/Observers/Category1cObserver.php` добавить импорт `use App\Services\ModuleConfigService;`. В `resolvePending()` заменить

```php
        $productIds = Product::query()
            ->where('shop_id', $shopId)
            ->where('pending_category_1c_external_id', $externalId)
            ->whereNull('category_1c_id')
            ->pluck('id');
```

на

```php
        $networkMode = ModuleConfigService::getCategory1cNetworkAnchorShopId() !== null;

        $productIds = Product::query()
            ->when(!$networkMode, fn ($query) => $query->where('shop_id', $shopId))
            ->where('pending_category_1c_external_id', $externalId)
            ->whereNull('category_1c_id')
            ->pluck('id');
```

- [ ] **Step 4: Запустить — PASS**

```bash
vendor/bin/phpunit --filter=Category1cObserverNetworkTest
```
Expected: OK (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Observers/Category1cObserver.php tests/Unit/Observers/Category1cObserverNetworkTest.php
git commit -m "feat: resolvePending дозаполняет pending-товары всех магазинов в сетевом режиме"
```

---

### Task 5: SyncCategoryMappingsJob — Apply по всей сети

**Files:**
- Modify: `app/Jobs/Category1c/SyncCategoryMappingsJob.php` (handle, запросы Product)
- Test: `tests/Unit/Jobs/Category1c/SyncCategoryMappingsJobNetworkTest.php`

**Interfaces:**
- Consumes: `ModuleConfigService::getCategory1cNetworkAnchorShopId()`; job запускается с `shopId` = якорь (фронт передаёт якорный shop_id, StartSync не меняется).
- Produces: в сетевом режиме синхронизируются товары ВСЕХ магазинов, чьи Category1c (якорные) имеют витрины.

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Unit/Jobs/Category1c/SyncCategoryMappingsJobNetworkTest.php` (фикстуры по образцу `SyncCategoryMappingsJobMultiTest`):

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Category1c;

use App\Jobs\Category1c\SyncCategoryMappingsJob;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Category1cSyncBatch;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class SyncCategoryMappingsJobNetworkTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    private function makeProduct(Shop $shop, int $cat1cId): Product
    {
        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Test product ' . uniqid();
        $product->category_1c_id = $cat1cId;
        $product->save();

        return $product;
    }

    private function makeBatch(int $shopId): Category1cSyncBatch
    {
        return Category1cSyncBatch::create([
            'shop_id' => $shopId,
            'status' => 'PENDING',
            'started_at' => now(),
        ]);
    }

    public function test_network_mode_syncs_products_of_all_shops(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $shopB = $this->makeShop('shop-b-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);
        $cat1c = Category1c::factory()->create(['shop_id' => $anchor->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $productA = $this->makeProduct($shopA, $cat1c->id);
        $productB = $this->makeProduct($shopB, $cat1c->id);

        $batch = $this->makeBatch($anchor->id);
        (new SyncCategoryMappingsJob($batch->id, $anchor->id))->handle();

        $this->assertSame('COMPLETED', $batch->fresh()->status);
        $this->assertSame(2, $batch->fresh()->progress_total);
        $this->assertTrue($productA->fresh()->categories->pluck('id')->contains($showcase->id));
        $this->assertTrue($productB->fresh()->categories->pluck('id')->contains($showcase->id));
    }

    public function test_per_shop_mode_ignores_foreign_shop_products(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $this->disableCategory1cNetworkMode();

        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);
        $cat1c = Category1c::factory()->create(['shop_id' => $anchor->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $productAnchor = $this->makeProduct($anchor, $cat1c->id);
        $productA = $this->makeProduct($shopA, $cat1c->id);

        $batch = $this->makeBatch($anchor->id);
        (new SyncCategoryMappingsJob($batch->id, $anchor->id))->handle();

        $this->assertTrue($productAnchor->fresh()->categories->pluck('id')->contains($showcase->id));
        $this->assertFalse($productA->fresh()->categories->pluck('id')->contains($showcase->id));
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

```bash
vendor/bin/phpunit --filter=SyncCategoryMappingsJobNetworkTest
```
Expected: FAIL первый тест — товар магазина B не получает витрину, progress_total=0 или 1.

- [ ] **Step 3: Реализация**

В `app/Jobs/Category1c/SyncCategoryMappingsJob.php` добавить импорт `use App\Services\ModuleConfigService;`. В `handle()` заменить

```php
            $eligible = fn ($q) => $q
                ->where('shop_id', $this->shopId)
                ->whereHas('showcaseCategories');

            $total = Product::query()
                ->where('shop_id', $this->shopId)
                ->whereHas('category1c', $eligible)
                ->count();
```

на

```php
            $networkMode = ModuleConfigService::getCategory1cNetworkAnchorShopId() !== null;

            $eligible = fn ($q) => $q
                ->where('shop_id', $this->shopId)
                ->whereHas('showcaseCategories');

            // Сетевой режим: товары всех магазинов; категории — якорного ($this->shopId).
            $products = fn () => Product::query()
                ->when(!$networkMode, fn ($q) => $q->where('shop_id', $this->shopId))
                ->whereHas('category1c', $eligible);

            $total = $products()->count();
```

и ниже заменить начало обхода

```php
            Product::query()
                ->where('shop_id', $this->shopId)
                ->whereHas('category1c', $eligible)
                ->with(['category1c.showcaseCategories:id', 'categories:id'])
```

на

```php
            $products()
                ->with(['category1c.showcaseCategories:id', 'categories:id'])
```

- [ ] **Step 4: Запустить — PASS + регрессия**

```bash
vendor/bin/phpunit --filter=SyncCategoryMappingsJobNetworkTest
vendor/bin/phpunit --filter=SyncCategoryMappingsJobMultiTest
vendor/bin/phpunit --filter=SyncCategoryMappingsJobTest
```
Expected: OK во всех трёх.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Category1c/SyncCategoryMappingsJob.php tests/Unit/Jobs/Category1c/SyncCategoryMappingsJobNetworkTest.php
git commit -m "feat: Apply маппинга категорий 1С по товарам всей сети в сетевом режиме"
```

---

### Task 6: PropagateShowcaseCategoriesToShopsJob

**Files:**
- Create: `app/Jobs/Category1c/PropagateShowcaseCategoriesToShopsJob.php`
- Test: `tests/Unit/Jobs/Category1c/PropagateShowcaseCategoriesToShopsJobTest.php`

**Interfaces:**
- Consumes: `ShopService::attachCategoriesWithEvents(Shop $shop, array $categoryIds): void` (существующий; аддитивный, расширяет предками, touch только новых → category.updated в outbox).
- Produces: `PropagateShowcaseCategoriesToShopsJob::dispatch(array $showcaseCategoryIds, ?int $onlyShopId = null)` — привязка витрин ко всем (или одному) магазинам. Используется Task 7 и Task 8.

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Unit/Jobs/Category1c/PropagateShowcaseCategoriesToShopsJobTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Category1c;

use App\Jobs\Category1c\PropagateShowcaseCategoriesToShopsJob;
use App\Models\Category;
use App\Models\Shop;
use App\Services\ShopService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PropagateShowcaseCategoriesToShopsJobTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    public function test_attaches_showcases_to_all_shops(): void
    {
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $shopB = $this->makeShop('shop-b-' . uniqid());
        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);

        (new PropagateShowcaseCategoriesToShopsJob([$showcase->id]))->handle(app(ShopService::class));

        $this->assertTrue($shopA->categories()->where('categories.id', $showcase->id)->exists());
        $this->assertTrue($shopB->categories()->where('categories.id', $showcase->id)->exists());
    }

    public function test_is_idempotent(): void
    {
        $shop = $this->makeShop('shop-' . uniqid());
        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);

        (new PropagateShowcaseCategoriesToShopsJob([$showcase->id]))->handle(app(ShopService::class));
        (new PropagateShowcaseCategoriesToShopsJob([$showcase->id]))->handle(app(ShopService::class));

        $this->assertSame(
            1,
            $shop->categories()->where('categories.id', $showcase->id)->count()
        );
    }

    public function test_only_shop_id_limits_scope(): void
    {
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $shopB = $this->makeShop('shop-b-' . uniqid());
        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);

        (new PropagateShowcaseCategoriesToShopsJob([$showcase->id], $shopA->id))->handle(app(ShopService::class));

        $this->assertTrue($shopA->categories()->where('categories.id', $showcase->id)->exists());
        $this->assertFalse($shopB->categories()->where('categories.id', $showcase->id)->exists());
    }

    public function test_empty_ids_is_noop(): void
    {
        $shop = $this->makeShop('shop-' . uniqid());

        (new PropagateShowcaseCategoriesToShopsJob([]))->handle(app(ShopService::class));

        $this->assertSame(0, $shop->categories()->count());
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

```bash
vendor/bin/phpunit --filter=PropagateShowcaseCategoriesToShopsJobTest
```
Expected: FAIL — class not found.

- [ ] **Step 3: Реализация**

Создать `app/Jobs/Category1c/PropagateShowcaseCategoriesToShopsJob.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Category1c;

use App\Models\Shop;
use App\Services\ShopService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Распространяет витринные категории на магазины сети (сетевой режим
 * маппинга категорий 1С): для каждого магазина attachCategoriesWithEvents —
 * аддитивно, с предками, с публикацией category.updated только по новым связям.
 *
 * $onlyShopId — ограничение одним магазином (сценарий «в сеть добавили магазин»).
 */
class PropagateShowcaseCategoriesToShopsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public array $showcaseCategoryIds,
        public ?int $onlyShopId = null,
    ) {
    }

    public function handle(ShopService $shopService): void
    {
        if ($this->showcaseCategoryIds === []) {
            return;
        }

        Shop::query()
            ->when($this->onlyShopId !== null, fn ($q) => $q->where('id', $this->onlyShopId))
            ->lazyById(100)
            ->each(function (Shop $shop) use ($shopService) {
                $shopService->attachCategoriesWithEvents($shop, $this->showcaseCategoryIds);
            });
    }
}
```

- [ ] **Step 4: Запустить — PASS**

```bash
vendor/bin/phpunit --filter=PropagateShowcaseCategoriesToShopsJobTest
```
Expected: OK (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Category1c/PropagateShowcaseCategoriesToShopsJob.php tests/Unit/Jobs/Category1c/PropagateShowcaseCategoriesToShopsJobTest.php
git commit -m "feat: job распространения витринных категорий на магазины сети"
```

---

### Task 7: LinkToShowcase — сетевая ветка авто-привязки витрин

**Files:**
- Modify: `app/GraphQL/Mutations/Category1cMutations/LinkToShowcase.php` (~строки 82–89; стиль — 4 пробела)
- Test: `tests/Feature/Category1cLinkNetworkTest.php`

**Interfaces:**
- Consumes: `PropagateShowcaseCategoriesToShopsJob` (Task 6), `ModuleConfigService::getCategory1cNetworkAnchorShopId()`.
- Produces: в сетевом режиме Link диспатчит асинхронное распространение витрин на все магазины вместо синхронного attach к магазину 1С-категории.

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Feature/Category1cLinkNetworkTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\GraphQL\Mutations\Category1cMutations\LinkToShowcase;
use App\Jobs\Category1c\PropagateShowcaseCategoriesToShopsJob;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class Category1cLinkNetworkTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    public function test_network_mode_dispatches_propagate_job(): void
    {
        Queue::fake();
        $anchor = $this->makeShop('anchor-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);
        $cat1c = Category1c::factory()->create(['shop_id' => $anchor->id]);

        (new LinkToShowcase())(null, [
            'category_1c_ids' => [$cat1c->id],
            'showcase_category_ids' => [$showcase->id],
        ]);

        Queue::assertPushed(
            PropagateShowcaseCategoriesToShopsJob::class,
            fn ($job) => $job->showcaseCategoryIds === [$showcase->id] && $job->onlyShopId === null
        );
        $this->assertTrue($cat1c->fresh()->showcaseCategories->pluck('id')->contains($showcase->id));
        // Синхронного attach к магазину не было — распространение асинхронное.
        $this->assertFalse($anchor->categories()->where('categories.id', $showcase->id)->exists());
    }

    public function test_per_shop_mode_attaches_synchronously_without_job(): void
    {
        Queue::fake();
        $shop = $this->makeShop('shop-' . uniqid());
        $this->disableCategory1cNetworkMode();

        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);

        (new LinkToShowcase())(null, [
            'category_1c_ids' => [$cat1c->id],
            'showcase_category_ids' => [$showcase->id],
        ]);

        Queue::assertNotPushed(PropagateShowcaseCategoriesToShopsJob::class);
        $this->assertTrue($shop->categories()->where('categories.id', $showcase->id)->exists());
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

```bash
vendor/bin/phpunit --filter=Category1cLinkNetworkTest
```
Expected: FAIL первый тест — job не пушится, attach синхронный.

- [ ] **Step 3: Реализация**

В `app/GraphQL/Mutations/Category1cMutations/LinkToShowcase.php` добавить импорты:

```php
use App\Jobs\Category1c\PropagateShowcaseCategoriesToShopsJob;
use App\Services\ModuleConfigService;
```

Заменить блок авто-привязки

```php
        // Авто-привязка витрин к магазину каждой 1С-категории: если витрина (или её
        // предки) ещё не в shops_categories магазина — добавляем. Делаем ДО основной
        // транзакции, для каждого магазина один раз (весь набор витрин аддитивно).
        $shopService = app(ShopService::class);
        foreach ($cats1c->pluck('shop_id')->unique() as $shopId) {
            $shop = Shop::find($shopId);
            if ($shop) {
                $shopService->attachCategoriesWithEvents($shop, $showcaseIds);
            }
        }
```

на

```php
        // Авто-привязка витрин к магазинам. Сетевой режим: асинхронно ко ВСЕМ
        // магазинам сети (синхронный цикл по 800+ магазинам заблокировал бы мутацию).
        // Пер-магазинный режим: синхронно к магазинам выбранных 1С-категорий.
        if (ModuleConfigService::getCategory1cNetworkAnchorShopId() !== null) {
            PropagateShowcaseCategoriesToShopsJob::dispatch($showcaseIds);
        } else {
            $shopService = app(ShopService::class);
            foreach ($cats1c->pluck('shop_id')->unique() as $shopId) {
                $shop = Shop::find($shopId);
                if ($shop) {
                    $shopService->attachCategoriesWithEvents($shop, $showcaseIds);
                }
            }
        }
```

- [ ] **Step 4: Запустить — PASS**

```bash
vendor/bin/phpunit --filter=Category1cLinkNetworkTest
```
Expected: OK (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/GraphQL/Mutations/Category1cMutations/LinkToShowcase.php tests/Feature/Category1cLinkNetworkTest.php
git commit -m "feat: Link в сетевом режиме распространяет витрины на все магазины асинхронно"
```

---

### Task 8: Category::category1cMappings фикс + ShopObserver для новых магазинов

**Files:**
- Modify: `app/Models/Category.php` (~строка 263 — мёртвая relation на удалённую колонку `showcase_category_id`; стиль — 2 пробела)
- Create: `app/Observers/ShopObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (регистрация observer)
- Test: `tests/Unit/Observers/ShopObserverNetworkTest.php`

**Interfaces:**
- Consumes: `PropagateShowcaseCategoriesToShopsJob` (Task 6).
- Produces: рабочая relation `Category::category1cMappings(): BelongsToMany` (pivot `category_1c_category`); новый магазин при сетевом режиме автоматически получает все витрины сетевого маппинга.

**Контекст для исполнителя:** `Category::category1cMappings()` сейчас — `hasMany(Category1c::class, 'showcase_category_id')`, но колонка `showcase_category_id` удалена из `categories_1c` миграцией `2026_05_14_000003`. Relation мёртвая (нигде не используется), запрос по ней упал бы с SQL-ошибкой. Чиним на pivot — она нужна ShopObserver'у.

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Unit/Observers/ShopObserverNetworkTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Jobs\Category1c\PropagateShowcaseCategoriesToShopsJob;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class ShopObserverNetworkTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    private function makeNetworkMapping(Shop $anchor): Category
    {
        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);
        $cat1c = Category1c::factory()->create(['shop_id' => $anchor->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        return $showcase;
    }

    public function test_category1c_mappings_relation_works_on_pivot(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $showcase = $this->makeNetworkMapping($anchor);

        $this->assertTrue(
            Category::whereHas('category1cMappings')->pluck('id')->contains($showcase->id)
        );
    }

    public function test_new_shop_triggers_propagation_in_network_mode(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $showcase = $this->makeNetworkMapping($anchor);
        $this->enableCategory1cNetworkMode($anchor->id);

        Queue::fake();
        $newShop = $this->makeShop('new-' . uniqid());

        Queue::assertPushed(
            PropagateShowcaseCategoriesToShopsJob::class,
            fn ($job) => $job->onlyShopId === $newShop->id
                && in_array($showcase->id, $job->showcaseCategoryIds, true)
        );
    }

    public function test_new_shop_does_not_dispatch_when_network_mode_off(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $this->makeNetworkMapping($anchor);
        $this->disableCategory1cNetworkMode();

        Queue::fake();
        $this->makeShop('new-' . uniqid());

        Queue::assertNotPushed(PropagateShowcaseCategoriesToShopsJob::class);
    }

    public function test_new_shop_without_mappings_does_not_dispatch(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        Queue::fake();
        $this->makeShop('new-' . uniqid());

        Queue::assertNotPushed(PropagateShowcaseCategoriesToShopsJob::class);
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

```bash
vendor/bin/phpunit --filter=ShopObserverNetworkTest
```
Expected: FAIL — первый тест падает SQL-ошибкой «Unknown column showcase_category_id», остальные — job не пушится.

- [ ] **Step 3: Реализация**

3a. В `app/Models/Category.php` заменить

```php
  public function category1cMappings(): HasMany
  {
    return $this->hasMany(Category1c::class, 'showcase_category_id');
  }
```

на

```php
  public function category1cMappings(): BelongsToMany
  {
    return $this->belongsToMany(
      Category1c::class,
      'category_1c_category',
      'category_id',
      'categories_1c_id'
    );
  }
```

(импорт `BelongsToMany` в файле уже есть — используется существующими relations; `HasMany` не удалять, если используется другими методами файла.)

3b. Создать `app/Observers/ShopObserver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\Category1c\PropagateShowcaseCategoriesToShopsJob;
use App\Models\Category;
use App\Models\Shop;
use App\Services\ModuleConfigService;

/**
 * Сетевой режим маппинга категорий 1С: новый (или восстановленный) магазин
 * автоматически получает все витринные категории, участвующие в сетевом
 * маппинге. Магазины, созданные в обход Eloquent-событий (bulk insert,
 * consumer с withoutEvents), подхватятся ближайшим Link (Propagate по всем).
 */
class ShopObserver
{
    public function created(Shop $shop): void
    {
        $this->propagateNetworkShowcase($shop);
    }

    public function restored(Shop $shop): void
    {
        $this->propagateNetworkShowcase($shop);
    }

    private function propagateNetworkShowcase(Shop $shop): void
    {
        if (ModuleConfigService::getCategory1cNetworkAnchorShopId() === null) {
            return;
        }

        $showcaseIds = Category::query()
            ->whereHas('category1cMappings')
            ->pluck('id')
            ->all();

        if ($showcaseIds === []) {
            return;
        }

        PropagateShowcaseCategoriesToShopsJob::dispatch($showcaseIds, $shop->id);
    }
}
```

3c. В `app/Providers/AppServiceProvider.php` добавить импорт `use App\Observers\ShopObserver;` (модель Shop уже импортирована или добавить `use App\Models\Shop;`) и в `boot()` рядом с остальными:

```php
    Shop::observe(ShopObserver::class);
```

- [ ] **Step 4: Запустить — PASS**

```bash
vendor/bin/phpunit --filter=ShopObserverNetworkTest
```
Expected: OK (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Category.php app/Observers/ShopObserver.php app/Providers/AppServiceProvider.php tests/Unit/Observers/ShopObserverNetworkTest.php
git commit -m "feat: авто-распространение сетевых витрин на новые магазины + фикс мёртвой relation category1cMappings"
```

---

### Task 9: Команда category-1c:normalize-network-scope

**Files:**
- Create: `app/Console/Commands/NormalizeCategory1cNetworkScopeCommand.php`
- Test: `tests/Feature/NormalizeCategory1cNetworkScopeCommandTest.php`

**Interfaces:**
- Consumes: `ModuleConfigService::getCategory1cNetworkAnchorShopId()`.
- Produces: artisan `category-1c:normalize-network-scope [--dry-run]` — перенос/merge пер-магазинных categories_1c под якорь. Запускается однократно при включении режима на проекте с данными.

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Feature/NormalizeCategory1cNetworkScopeCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class NormalizeCategory1cNetworkScopeCommandTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    private function makeProduct(Shop $shop, int $cat1cId): Product
    {
        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Test product ' . uniqid();
        $product->category_1c_id = $cat1cId;
        $product->save();

        return $product;
    }

    public function test_fails_when_network_mode_off(): void
    {
        $this->disableCategory1cNetworkMode();

        $this->artisan('category-1c:normalize-network-scope')->assertFailed();
    }

    public function test_moves_foreign_rows_without_duplicate_to_anchor(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $foreign = Category1c::factory()->create([
            'shop_id' => $other->id,
            'external_id' => 'guid-norm-move',
        ]);

        $this->artisan('category-1c:normalize-network-scope')->assertSuccessful();

        $this->assertSame($anchor->id, $foreign->fresh()->shop_id);
    }

    public function test_merges_duplicate_into_anchor_row(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $anchorRow = Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-norm-merge',
        ]);
        $dupRow = Category1c::factory()->create([
            'shop_id' => $other->id,
            'external_id' => 'guid-norm-merge',
        ]);

        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);
        $dupRow->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $product = $this->makeProduct($other, $dupRow->id);

        $dupChild = Category1c::factory()->create([
            'shop_id' => $other->id,
            'external_id' => 'guid-norm-merge-child',
            'parent_id' => $dupRow->id,
        ]);

        $this->artisan('category-1c:normalize-network-scope')->assertSuccessful();

        $this->assertNull(Category1c::withTrashed()->find($dupRow->id));
        $this->assertSame($anchorRow->id, $product->fresh()->category_1c_id);
        $this->assertTrue(
            $anchorRow->fresh()->showcaseCategories->pluck('id')->contains($showcase->id)
        );
        $this->assertSame($anchorRow->id, $dupChild->fresh()->parent_id);
        $this->assertSame($anchor->id, $dupChild->fresh()->shop_id);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $foreign = Category1c::factory()->create([
            'shop_id' => $other->id,
            'external_id' => 'guid-norm-dry',
        ]);

        $this->artisan('category-1c:normalize-network-scope --dry-run')->assertSuccessful();

        $this->assertSame($other->id, $foreign->fresh()->shop_id);
    }

    public function test_resolves_parent_links_inside_anchor_after_move(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $parentAnchor = Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-norm-parent',
        ]);
        $childForeign = Category1c::factory()->create([
            'shop_id' => $other->id,
            'external_id' => 'guid-norm-child',
            'parent_id' => null,
            'parent_external_id' => 'guid-norm-parent',
        ]);

        $this->artisan('category-1c:normalize-network-scope')->assertSuccessful();

        $this->assertSame($parentAnchor->id, $childForeign->fresh()->parent_id);
    }
}
```

- [ ] **Step 2: Запустить — FAIL**

```bash
vendor/bin/phpunit --filter=NormalizeCategory1cNetworkScopeCommandTest
```
Expected: FAIL — команда не существует.

- [ ] **Step 3: Реализация**

Создать `app/Console/Commands/NormalizeCategory1cNetworkScopeCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category1c;
use App\Models\Shop;
use App\Services\ModuleConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Однократная нормализация пер-магазинных категорий 1С к якорному магазину
 * при включении сетевого режима маппинга.
 *
 * Для каждой строки categories_1c с shop_id != якоря:
 *  - дубликата (по external_id) под якорем нет → строка переносится (UPDATE shop_id);
 *  - дубликат есть → merge: pivot-привязки переносятся аддитивно, товары и дети
 *    перевешиваются на якорную строку, её поля (linked_at, quantum_*) не затираются,
 *    строка-дубликат удаляется физически вместе со своей метадатой.
 *
 * Все записи — DB::table (без Eloquent-событий: наружу ничего не публикуем).
 * Финальный проход дорезолвливает parent_id по parent_external_id внутри якоря.
 */
class NormalizeCategory1cNetworkScopeCommand extends Command
{
    protected $signature = 'category-1c:normalize-network-scope {--dry-run : Только отчёт, без изменений}';

    protected $description = 'Перенести пер-магазинные категории 1С под якорный магазин сетевого режима';

    public function handle(): int
    {
        $anchorShopId = ModuleConfigService::getCategory1cNetworkAnchorShopId();

        if ($anchorShopId === null) {
            $this->error('Сетевой режим выключен или anchor_shop_id не задан (project_modules.category_1c_network_mapping).');

            return self::FAILURE;
        }

        if (Shop::withTrashed()->find($anchorShopId) === null) {
            $this->error("Якорный магазин #{$anchorShopId} не найден.");

            return self::FAILURE;
        }

        $apply = !$this->option('dry-run');
        $moved = 0;
        $merged = 0;

        Category1c::withTrashed()
            ->where('shop_id', '!=', $anchorShopId)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($anchorShopId, $apply, &$moved, &$merged) {
                foreach ($rows as $row) {
                    $anchorRow = Category1c::withTrashed()
                        ->where('shop_id', $anchorShopId)
                        ->where('external_id', $row->external_id)
                        ->first();

                    if ($anchorRow === null) {
                        $moved++;
                        if ($apply) {
                            DB::table('categories_1c')->where('id', $row->id)->update([
                                'shop_id' => $anchorShopId,
                                'updated_at' => now(),
                            ]);
                        }
                        continue;
                    }

                    $merged++;
                    if ($apply) {
                        $this->mergeIntoAnchor($row, $anchorRow);
                    }
                }
            });

        if ($apply) {
            $this->resolveParentsInsideAnchor($anchorShopId);
        }

        $mode = $apply ? 'применено' : 'dry-run, БЕЗ изменений';
        $this->info("Готово ({$mode}): перенесено {$moved}, слито дубликатов {$merged}.");

        return self::SUCCESS;
    }

    private function mergeIntoAnchor(Category1c $dup, Category1c $anchorRow): void
    {
        DB::transaction(function () use ($dup, $anchorRow) {
            // Pivot дубликата → якорной строке (аддитивно, позиции в хвост).
            $existing = $anchorRow->showcaseCategories()->pluck('categories.id')->all();
            $positions = $anchorRow->showcaseCategories()->pluck('category_1c_category.position')->all();
            $nextPosition = $positions === [] ? 0 : (max($positions) + 1);

            $dupShowcaseIds = DB::table('category_1c_category')
                ->where('categories_1c_id', $dup->id)
                ->orderBy('position')
                ->pluck('category_id')
                ->all();

            foreach ($dupShowcaseIds as $showcaseId) {
                if (in_array((int) $showcaseId, array_map('intval', $existing), true)) {
                    continue;
                }
                DB::table('category_1c_category')->insert([
                    'categories_1c_id' => $anchorRow->id,
                    'category_id' => $showcaseId,
                    'position' => $nextPosition++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('products')
                ->where('category_1c_id', $dup->id)
                ->update(['category_1c_id' => $anchorRow->id, 'updated_at' => now()]);

            DB::table('categories_1c')
                ->where('parent_id', $dup->id)
                ->update(['parent_id' => $anchorRow->id, 'updated_at' => now()]);

            DB::table('event_sync_metadata')
                ->where('entity_type', 'categories_1c')
                ->where('entity_id', $dup->id)
                ->delete();

            // Свои pivot-строки дубликата удалит FK cascade при delete.
            DB::table('categories_1c')->where('id', $dup->id)->delete();
        });
    }

    private function resolveParentsInsideAnchor(int $anchorShopId): void
    {
        Category1c::withTrashed()
            ->where('shop_id', $anchorShopId)
            ->whereNotNull('parent_external_id')
            ->where('parent_external_id', '!=', '')
            ->chunkById(500, function ($rows) use ($anchorShopId) {
                foreach ($rows as $row) {
                    $parent = Category1c::where('shop_id', $anchorShopId)
                        ->where('external_id', $row->parent_external_id)
                        ->first();

                    if ($parent !== null && $row->parent_id !== $parent->id && $parent->id !== $row->id) {
                        DB::table('categories_1c')
                            ->where('id', $row->id)
                            ->update(['parent_id' => $parent->id, 'updated_at' => now()]);
                    }
                }
            });
    }
}
```

- [ ] **Step 4: Запустить — PASS**

```bash
vendor/bin/phpunit --filter=NormalizeCategory1cNetworkScopeCommandTest
```
Expected: OK (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/NormalizeCategory1cNetworkScopeCommand.php tests/Feature/NormalizeCategory1cNetworkScopeCommandTest.php
git commit -m "feat: команда нормализации категорий 1С к якорному магазину"
```

---

### Task 10: admin_front — модуль + экран без селектора магазина

**Files:**
- Modify: `mendev_admin_front/src/stores/moduleConfig.js` (MODULE_DEFAULTS)
- Modify: `mendev_admin_front/src/views/category-1c-management/index.vue`

**Interfaces:**
- Consumes: `useModuleConfigStore().config` (project_modules JSON, грузится глобально), ключ `category_1c_network_mapping.anchor_shop_id`.
- Produces: при включённом модуле экран работает от якорного магазина без селектора («Вся сеть»).

Тестовой инфраструктуры во фронте нет (vitest не заведён) — проверка ручная (`npm run build` + smoke на dev после деплоя).

- [ ] **Step 1: MODULE_DEFAULTS**

В `src/stores/moduleConfig.js` после блока `category_1c_integration` добавить:

```js
  category_1c_network_mapping: {
    label: "Сетевой маппинг категорий 1С",
    enabled: false,
    description:
      "Единый справочник и маппинг категорий 1С для всей сети магазинов (якорный магазин задаётся ключом anchor_shop_id).",
  },
```

- [ ] **Step 2: index.vue — computed и mounted**

В `src/views/category-1c-management/index.vue`:

2a. Добавить импорт store:

```js
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
```

2b. В `computed` добавить:

```js
    networkAnchorShopId() {
      const mod = useModuleConfigStore().config?.category_1c_network_mapping;
      if (!mod?.enabled) return null;
      const anchor = parseInt(mod.anchor_shop_id);
      return Number.isFinite(anchor) && anchor > 0 ? anchor : null;
    },
    isNetworkMode() {
      return this.networkAnchorShopId !== null;
    },
```

2c. `mounted()` заменить на:

```js
  mounted() {
    if (this.isNetworkMode) {
      this.selectedShopId = this.networkAnchorShopId;
    } else if (this.isAdmin) {
      this.fetchShops();
    } else if (this.userShopId) {
      this.selectedShopId = parseInt(this.userShopId);
    }
  },
```

2d. В template заменить блок селектора

```html
        <div v-if="isAdmin" class="category-1c-management__shop-select">
          <label>Магазин:</label>
          <select v-model="selectedShopId" @change="onShopChange">
            <option :value="null" disabled>Выберите магазин</option>
            <option v-for="shop in shops" :key="shop.id" :value="shop.id">
              {{ shop.title }}
            </option>
          </select>
        </div>
```

на

```html
        <div v-if="isNetworkMode" class="category-1c-management__shop-select">
          <label>Магазин:</label>
          <span>Вся сеть</span>
        </div>
        <div v-else-if="isAdmin" class="category-1c-management__shop-select">
          <label>Магазин:</label>
          <select v-model="selectedShopId" @change="onShopChange">
            <option :value="null" disabled>Выберите магазин</option>
            <option v-for="shop in shops" :key="shop.id" :value="shop.id">
              {{ shop.title }}
            </option>
          </select>
        </div>
```

- [ ] **Step 3: Проверка сборки**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_admin_front
npm run build
```
Expected: сборка без ошибок.

- [ ] **Step 4: Commit (admin_front)**

```bash
git add src/stores/moduleConfig.js src/views/category-1c-management/index.vue
git commit -m "feat: сетевой режим экрана Категории 1С (без селектора магазина, якорь из project_modules)"
```

---

### Task 11: Контракт 1С + примечание в спеке

**Files:**
- Modify: `/Users/daibelbuzarov/PhpstormProjects/mendev/docs/specs/2026-06-24-1c-rabbitmq-direct-contract.md` (раздел 1)
- Modify: `mendev_admin_back/docs/superpowers/specs/2026-07-23-category-1c-network-mapping-design.md` (§7: vitest-строку заменить на ручную проверку фронта)

- [ ] **Step 1: Дополнить раздел 1 контракта**

В `docs/specs/2026-06-24-1c-rabbitmq-direct-contract.md` после абзаца «Категории едут **отдельным справочником**…» (раздел 1) добавить:

```markdown
> **Сетевой режим (Разгуляйка).** Классификатор в центральной 1С один на все
> магазины, поэтому справочник категорий публикуется **один раз для всей сети** —
> НЕ по разу на каждый магазин. `shop_code` в конверте категорий — фиксированное
> значение: код якорного магазина (на dev — `mendev`); поле остаётся обязательным.
> Если справочник всё же опубликовать с разными `shop_code`, платформа склеит
> события в один справочник (дедуп по GUID), но это лишний трафик — так делать
> не нужно. Товары (`product.*`, включая батчи) публикуются по-прежнему
> пер-магазинно, каждая позиция несёт `category_1c_external_id` из единого
> справочника.
```

- [ ] **Step 2: Поправить §7 спеки**

В таблице тестов спеки строку `| admin_front (vitest) | ... |` заменить на:

```markdown
| admin_front | Тестовой инфраструктуры нет (vitest не заведён) — `npm run build` + ручная проверка экрана на dev: селектор скрыт, «Вся сеть», обе колонки работают от якоря. |
```

- [ ] **Step 3: Commit (admin_back — спека уже в этом репо)**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_admin_back
git add docs/superpowers/specs/2026-07-23-category-1c-network-mapping-design.md docs/superpowers/plans/2026-07-23-category-1c-network-mapping.md
git commit -m "docs: спека и план сетевого маппинга категорий 1С"
```

(Контракт-док в корне mendev вне git-репо — просто сохранить файл.)

---

## Пост-план (вне задач, по отдельной команде пользователя)

Деплой и включение — по спеке §8: merge `--no-ff` + push по команде → CI `last_pipeline --wait` → SQL-включение модуля с `anchor_shop_id=1` → `cache:clear` → `supervisorctl restart mendev_rabbitmq:*` → `category-1c:normalize-network-scope --dry-run` → live-верификация (тестовое `category_1c.updated` с `shop_code='zia'` → одна запись под якорем; Link → Apply → товары обоих магазинов; экран без селектора).
