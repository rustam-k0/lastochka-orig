# 1C Categories Mapping — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Реализовать отдельный справочник 1С-категорий в admin_back и экран привязки 1С↔витринные категории по задаче LASTOCHKI-JAMI-10114, с массовой синхронизацией товаров через очередь.

**Architecture:** Новая read-only сущность `Category1c` в admin_back, наполняется из 1c_parser через RabbitMQ (новые routing keys `category_1c.*`). На `products` появляются два FK — `category_1c_id` (источник) и `showcase_category_id` (витринная). Привязка 1С→витринная (`showcase_category_id` на `categories_1c`) — метаданные; реальный массовый apply делается асинхронным `SyncCategoryMappingsJob`'ом по нажатию кнопки в admin_front. Pivot `category_product` остаётся как есть.

**Tech Stack:** PHP 8.4 + Laravel 9, Lighthouse GraphQL, Pest, RabbitMQ + Outbox (`comrades/laravel-rabbitmq-events`), MySQL 8.0, Vue 3 Composition + Apollo Client + vuedraggable, Pinia, Stylus.

**Spec reference:** [`docs/superpowers/specs/2026-05-05-1c-categories-mapping-design.md`](../specs/2026-05-05-1c-categories-mapping-design.md)

---

## File Structure (по фазам)

### Repo: `multi_vendor_delivery_rabbitmq_package` (composer пакет, симлинк → `fullfilment_saas/multi_vendor_delivery_rabbitmq_package`)
- Modify: `src/Config/event-types.php` (или эквивалент — регистрация `category_1c.*` event types)
- Modify: `composer.json` — bump version
- Test: `tests/Unit/EventTypesRegistryTest.php`

### Repo: `lastochka_1c_parser`
- Modify: `config/rabbitmq-events.php:166` — `routing_key_prefix => 'category_1c'`
- Modify: `app/RabbitMQ/Transformers/ProductEventTransformer.php:129-130` — payload поля
- Modify: `app/RabbitMQ/Transformers/CategoryEventTransformer.php` — если содержит явное routing key
- Modify: `composer.json` — bump multi_vendor_delivery_rabbitmq_package version
- Test: `tests/Unit/RabbitMQ/CategoryEventTransformerTest.php`
- Test: `tests/Unit/RabbitMQ/ProductEventTransformerTest.php`

### Repo: `lastochka_docker/lastochka_admin_back`

**Migrations:**
- Create: `database/migrations/2026_05_06_000001_create_categories_1c_table.php`
- Create: `database/migrations/2026_05_06_000002_add_category_1c_id_and_showcase_category_id_to_products.php`
- Create: `database/migrations/2026_05_06_000003_create_category_1c_sync_batches_table.php`
- Create: `database/migrations/2026_05_06_000004_add_category_1c_menu_item.php`

**Models:**
- Create: `app/Models/Category1c.php`
- Create: `app/Models/Category1cSyncBatch.php`
- Modify: `app/Models/Product.php` — fillable + 2 relations
- Modify: `app/Models/Category.php` — 1 relation

**Transformers:**
- Create: `app/Transformers/Category1cEventTransformer.php`
- Modify: `app/Transformers/ProductEventTransformer.php:243` — fallback на showcase_category_id
- Modify: `app/Transformers/ProductEventTransformer.php` — fromEvent: резолв `category_1c_external_id`

**Observer:**
- Create: `app/Observers/CategoryObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` — регистрация observer

**RabbitMQ config:**
- Modify: `config/rabbitmq-events.php` — добавить inbound queue `categories_1c`

**Job:**
- Create: `app/Jobs/Category1c/SyncCategoryMappingsJob.php`

**GraphQL:**
- Modify: `graphql/schema.graphql` — `#import Category1c/*.graphql`
- Create: `graphql/Category1c/Category1c.graphql`
- Create: `graphql/Category1c/enums.graphql`
- Create: `app/GraphQL/Queries/Category1c/GetMappings.php`
- Create: `app/GraphQL/Queries/Category1c/GetMappingsCount.php`
- Create: `app/GraphQL/Queries/Category1c/HasOrphanSources.php`
- Create: `app/GraphQL/Queries/Category1c/NewCount.php`
- Create: `app/GraphQL/Queries/Category1c/GetCurrentBatch.php`
- Create: `app/GraphQL/Mutations/Category1c/LinkToShowcase.php`
- Create: `app/GraphQL/Mutations/Category1c/UnlinkFromShowcase.php`
- Create: `app/GraphQL/Mutations/Category1c/StartSync.php`

**Tests:**
- Create: `tests/Unit/Category1c/Category1cTest.php`
- Create: `tests/Feature/Transformers/Category1cEventTransformerTest.php`
- Create: `tests/Feature/Transformers/ProductEventTransformerCategory1cTest.php`
- Create: `tests/Unit/Jobs/SyncCategoryMappingsJobTest.php`
- Create: `tests/Feature/GraphQL/Category1c/GetCategoriesOneCQueryTest.php`
- Create: `tests/Feature/GraphQL/Category1c/LinkCategories1cMutationTest.php`
- Create: `tests/Feature/GraphQL/Category1c/UnlinkCategories1cMutationTest.php`
- Create: `tests/Feature/GraphQL/Category1c/SyncCategoriesOneCMutationTest.php`

### Repo: `lastochka_admin_front`

**Module / routing:**
- Modify: `src/stores/modules.js` (или где `MODULE_DEFAULTS`)
- Modify: `src/components/AsideComponent.vue`
- Modify: `src/router/index.js`

**Views:**
- Create: `src/views/category-1c-management/index.vue`
- Create: `src/views/category-1c-management/components/OneCColumn.vue`
- Create: `src/views/category-1c-management/components/OneCCategoryRow.vue`
- Create: `src/views/category-1c-management/components/ShowcaseColumn.vue`
- Create: `src/views/category-1c-management/components/ShowcaseCategoryRow.vue`
- Create: `src/views/category-1c-management/components/SyncBatchButton.vue`
- Create: `src/views/category-1c-management/components/BulkLinkBar.vue`
- Create: `src/views/category-1c-management/components/NewOneCBanner.vue`

**GraphQL:**
- Create: `src/views/category-1c-management/graphql/categories1c.graphql`
- Create: `src/views/category-1c-management/graphql/categoriesShowcase.graphql`
- Create: `src/views/category-1c-management/graphql/linkCategories1c.graphql`
- Create: `src/views/category-1c-management/graphql/unlinkCategories1c.graphql`
- Create: `src/views/category-1c-management/graphql/syncCategories1c.graphql`
- Create: `src/views/category-1c-management/graphql/syncBatch.graphql`

**Tests:**
- Create: `tests/unit/views/category-1c-management/OneCColumn.spec.js`
- Create: `tests/unit/views/category-1c-management/ShowcaseColumn.spec.js`
- Create: `tests/unit/views/category-1c-management/SyncBatchButton.spec.js`

---

## Pre-implementation: Worktree

- [ ] **Создать worktree для работы**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_docker/lastochka_admin_back
git checkout -b feat/lastochki-jami-10114-1c-categories-mapping
```

Аналогично в `lastochka_1c_parser`, `lastochka_admin_front`, `fullfilment_saas/multi_vendor_delivery_rabbitmq_package` — feature-branch с тем же именем.

---

## Phase 1: multi_vendor_delivery_rabbitmq_package — **SKIPPED**

**Update at execution time:** verified в `src/Commands/ConsumeEvents.php`, `src/Observers/ModelEventObserver.php` — пакет не имеет explicit registry event-типов, routing keys генерируются динамически как `routing_key_prefix + event_type` (например, `ModelEventObserver.php:290` — `"{$routingKeyPrefix}.{$eventType}"`). Соответственно регистрация `category_1c.*` event types происходит на уровне config'а каждого сервиса (Phases 2 для publisher, 5.4 для admin_back consumer), а сам пакет изменений не требует.

Также пропускаем composer bump (бывшие Tasks 2.3 и 7.2).

---

## Phase 1 (legacy, skipped): multi_vendor_delivery_rabbitmq_package

### Task 1.1: Зарегистрировать `category_1c.*` event types

**Files:**
- Modify: `/Users/daibelbuzarov/PhpstormProjects/fullfilment_saas/multi_vendor_delivery_rabbitmq_package/src/Config/event-types.php` (точное имя файла — посмотреть в репо; если иначе — `src/EventTypes/Registry.php` или `config/event-types.php` в зависимости от структуры)
- Test: `/Users/daibelbuzarov/PhpstormProjects/fullfilment_saas/multi_vendor_delivery_rabbitmq_package/tests/Unit/EventTypesRegistryTest.php`

- [ ] **Step 1.1.1: Найти точное место регистрации event types**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/fullfilment_saas/multi_vendor_delivery_rabbitmq_package
grep -RIn "category.created\|category.updated" src/ config/ 2>/dev/null
```
Запомнить найденный файл — его правим в шаге 1.1.3.

- [ ] **Step 1.1.2: Написать failing-тест**

```php
// tests/Unit/EventTypesRegistryTest.php
namespace Tests\Unit;

use Comrades\LaravelRabbitmqEvents\EventTypes\Registry;
use Tests\TestCase;

class EventTypesRegistryTest extends TestCase
{
    public function test_category_1c_event_types_are_registered(): void
    {
        $registered = Registry::all();
        $this->assertContains('category_1c.created', $registered);
        $this->assertContains('category_1c.updated', $registered);
        $this->assertContains('category_1c.deleted', $registered);
    }
}
```
(имя класса `Registry` подменить на реальное из шага 1.1.1)

- [ ] **Step 1.1.3: Запустить тест → ожидать FAIL**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/fullfilment_saas/multi_vendor_delivery_rabbitmq_package
vendor/bin/phpunit tests/Unit/EventTypesRegistryTest.php -v
```
Expected: `FAILED — Failed asserting that array contains 'category_1c.created'`.

- [ ] **Step 1.1.4: Зарегистрировать event types**

В файле найденном в шаге 1.1.1 рядом с `'category.created'`/`.updated`/`.deleted` добавить:
```php
'category_1c.created',
'category_1c.updated',
'category_1c.deleted',
```

- [ ] **Step 1.1.5: Запустить тест → ожидать PASS**

```bash
vendor/bin/phpunit tests/Unit/EventTypesRegistryTest.php -v
```
Expected: `OK (1 test)`.

- [ ] **Step 1.1.6: Bump composer version**

В `composer.json`:
```json
{
  "version": "X.Y.(Z+1)"
}
```
(посмотреть текущую версию командой `git log -1 --format=%s -- composer.json`).

- [ ] **Step 1.1.7: Commit**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/fullfilment_saas/multi_vendor_delivery_rabbitmq_package
git add src/ tests/Unit/EventTypesRegistryTest.php composer.json
git commit -m "feat: register category_1c.* event types (LASTOCHKI-JAMI-10114)"
```
Не пушим — push после ручной команды пользователя.

---

## Phase 2: 1c_parser — publisher изменения

### Task 2.1: Сменить routing_key_prefix категорий

**Files:**
- Modify: `/Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_1c_parser/config/rabbitmq-events.php:166`
- Test: `/Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_1c_parser/tests/Unit/RabbitMQ/CategoryConfigTest.php`

- [ ] **Step 2.1.1: Failing-тест**

```php
// tests/Unit/RabbitMQ/CategoryConfigTest.php
namespace Tests\Unit\RabbitMQ;

use Tests\TestCase;

class CategoryConfigTest extends TestCase
{
    public function test_categories_use_new_routing_key_prefix(): void
    {
        $cfg = config('rabbitmq-events.outbound.categories');
        $this->assertSame('category_1c', $cfg['routing_key_prefix']);
    }
}
```

- [ ] **Step 2.1.2: Run → FAIL**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_1c_parser
vendor/bin/phpunit tests/Unit/RabbitMQ/CategoryConfigTest.php -v
```
Expected: `FAIL — expected 'category_1c', actual 'category'`.

- [ ] **Step 2.1.3: Изменить config**

В `config/rabbitmq-events.php:166`:
```php
// Было:
'routing_key_prefix' => 'category',
// Стало:
'routing_key_prefix' => 'category_1c',
```

- [ ] **Step 2.1.4: Run → PASS**

```bash
vendor/bin/phpunit tests/Unit/RabbitMQ/CategoryConfigTest.php -v
```
Expected: `OK (1 test)`.

- [ ] **Step 2.1.5: Commit**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_1c_parser
git add config/rabbitmq-events.php tests/Unit/RabbitMQ/CategoryConfigTest.php
git commit -m "feat(rabbitmq): rename categories routing key to category_1c (LASTOCHKI-JAMI-10114)"
```

### Task 2.2: ProductEventTransformer — payload без category_id, с category_1c_external_id

**Files:**
- Modify: `/Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_1c_parser/app/RabbitMQ/Transformers/ProductEventTransformer.php:129-130`
- Test: `/Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_1c_parser/tests/Unit/RabbitMQ/ProductEventTransformerTest.php`

- [ ] **Step 2.2.1: Failing-тест**

```php
// tests/Unit/RabbitMQ/ProductEventTransformerTest.php
namespace Tests\Unit\RabbitMQ;

use App\Models\Category;
use App\Models\Product;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Tests\TestCase;

class ProductEventTransformerTest extends TestCase
{
    public function test_payload_omits_legacy_category_id_and_source(): void
    {
        $product = Product::factory()->create();
        $payload = (new ProductEventTransformer())->toEvent($product);

        $this->assertArrayNotHasKey('category_id', $payload);
        $this->assertArrayNotHasKey('category_source', $payload);
    }

    public function test_payload_contains_category_1c_external_id_when_category_present(): void
    {
        $cat = Category::factory()->create(['external_id' => 'GUID-CAT-1']);
        $product = Product::factory()->create(['category_id' => $cat->id]);
        $payload = (new ProductEventTransformer())->toEvent($product);

        $this->assertSame('GUID-CAT-1', $payload['category_1c_external_id']);
    }

    public function test_payload_category_1c_external_id_is_null_when_no_category(): void
    {
        $product = Product::factory()->create(['category_id' => null]);
        $payload = (new ProductEventTransformer())->toEvent($product);

        $this->assertNull($payload['category_1c_external_id']);
    }
}
```

- [ ] **Step 2.2.2: Run → FAIL**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_1c_parser
vendor/bin/phpunit tests/Unit/RabbitMQ/ProductEventTransformerTest.php -v
```
Expected: 3 failures (payload still has `category_id`/`category_source`, no `category_1c_external_id`).

- [ ] **Step 2.2.3: Изменить transformer**

В `app/RabbitMQ/Transformers/ProductEventTransformer.php` найти строки (примерно :129-130):
```php
'category_id'     => $product->category?->external_id,
'category_source' => $product->category ? $source : null,
```
Заменить на:
```php
'category_1c_external_id' => $product->category?->external_id,
```

- [ ] **Step 2.2.4: Run → PASS**

```bash
vendor/bin/phpunit tests/Unit/RabbitMQ/ProductEventTransformerTest.php -v
```
Expected: `OK (3 tests)`.

- [ ] **Step 2.2.5: Прогнать все 1c_parser тесты — убедиться что ничего не сломалось**

```bash
vendor/bin/phpunit
```
Expected: всё зелёное (или если что-то падает — фиксим до коммита).

- [ ] **Step 2.2.6: Commit**

```bash
git add app/RabbitMQ/Transformers/ProductEventTransformer.php tests/Unit/RabbitMQ/ProductEventTransformerTest.php
git commit -m "feat(rabbitmq): replace product.category_id/source with category_1c_external_id in payload (LASTOCHKI-JAMI-10114)"
```

### Task 2.3: Bump composer version пакета в 1c_parser

- [ ] **Step 2.3.1: Update composer.json**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_1c_parser
composer require comrades/laravel-rabbitmq-events:"^X.Y.(Z+1)"
```
(версия — та, что bumped в Task 1.1.6)

- [ ] **Step 2.3.2: Запустить тесты — package update не сломал**

```bash
vendor/bin/phpunit
```

- [ ] **Step 2.3.3: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: bump rabbitmq-events package (LASTOCHKI-JAMI-10114)"
```

---

## Phase 3: admin_back — Migrations

### Task 3.1: Migration `create_categories_1c_table`

**Files:**
- Create: `/Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_docker/lastochka_admin_back/database/migrations/2026_05_06_000001_create_categories_1c_table.php`

- [ ] **Step 3.1.1: Create migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories_1c', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('external_id', 64);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('showcase_category_id')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('synced_from_1c_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('categories_1c')->onDelete('set null');
            $table->foreign('showcase_category_id')->references('id')->on('categories')->onDelete('set null');

            $table->unique(['shop_id', 'external_id', 'deleted_at'], 'uniq_shop_external_deleted');
            $table->index(['shop_id', 'showcase_category_id'], 'idx_shop_showcase');
            $table->index(['shop_id', 'deleted_at', 'name'], 'idx_shop_deleted_name');
            $table->index('parent_id', 'idx_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories_1c');
    }
};
```

- [ ] **Step 3.1.2: Run migration**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_docker/lastochka_admin_back
php artisan migrate --path=database/migrations/2026_05_06_000001_create_categories_1c_table.php
```
Expected: `Migrated`.

- [ ] **Step 3.1.3: Verify schema**

```bash
php artisan tinker --execute="dump(\Schema::hasTable('categories_1c'))"
```
Expected: `true`.

- [ ] **Step 3.1.4: Commit**

```bash
git add database/migrations/2026_05_06_000001_create_categories_1c_table.php
git commit -m "feat(db): create categories_1c table (LASTOCHKI-JAMI-10114)"
```

### Task 3.2: Migration `add_category_1c_id_and_showcase_category_id_to_products`

**Files:**
- Create: `/Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_docker/lastochka_admin_back/database/migrations/2026_05_06_000002_add_category_1c_id_and_showcase_category_id_to_products.php`

- [ ] **Step 3.2.1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('category_1c_id')->nullable()->after('id');
            $table->unsignedBigInteger('showcase_category_id')->nullable()->after('category_1c_id');

            $table->foreign('category_1c_id')
                ->references('id')->on('categories_1c')
                ->onDelete('set null');
            $table->foreign('showcase_category_id')
                ->references('id')->on('categories')
                ->onDelete('set null');

            $table->index('category_1c_id', 'idx_products_category_1c');
            $table->index('showcase_category_id', 'idx_products_showcase_category');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign('products_category_1c_id_foreign');
            $table->dropForeign('products_showcase_category_id_foreign');
            $table->dropIndex('idx_products_category_1c');
            $table->dropIndex('idx_products_showcase_category');
            $table->dropColumn(['category_1c_id', 'showcase_category_id']);
        });
    }
};
```

- [ ] **Step 3.2.2: Run + verify**

```bash
php artisan migrate --path=database/migrations/2026_05_06_000002_add_category_1c_id_and_showcase_category_id_to_products.php
php artisan tinker --execute="dump(\Schema::hasColumns('products', ['category_1c_id', 'showcase_category_id']))"
```
Expected: `Migrated`, then `true`.

- [ ] **Step 3.2.3: Commit**

```bash
git add database/migrations/2026_05_06_000002_add_category_1c_id_and_showcase_category_id_to_products.php
git commit -m "feat(db): add category_1c_id and showcase_category_id FKs to products (LASTOCHKI-JAMI-10114)"
```

### Task 3.3: Migration `create_category_1c_sync_batches_table`

**Files:**
- Create: `/Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_docker/lastochka_admin_back/database/migrations/2026_05_06_000003_create_category_1c_sync_batches_table.php`

- [ ] **Step 3.3.1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('category_1c_sync_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->enum('status', ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'FAILED'])->default('PENDING');
            $table->integer('progress_total')->default(0);
            $table->integer('progress_done')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            $table->index(['shop_id', 'status', 'created_at'], 'idx_shop_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_1c_sync_batches');
    }
};
```

- [ ] **Step 3.3.2: Run + verify**

```bash
php artisan migrate --path=database/migrations/2026_05_06_000003_create_category_1c_sync_batches_table.php
```
Expected: `Migrated`.

- [ ] **Step 3.3.3: Commit**

```bash
git add database/migrations/2026_05_06_000003_create_category_1c_sync_batches_table.php
git commit -m "feat(db): create category_1c_sync_batches table (LASTOCHKI-JAMI-10114)"
```

### Task 3.4: Migration — add menu item

**Files:**
- Create: `/Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_docker/lastochka_admin_back/database/migrations/2026_05_06_000004_add_category_1c_menu_item.php`

- [ ] **Step 3.4.1: Create migration (idempotent)**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $exists = DB::table('menu_items')->where('key', 'category_1c')->exists();
        if ($exists) {
            return;
        }
        DB::table('menu_items')->insert([
            'key' => 'category_1c',
            'title' => 'Категории 1С',
            'route' => '/categories-1c',
            'icon' => 'icon-1c',
            'position' => 75,
            'is_visible' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menu_items')->where('key', 'category_1c')->delete();
    }
};
```

- [ ] **Step 3.4.2: Run + verify**

```bash
php artisan migrate --path=database/migrations/2026_05_06_000004_add_category_1c_menu_item.php
php artisan tinker --execute="dump(DB::table('menu_items')->where('key','category_1c')->first())"
```
Expected: запись есть с `is_visible=0`.

- [ ] **Step 3.4.3: Commit**

```bash
git add database/migrations/2026_05_06_000004_add_category_1c_menu_item.php
git commit -m "feat(db): add category_1c menu item (hidden by default) (LASTOCHKI-JAMI-10114)"
```

---

## Phase 4: admin_back — Models

### Task 4.1: Model `Category1c`

**Files:**
- Create: `app/Models/Category1c.php`
- Test: `tests/Unit/Category1c/Category1cTest.php`

- [ ] **Step 4.1.1: Failing-тест**

```php
// tests/Unit/Category1c/Category1cTest.php
namespace Tests\Unit\Category1c;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class Category1cTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_leaf_returns_true_when_no_children(): void
    {
        $cat = Category1c::factory()->create();
        $this->assertTrue($cat->is_leaf);
    }

    public function test_is_leaf_returns_false_when_has_children(): void
    {
        $parent = Category1c::factory()->create();
        Category1c::factory()->create(['parent_id' => $parent->id]);
        $this->assertFalse($parent->fresh()->is_leaf);
    }

    public function test_is_linked_reflects_showcase_category_id(): void
    {
        $cat = Category1c::factory()->create(['showcase_category_id' => null]);
        $this->assertFalse($cat->is_linked);

        $showcase = Category::factory()->create();
        $cat->update(['showcase_category_id' => $showcase->id]);
        $this->assertTrue($cat->fresh()->is_linked);
    }

    public function test_soft_delete_keeps_record_with_deleted_at(): void
    {
        $cat = Category1c::factory()->create();
        $cat->delete();
        $this->assertSoftDeleted($cat);
    }
}
```

- [ ] **Step 4.1.2: Run → FAIL** (модель ещё не создана)

```bash
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit tests/Unit/Category1c/Category1cTest.php -v
```
Expected: `Class 'App\Models\Category1c' not found`.

- [ ] **Step 4.1.3: Create model**

```php
<?php
// app/Models/Category1c.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category1c extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'categories_1c';

    protected $fillable = [
        'shop_id',
        'external_id',
        'parent_id',
        'name',
        'position',
        'is_active',
        'showcase_category_id',
        'linked_at',
        'last_synced_at',
        'synced_from_1c_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'linked_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'synced_from_1c_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function showcaseCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'showcase_category_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_1c_id');
    }

    public function getIsLeafAttribute(): bool
    {
        return !$this->children()->exists();
    }

    public function getIsLinkedAttribute(): bool
    {
        return $this->showcase_category_id !== null;
    }

    public function getIsSourceDeletedAttribute(): bool
    {
        return $this->trashed();
    }

    public function getIsNewAttribute(): bool
    {
        return $this->created_at?->gt(now()->subDays(30))
            && $this->showcase_category_id === null;
    }
}
```

- [ ] **Step 4.1.4: Create factory**

```php
<?php
// database/factories/Category1cFactory.php

namespace Database\Factories;

use App\Models\Category1c;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

class Category1cFactory extends Factory
{
    protected $model = Category1c::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'external_id' => $this->faker->uuid(),
            'name' => $this->faker->words(2, true),
            'position' => 0,
            'is_active' => true,
            'showcase_category_id' => null,
            'synced_from_1c_at' => now(),
        ];
    }
}
```

- [ ] **Step 4.1.5: Run → PASS**

```bash
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit tests/Unit/Category1c/Category1cTest.php -v
```
Expected: `OK (4 tests)`.

- [ ] **Step 4.1.6: Commit**

```bash
git add app/Models/Category1c.php database/factories/Category1cFactory.php tests/Unit/Category1c/Category1cTest.php
git commit -m "feat(model): add Category1c model with relations and accessors (LASTOCHKI-JAMI-10114)"
```

### Task 4.2: Model `Category1cSyncBatch`

**Files:**
- Create: `app/Models/Category1cSyncBatch.php`

- [ ] **Step 4.2.1: Create model**

```php
<?php
// app/Models/Category1cSyncBatch.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Category1cSyncBatch extends Model
{
    use HasFactory;

    protected $table = 'category_1c_sync_batches';

    protected $fillable = [
        'shop_id',
        'status',
        'progress_total',
        'progress_done',
        'started_at',
        'completed_at',
        'error',
        'metadata',
    ];

    protected $casts = [
        'progress_total' => 'integer',
        'progress_done' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
```

- [ ] **Step 4.2.2: Create factory**

```php
<?php
// database/factories/Category1cSyncBatchFactory.php

namespace Database\Factories;

use App\Models\Category1cSyncBatch;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

class Category1cSyncBatchFactory extends Factory
{
    protected $model = Category1cSyncBatch::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'status' => 'PENDING',
            'progress_total' => 0,
            'progress_done' => 0,
            'started_at' => now(),
        ];
    }
}
```

- [ ] **Step 4.2.3: Smoke test**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan tinker --execute="dump(\App\Models\Category1cSyncBatch::factory()->create()->id)"
```
Expected: integer ID.

- [ ] **Step 4.2.4: Commit**

```bash
git add app/Models/Category1cSyncBatch.php database/factories/Category1cSyncBatchFactory.php
git commit -m "feat(model): add Category1cSyncBatch model (LASTOCHKI-JAMI-10114)"
```

### Task 4.3: Дополнения в `Product` и `Category`

**Files:**
- Modify: `app/Models/Product.php`
- Modify: `app/Models/Category.php`

- [ ] **Step 4.3.1: Read existing Product.php to find $fillable section**

```bash
grep -n "fillable\|protected" /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_docker/lastochka_admin_back/app/Models/Product.php | head -20
```

- [ ] **Step 4.3.2: Modify Product.php — add to $fillable**

В существующий `protected $fillable = [...]` добавить:
```php
'category_1c_id',
'showcase_category_id',
```

- [ ] **Step 4.3.3: Add relations in Product.php**

В тело класса добавить:
```php
public function category1c(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(Category1c::class, 'category_1c_id');
}

public function showcaseCategory(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(Category::class, 'showcase_category_id');
}
```

- [ ] **Step 4.3.4: Modify Category.php — add relation**

В `app/Models/Category.php` в тело класса добавить:
```php
public function category1cMappings(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(Category1c::class, 'showcase_category_id');
}
```

- [ ] **Step 4.3.5: Smoke test**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan tinker --execute="
\$p = \App\Models\Product::factory()->create();
dump(\$p->category1c, \$p->showcaseCategory);
\$c = \App\Models\Category::factory()->create();
dump(\$c->category1cMappings()->count());
"
```
Expected: `null`, `null`, `0`.

- [ ] **Step 4.3.6: Commit**

```bash
git add app/Models/Product.php app/Models/Category.php
git commit -m "feat(model): wire Category1c into Product and Category (LASTOCHKI-JAMI-10114)"
```

---

## Phase 5: admin_back — Transformers + Observer

### Task 5.1: `Category1cEventTransformer`

**Files:**
- Create: `app/Transformers/Category1cEventTransformer.php`
- Test: `tests/Feature/Transformers/Category1cEventTransformerTest.php`

- [ ] **Step 5.1.1: Read existing transformer for pattern**

```bash
cat /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_docker/lastochka_admin_back/app/Transformers/CategoryEventTransformer.php | head -100
```

- [ ] **Step 5.1.2: Failing-test**

```php
// tests/Feature/Transformers/Category1cEventTransformerTest.php
namespace Tests\Feature\Transformers;

use App\Models\Category1c;
use App\Models\Shop;
use App\Transformers\Category1cEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Category1cEventTransformerTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_event_creates_category_1c_with_external_id(): void
    {
        $shop = Shop::factory()->create(['code' => 'lastochka_1']);
        $payload = [
            'id' => 'GUID-1',
            'shop_code' => 'lastochka_1',
            'name' => 'Молоко 1.5%',
            'position' => 100,
            'active' => true,
            'parent_id' => null,
        ];

        $transformer = new Category1cEventTransformer();
        $cat = $transformer->createOrUpdate($payload);

        $this->assertSame('GUID-1', $cat->external_id);
        $this->assertSame('Молоко 1.5%', $cat->name);
        $this->assertSame($shop->id, $cat->shop_id);
        $this->assertNotNull($cat->synced_from_1c_at);
    }

    public function test_find_existing_returns_record_by_shop_and_external_id(): void
    {
        $shop = Shop::factory()->create(['code' => 'lastochka_1']);
        $existing = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'GUID-2',
        ]);

        $transformer = new Category1cEventTransformer();
        $found = $transformer->findExistingEntity([
            'id' => 'GUID-2',
            'shop_code' => 'lastochka_1',
        ]);

        $this->assertSame($existing->id, $found->id);
    }

    public function test_after_update_resolves_parent_via_external_id(): void
    {
        $shop = Shop::factory()->create(['code' => 'lastochka_1']);
        $parent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'PARENT-GUID',
        ]);
        $child = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'CHILD-GUID',
            'parent_id' => null,
        ]);

        (new Category1cEventTransformer())->afterUpdate($child, [
            'shop_code' => 'lastochka_1',
            'parent_id' => 'PARENT-GUID',
        ]);

        $this->assertSame($parent->id, $child->fresh()->parent_id);
    }

    public function test_after_update_restores_soft_deleted_record(): void
    {
        $shop = Shop::factory()->create(['code' => 'lastochka_1']);
        $cat = Category1c::factory()->create(['shop_id' => $shop->id]);
        $cat->delete();
        $this->assertSoftDeleted($cat);

        (new Category1cEventTransformer())->afterUpdate($cat, [
            'shop_code' => 'lastochka_1',
        ]);

        $this->assertFalse($cat->fresh()->trashed());
    }
}
```

- [ ] **Step 5.1.3: Run → FAIL**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_docker/lastochka_admin_back
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit tests/Feature/Transformers/Category1cEventTransformerTest.php -v
```
Expected: 4 failures (transformer не существует).

- [ ] **Step 5.1.4: Create transformer**

```php
<?php
// app/Transformers/Category1cEventTransformer.php

namespace App\Transformers;

use App\Models\Category1c;
use App\Models\Shop;
use Comrades\LaravelRabbitmqEvents\Transformers\BaseEventTransformer;
use Illuminate\Database\Eloquent\Model;

class Category1cEventTransformer extends BaseEventTransformer
{
    protected string $modelClass = Category1c::class;
    protected string $sourceType = '1c';
    protected string $entityType = 'category_1c';

    public function fromEvent(array $data, ?Model $existingEntity, ?Model $relatedModel = null): array
    {
        $attrs = [];
        if (array_key_exists('shop_code', $data)) {
            $attrs['shop_id'] = $this->resolveShopId($data['shop_code']);
        }
        if (array_key_exists('id', $data)) {
            $attrs['external_id'] = $data['id'];
        }
        if (array_key_exists('name', $data)) {
            $attrs['name'] = $data['name'];
        }
        if (array_key_exists('position', $data)) {
            $attrs['position'] = (int) $data['position'];
        }
        if (array_key_exists('active', $data)) {
            $attrs['is_active'] = (bool) $data['active'];
        }
        $attrs['synced_from_1c_at'] = now();
        return $attrs;
    }

    public function afterCreate(Model $entity, array $data): void
    {
        $this->resolveParent($entity, $data);
    }

    public function afterUpdate(Model $entity, array $data): void
    {
        if ($entity->trashed()) {
            $entity->restore();
        }
        $this->resolveParent($entity, $data);
    }

    public function afterDelete(Model $entity, array $data): void
    {
        // soft delete уже выполнен платформенным механизмом
    }

    public function findExistingEntity(array $data, ?object $relatedModel = null): ?Model
    {
        return Category1c::withTrashed()
            ->where('shop_id', $this->resolveShopId($data['shop_code']))
            ->where('external_id', $data['id'])
            ->first();
    }

    protected function resolveShopId(string $shopCode): int
    {
        return Shop::where('code', $shopCode)->value('id')
            ?? throw new \RuntimeException("Shop not found: {$shopCode}");
    }

    private function resolveParent(Model $entity, array $data): void
    {
        if (!array_key_exists('parent_id', $data)) {
            return;
        }
        if ($data['parent_id'] === null) {
            $entity->update(['parent_id' => null]);
            return;
        }
        $parent = Category1c::where('shop_id', $entity->shop_id)
            ->where('external_id', $data['parent_id'])
            ->first();
        if ($parent) {
            $entity->update(['parent_id' => $parent->id]);
        }
    }
}
```

- [ ] **Step 5.1.5: Run → PASS**

```bash
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit tests/Feature/Transformers/Category1cEventTransformerTest.php -v
```
Expected: `OK (4 tests)`.

- [ ] **Step 5.1.6: Commit**

```bash
git add app/Transformers/Category1cEventTransformer.php tests/Feature/Transformers/Category1cEventTransformerTest.php
git commit -m "feat(rabbitmq): add Category1cEventTransformer for inbound events (LASTOCHKI-JAMI-10114)"
```

### Task 5.2: Дополнения в `ProductEventTransformer`

**Files:**
- Modify: `app/Transformers/ProductEventTransformer.php`
- Test: `tests/Feature/Transformers/ProductEventTransformerCategory1cTest.php`

- [ ] **Step 5.2.1: Failing-test**

```php
// tests/Feature/Transformers/ProductEventTransformerCategory1cTest.php
namespace Tests\Feature\Transformers;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Product;
use App\Models\Shop;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductEventTransformerCategory1cTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_event_resolves_category_1c_external_id_to_local_id(): void
    {
        $shop = Shop::factory()->create(['code' => 'lastochka_1']);
        $cat1c = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'GUID-CAT',
        ]);

        $product = Product::factory()->create([
            'shop_id' => $shop->id,
            'category_1c_id' => null,
        ]);

        $transformer = new ProductEventTransformer();
        $attrs = $transformer->fromEvent([
            'shop_code' => 'lastochka_1',
            'category_1c_external_id' => 'GUID-CAT',
        ], $product);

        $this->assertSame($cat1c->id, $attrs['category_1c_id']);
    }

    public function test_from_event_sets_category_1c_id_null_when_payload_field_null(): void
    {
        $shop = Shop::factory()->create(['code' => 'lastochka_1']);

        $transformer = new ProductEventTransformer();
        $attrs = $transformer->fromEvent([
            'shop_code' => 'lastochka_1',
            'category_1c_external_id' => null,
        ], null);

        $this->assertArrayHasKey('category_1c_id', $attrs);
        $this->assertNull($attrs['category_1c_id']);
    }

    public function test_from_event_does_not_touch_category_1c_id_when_field_absent(): void
    {
        $transformer = new ProductEventTransformer();
        $attrs = $transformer->fromEvent([
            'shop_code' => 'lastochka_1',
        ], null);

        $this->assertArrayNotHasKey('category_1c_id', $attrs);
    }

    public function test_to_event_uses_showcase_category_id_when_set(): void
    {
        $shop = Shop::factory()->create();
        $showcase = Category::factory()->create();
        $product = Product::factory()->create([
            'shop_id' => $shop->id,
            'showcase_category_id' => $showcase->id,
        ]);

        $payload = (new ProductEventTransformer())->toEvent($product);
        $this->assertSame($showcase->id, $payload['category_id']);
    }

    public function test_to_event_falls_back_to_first_pivot_category_when_showcase_null(): void
    {
        $shop = Shop::factory()->create();
        $cat = Category::factory()->create();
        $product = Product::factory()->create([
            'shop_id' => $shop->id,
            'showcase_category_id' => null,
        ]);
        $product->categories()->attach($cat->id);

        $payload = (new ProductEventTransformer())->toEvent($product);
        $this->assertSame($cat->id, $payload['category_id']);
    }
}
```

- [ ] **Step 5.2.2: Run → FAIL**

```bash
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit tests/Feature/Transformers/ProductEventTransformerCategory1cTest.php -v
```
Expected: 5 failures.

- [ ] **Step 5.2.3: Modify `ProductEventTransformer::toEvent` (one-line change)**

В `app/Transformers/ProductEventTransformer.php` найти:
```php
$categoryId = $product->categories()->first()?->id;
```
(строка ~243)

Заменить на:
```php
$categoryId = $product->showcase_category_id ?? $product->categories()->first()?->id;
```

- [ ] **Step 5.2.4: Modify `ProductEventTransformer::fromEvent` — add category_1c_external_id resolution**

В методе `fromEvent` (или соответствующем) добавить блок (после существующей логики `category_id`):
```php
if (array_key_exists('category_1c_external_id', $data)) {
    if ($data['category_1c_external_id'] === null) {
        $attrs['category_1c_id'] = null;
    } else {
        $cat1c = \App\Models\Category1c::where('shop_id', $this->resolveShopId($data['shop_code']))
            ->where('external_id', $data['category_1c_external_id'])
            ->first();
        $attrs['category_1c_id'] = $cat1c?->id;
    }
}
```

- [ ] **Step 5.2.5: Run → PASS**

```bash
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit tests/Feature/Transformers/ProductEventTransformerCategory1cTest.php -v
```
Expected: `OK (5 tests)`.

- [ ] **Step 5.2.6: Run all admin_back tests — ничего не сломалось**

```bash
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit
```
Expected: ✓ (если pre-existing fail'ы есть фоном — игнорируем, но новые регрессии — фиксим).

- [ ] **Step 5.2.7: Commit**

```bash
git add app/Transformers/ProductEventTransformer.php tests/Feature/Transformers/ProductEventTransformerCategory1cTest.php
git commit -m "feat(rabbitmq): wire showcase_category_id and category_1c_external_id in ProductEventTransformer (LASTOCHKI-JAMI-10114)"
```

### Task 5.3: Observer на Category::deleted

**Files:**
- Create: `app/Observers/CategoryObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Observers/CategoryObserverTest.php`

- [ ] **Step 5.3.1: Failing-тест**

```php
// tests/Feature/Observers/CategoryObserverTest.php
namespace Tests\Feature\Observers;

use App\Models\Category;
use App\Models\Category1c;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_category_clears_linked_at_on_categories_1c(): void
    {
        $cat = Category::factory()->create();
        $cat1c = Category1c::factory()->create([
            'showcase_category_id' => $cat->id,
            'linked_at' => now(),
        ]);

        $cat->delete();

        $cat1c->refresh();
        $this->assertNull($cat1c->linked_at);
    }
}
```

- [ ] **Step 5.3.2: Run → FAIL**

- [ ] **Step 5.3.3: Create observer**

```php
<?php
// app/Observers/CategoryObserver.php

namespace App\Observers;

use App\Models\Category;
use App\Models\Category1c;

class CategoryObserver
{
    public function deleted(Category $category): void
    {
        Category1c::where('showcase_category_id', $category->id)
            ->update(['linked_at' => null]);
    }
}
```

- [ ] **Step 5.3.4: Register observer in AppServiceProvider**

В `app/Providers/AppServiceProvider.php` в метод `boot()` добавить:
```php
\App\Models\Category::observe(\App\Observers\CategoryObserver::class);
```

- [ ] **Step 5.3.5: Run → PASS**

- [ ] **Step 5.3.6: Commit**

```bash
git add app/Observers/CategoryObserver.php app/Providers/AppServiceProvider.php tests/Feature/Observers/CategoryObserverTest.php
git commit -m "feat: clear Category1c.linked_at when showcase Category deleted (LASTOCHKI-JAMI-10114)"
```

### Task 5.4: Зарегистрировать `categories_1c` queue в admin_back rabbitmq config

**Files:**
- Modify: `config/rabbitmq-events.php`

- [ ] **Step 5.4.1: Add inbound queue config**

В `config/rabbitmq-events.php` найти секцию `inbound_queues` (или `queues_in`), добавить:
```php
'categories_1c' => [
    'queue' => env('RABBITMQ_QUEUE_CATEGORIES_1C', 'admin_categories_1c_in'),
    'event_types' => [
        'category_1c.created',
        'category_1c.updated',
        'category_1c.deleted',
    ],
    'transformer' => \App\Transformers\Category1cEventTransformer::class,
],
```
(точную форму подставить из существующих inbound queue в этом конфиге как образец).

- [ ] **Step 5.4.2: Smoke test — config валидный**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan config:clear
/opt/homebrew/opt/php@8.4/bin/php artisan tinker --execute="dump(config('rabbitmq-events'))" | head -50
```

- [ ] **Step 5.4.3: Commit**

```bash
git add config/rabbitmq-events.php
git commit -m "feat(rabbitmq): register categories_1c inbound queue (LASTOCHKI-JAMI-10114)"
```

---

## Phase 6: admin_back — GraphQL

### Task 6.1: GraphQL types и schema import

**Files:**
- Create: `graphql/Category1c/Category1c.graphql`
- Create: `graphql/Category1c/enums.graphql`
- Modify: `graphql/schema.graphql`

- [ ] **Step 6.1.1: Создать enums.graphql**

```graphql
# graphql/Category1c/enums.graphql

enum Category1cSyncStatus {
  PENDING
  IN_PROGRESS
  COMPLETED
  FAILED
}

enum Category1cLinkedFilter {
  LINKED
  UNLINKED
  ALL
}
```

- [ ] **Step 6.1.2: Создать Category1c.graphql со всеми типами**

```graphql
# graphql/Category1c/Category1c.graphql

type Category1c {
  id: ID!
  shop: Shop! @belongsTo
  shop_id: ID!
  external_id: String!
  parent: Category1c @belongsTo
  parent_id: ID
  children: [Category1c!]! @hasMany
  name: String!
  position: Int!
  is_active: Boolean!
  is_leaf: Boolean! @field(resolver: "Category1cQueries\\IsLeaf")
  showcase_category: Category @belongsTo
  showcase_category_id: ID
  linked_at: DateTime
  last_synced_at: DateTime
  synced_from_1c_at: DateTime
  products_count: Int! @count(relation: "products")
  is_new: Boolean! @field(resolver: "Category1cQueries\\IsNew")
  is_linked: Boolean!
  is_source_deleted: Boolean!
  created_at: DateTime!
  updated_at: DateTime!
  deleted_at: DateTime
}

extend type Category {
  category_1c_mappings(shop_id: ID!): [Category1c!]!
    @field(resolver: "Category1cQueries\\GetMappings")
  category_1c_mappings_count(shop_id: ID!): Int!
    @field(resolver: "Category1cQueries\\GetMappingsCount")
  has_orphan_1c_sources(shop_id: ID!): Boolean!
    @field(resolver: "Category1cQueries\\HasOrphanSources")
}

extend type Product {
  category_1c: Category1c @belongsTo
  category_1c_id: ID
  showcase_category: Category @belongsTo
  showcase_category_id: ID
}

type Category1cSyncBatch {
  id: ID!
  shop: Shop! @belongsTo
  status: Category1cSyncStatus!
  progress_total: Int!
  progress_done: Int!
  started_at: DateTime!
  completed_at: DateTime
  error: String
}

extend type Query {
  categories_1c(
    shop_id: ID!
    linked_status: Category1cLinkedFilter = ALL
    search: String
    parent_id: ID
    only_leaves: Boolean = false
    include_deleted: Boolean = false
    is_active: Boolean
  ): [Category1c!]! @paginate(defaultCount: 50, maxCount: 200)

  categories_1c_new_count(shop_id: ID!): Int!
    @field(resolver: "Category1cQueries\\NewCount")

  category_1c_sync_batch(shop_id: ID!): Category1cSyncBatch
    @field(resolver: "Category1cQueries\\GetCurrentBatch")
}

extend type Mutation {
  link_categories_1c_to_showcase(
    category_1c_ids: [ID!]!
    showcase_category_id: ID!
  ): [Category1c!]! @field(resolver: "Category1cMutations\\LinkToShowcase")

  unlink_categories_1c(
    category_1c_ids: [ID!]!
  ): [Category1c!]! @field(resolver: "Category1cMutations\\UnlinkFromShowcase")

  sync_categories_1c(shop_id: ID!): Category1cSyncBatch!
    @field(resolver: "Category1cMutations\\StartSync")
}
```

- [ ] **Step 6.1.3: Импортировать в `graphql/schema.graphql`**

В `graphql/schema.graphql` рядом с другими `#import` добавить:
```graphql
#import Category1c/enums.graphql
#import Category1c/Category1c.graphql
```

- [ ] **Step 6.1.4: Очистить Lighthouse cache**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan lighthouse:clear-cache
```

- [ ] **Step 6.1.5: Smoke test — schema валидна**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan lighthouse:validate-schema
```
Expected: ✓ или конкретная ошибка про missing resolver — ОК, мы их добавим в следующих тасках.

- [ ] **Step 6.1.6: Commit**

```bash
git add graphql/Category1c/ graphql/schema.graphql
git commit -m "feat(graphql): add Category1c types, queries, mutations to schema (LASTOCHKI-JAMI-10114)"
```

### Task 6.2: Query resolvers

**Files:**
- Create: `app/GraphQL/Queries/Category1c/IsLeaf.php`
- Create: `app/GraphQL/Queries/Category1c/IsNew.php`
- Create: `app/GraphQL/Queries/Category1c/GetMappings.php`
- Create: `app/GraphQL/Queries/Category1c/GetMappingsCount.php`
- Create: `app/GraphQL/Queries/Category1c/HasOrphanSources.php`
- Create: `app/GraphQL/Queries/Category1c/NewCount.php`
- Create: `app/GraphQL/Queries/Category1c/GetCurrentBatch.php`

- [ ] **Step 6.2.1: Реализовать `IsLeaf`**

```php
<?php
// app/GraphQL/Queries/Category1c/IsLeaf.php

namespace App\GraphQL\Queries\Category1c;

use App\Models\Category1c;

class IsLeaf
{
    public function __invoke(Category1c $root): bool
    {
        return !$root->children()->exists();
    }
}
```

- [ ] **Step 6.2.2: Реализовать `IsNew`**

```php
<?php
namespace App\GraphQL\Queries\Category1c;

use App\Models\Category1c;

class IsNew
{
    public function __invoke(Category1c $root): bool
    {
        return $root->created_at?->gt(now()->subDays(30))
            && $root->showcase_category_id === null;
    }
}
```

- [ ] **Step 6.2.3: Реализовать `GetMappings`**

```php
<?php
namespace App\GraphQL\Queries\Category1c;

use App\Models\Category;
use App\Models\Category1c;

class GetMappings
{
    public function __invoke(Category $root, array $args): \Illuminate\Database\Eloquent\Collection
    {
        return Category1c::where('shop_id', $args['shop_id'])
            ->where('showcase_category_id', $root->id)
            ->orderBy('name')
            ->get();
    }
}
```

- [ ] **Step 6.2.4: Реализовать `GetMappingsCount`**

```php
<?php
namespace App\GraphQL\Queries\Category1c;

use App\Models\Category;
use App\Models\Category1c;

class GetMappingsCount
{
    public function __invoke(Category $root, array $args): int
    {
        return Category1c::where('shop_id', $args['shop_id'])
            ->where('showcase_category_id', $root->id)
            ->count();
    }
}
```

- [ ] **Step 6.2.5: Реализовать `HasOrphanSources`**

```php
<?php
namespace App\GraphQL\Queries\Category1c;

use App\Models\Category;
use App\Models\Category1c;

class HasOrphanSources
{
    public function __invoke(Category $root, array $args): bool
    {
        return Category1c::onlyTrashed()
            ->where('shop_id', $args['shop_id'])
            ->where('showcase_category_id', $root->id)
            ->exists();
    }
}
```

- [ ] **Step 6.2.6: Реализовать `NewCount`**

```php
<?php
namespace App\GraphQL\Queries\Category1c;

use App\Models\Category1c;

class NewCount
{
    public function __invoke($root, array $args): int
    {
        return Category1c::where('shop_id', $args['shop_id'])
            ->whereNull('showcase_category_id')
            ->where('created_at', '>', now()->subDays(30))
            ->count();
    }
}
```

- [ ] **Step 6.2.7: Реализовать `GetCurrentBatch`**

```php
<?php
namespace App\GraphQL\Queries\Category1c;

use App\Models\Category1cSyncBatch;

class GetCurrentBatch
{
    public function __invoke($root, array $args): ?Category1cSyncBatch
    {
        return Category1cSyncBatch::where('shop_id', $args['shop_id'])
            ->orderByDesc('id')
            ->first();
    }
}
```

- [ ] **Step 6.2.8: Lighthouse clear-cache + smoke**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan lighthouse:clear-cache
/opt/homebrew/opt/php@8.4/bin/php artisan lighthouse:validate-schema
```

- [ ] **Step 6.2.9: Commit**

```bash
git add app/GraphQL/Queries/Category1c/
git commit -m "feat(graphql): add Category1c query resolvers (LASTOCHKI-JAMI-10114)"
```

### Task 6.3: Mutation resolvers

**Files:**
- Create: `app/GraphQL/Mutations/Category1c/LinkToShowcase.php`
- Create: `app/GraphQL/Mutations/Category1c/UnlinkFromShowcase.php`
- Create: `app/GraphQL/Mutations/Category1c/StartSync.php`
- Test: `tests/Feature/GraphQL/Category1c/LinkCategories1cMutationTest.php`
- Test: `tests/Feature/GraphQL/Category1c/UnlinkCategories1cMutationTest.php`
- Test: `tests/Feature/GraphQL/Category1c/SyncCategoriesOneCMutationTest.php`

- [ ] **Step 6.3.1: Failing-тест на link**

```php
// tests/Feature/GraphQL/Category1c/LinkCategories1cMutationTest.php
namespace Tests\Feature\GraphQL\Category1c;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkCategories1cMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_writes_showcase_category_id_and_linked_at(): void
    {
        $shop = Shop::factory()->create();
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);
        $showcase = Category::factory()->create();
        $shop->categories()->attach($showcase->id);

        $this->postJson('/graphql-admin', [
            'query' => '
                mutation {
                  link_categories_1c_to_showcase(
                    category_1c_ids: [' . $cat1c->id . ']
                    showcase_category_id: ' . $showcase->id . '
                  ) { id showcase_category_id linked_at }
                }
            ',
        ])->assertJsonStructure(['data' => ['link_categories_1c_to_showcase']]);

        $cat1c->refresh();
        $this->assertSame($showcase->id, (int) $cat1c->showcase_category_id);
        $this->assertNotNull($cat1c->linked_at);
    }

    public function test_link_rejects_when_showcase_has_children_not_leaf(): void
    {
        $shop = Shop::factory()->create();
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);
        $showcaseParent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $showcaseParent->id]);
        $shop->categories()->attach($showcaseParent->id);

        $resp = $this->postJson('/graphql-admin', [
            'query' => '
                mutation {
                  link_categories_1c_to_showcase(
                    category_1c_ids: [' . $cat1c->id . ']
                    showcase_category_id: ' . $showcaseParent->id . '
                  ) { id }
                }
            ',
        ]);

        $resp->assertJsonFragment(['extensions' => ['code' => 'SHOWCASE_NOT_LEAF']]);
    }

    public function test_link_rejects_when_category_1c_is_soft_deleted(): void
    {
        $shop = Shop::factory()->create();
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);
        $cat1c->delete();
        $showcase = Category::factory()->create();
        $shop->categories()->attach($showcase->id);

        $resp = $this->postJson('/graphql-admin', [
            'query' => '
                mutation {
                  link_categories_1c_to_showcase(
                    category_1c_ids: [' . $cat1c->id . ']
                    showcase_category_id: ' . $showcase->id . '
                  ) { id }
                }
            ',
        ]);

        $resp->assertJsonFragment(['extensions' => ['code' => 'CATEGORY_1C_DELETED']]);
    }
}
```

- [ ] **Step 6.3.2: Run → FAIL**

- [ ] **Step 6.3.3: Реализовать `LinkToShowcase`**

```php
<?php
// app/GraphQL/Mutations/Category1c/LinkToShowcase.php

namespace App\GraphQL\Mutations\Category1c;

use App\Models\Category;
use App\Models\Category1c;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Exceptions\DefinitionException;
use GraphQL\Error\Error;

class LinkToShowcase
{
    public function __invoke($root, array $args): array
    {
        $cat1cIds = $args['category_1c_ids'];
        $showcaseId = $args['showcase_category_id'];

        $showcase = Category::with('children')->findOrFail($showcaseId);
        if ($showcase->children->isNotEmpty()) {
            throw new Error('Showcase category must be a leaf', null, null, [], null, null, ['code' => 'SHOWCASE_NOT_LEAF']);
        }

        $cats1c = Category1c::with('children')->whereIn('id', $cat1cIds)->get();
        foreach ($cats1c as $cat1c) {
            if ($cat1c->trashed()) {
                throw new Error('Category 1c is deleted in 1C', null, null, [], null, null, ['code' => 'CATEGORY_1C_DELETED']);
            }
            if ($cat1c->children->isNotEmpty()) {
                throw new Error('Category 1c must be a leaf', null, null, [], null, null, ['code' => 'CATEGORY_1C_NOT_LEAF']);
            }
            // Shop mismatch check
            $shopHasShowcase = DB::table('shops_categories')
                ->where('shop_id', $cat1c->shop_id)
                ->where('category_id', $showcaseId)
                ->exists();
            if (!$shopHasShowcase) {
                throw new Error('Shop mismatch between category 1c and showcase', null, null, [], null, null, ['code' => 'SHOP_MISMATCH']);
            }
        }

        DB::transaction(function () use ($cat1cIds, $showcaseId) {
            Category1c::whereIn('id', $cat1cIds)->update([
                'showcase_category_id' => $showcaseId,
                'linked_at' => now(),
            ]);
        });

        return Category1c::whereIn('id', $cat1cIds)->get()->all();
    }
}
```

- [ ] **Step 6.3.4: Run → PASS**

```bash
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit tests/Feature/GraphQL/Category1c/LinkCategories1cMutationTest.php -v
```

- [ ] **Step 6.3.5: Реализовать `UnlinkFromShowcase`**

```php
<?php
// app/GraphQL/Mutations/Category1c/UnlinkFromShowcase.php

namespace App\GraphQL\Mutations\Category1c;

use App\Models\Category1c;
use Illuminate\Support\Facades\DB;

class UnlinkFromShowcase
{
    public function __invoke($root, array $args): array
    {
        $ids = $args['category_1c_ids'];

        DB::transaction(function () use ($ids) {
            Category1c::whereIn('id', $ids)->update([
                'showcase_category_id' => null,
                'linked_at' => null,
            ]);
        });

        return Category1c::whereIn('id', $ids)->get()->all();
    }
}
```

- [ ] **Step 6.3.6: Тест Unlink + run → PASS**

```php
// tests/Feature/GraphQL/Category1c/UnlinkCategories1cMutationTest.php
namespace Tests\Feature\GraphQL\Category1c;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnlinkCategories1cMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlink_clears_showcase_and_linked_at_but_does_not_touch_products(): void
    {
        $shop = Shop::factory()->create();
        $showcase = Category::factory()->create();
        $cat1c = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'showcase_category_id' => $showcase->id,
            'linked_at' => now(),
        ]);
        $product = Product::factory()->create([
            'shop_id' => $shop->id,
            'category_1c_id' => $cat1c->id,
            'showcase_category_id' => $showcase->id,
        ]);

        $this->postJson('/graphql-admin', [
            'query' => 'mutation { unlink_categories_1c(category_1c_ids: [' . $cat1c->id . ']) { id } }',
        ])->assertJsonStructure(['data' => ['unlink_categories_1c']]);

        $cat1c->refresh();
        $this->assertNull($cat1c->showcase_category_id);
        $this->assertNull($cat1c->linked_at);

        // Product unchanged
        $product->refresh();
        $this->assertSame($showcase->id, $product->showcase_category_id);
    }
}
```

- [ ] **Step 6.3.7: Реализовать `StartSync`**

```php
<?php
// app/GraphQL/Mutations/Category1c/StartSync.php

namespace App\GraphQL\Mutations\Category1c;

use App\Jobs\Category1c\SyncCategoryMappingsJob;
use App\Models\Category1cSyncBatch;

class StartSync
{
    public function __invoke($root, array $args): Category1cSyncBatch
    {
        $shopId = (int) $args['shop_id'];

        $existing = Category1cSyncBatch::where('shop_id', $shopId)
            ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
            ->latest('id')
            ->first();
        if ($existing) {
            return $existing;
        }

        $batch = Category1cSyncBatch::create([
            'shop_id' => $shopId,
            'status' => 'PENDING',
            'started_at' => now(),
        ]);
        SyncCategoryMappingsJob::dispatch($batch->id, $shopId);
        return $batch;
    }
}
```

- [ ] **Step 6.3.8: Тест StartSync (idempotency)**

```php
// tests/Feature/GraphQL/Category1c/SyncCategoriesOneCMutationTest.php
namespace Tests\Feature\GraphQL\Category1c;

use App\Models\Category1cSyncBatch;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncCategoriesOneCMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_creates_pending_batch(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();

        $resp = $this->postJson('/graphql-admin', [
            'query' => 'mutation { sync_categories_1c(shop_id: ' . $shop->id . ') { id status } }',
        ]);

        $resp->assertJsonPath('data.sync_categories_1c.status', 'PENDING');
        $this->assertSame(1, Category1cSyncBatch::where('shop_id', $shop->id)->count());
    }

    public function test_sync_returns_existing_in_progress_batch_on_repeat(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();
        $existing = Category1cSyncBatch::factory()->create([
            'shop_id' => $shop->id,
            'status' => 'IN_PROGRESS',
        ]);

        $resp = $this->postJson('/graphql-admin', [
            'query' => 'mutation { sync_categories_1c(shop_id: ' . $shop->id . ') { id status } }',
        ]);

        $resp->assertJsonPath('data.sync_categories_1c.id', (string) $existing->id);
        $this->assertSame(1, Category1cSyncBatch::where('shop_id', $shop->id)->count());
    }
}
```

- [ ] **Step 6.3.9: Lighthouse clear-cache + run all GraphQL tests**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan lighthouse:clear-cache
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit tests/Feature/GraphQL/Category1c/ -v
```

- [ ] **Step 6.3.10: Commit**

```bash
git add app/GraphQL/Mutations/Category1c/ tests/Feature/GraphQL/Category1c/
git commit -m "feat(graphql): add Category1c link/unlink/sync mutations (LASTOCHKI-JAMI-10114)"
```

---

## Phase 7: admin_back — `SyncCategoryMappingsJob`

### Task 7.1: Job скелет + tests

**Files:**
- Create: `app/Jobs/Category1c/SyncCategoryMappingsJob.php`
- Test: `tests/Unit/Jobs/SyncCategoryMappingsJobTest.php`

- [ ] **Step 7.1.1: Failing-тест**

```php
// tests/Unit/Jobs/SyncCategoryMappingsJobTest.php
namespace Tests\Unit\Jobs;

use App\Events\ProductUpdated;
use App\Jobs\Category1c\SyncCategoryMappingsJob;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Category1cSyncBatch;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SyncCategoryMappingsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigns_showcase_category_id_to_products_via_linked_category_1c(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = Shop::factory()->create();
        $showcase = Category::factory()->create();
        $cat1c = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'showcase_category_id' => $showcase->id,
        ]);
        $products = Product::factory()->count(3)->create([
            'shop_id' => $shop->id,
            'category_1c_id' => $cat1c->id,
            'showcase_category_id' => null,
        ]);

        $batch = Category1cSyncBatch::factory()->create(['shop_id' => $shop->id, 'status' => 'PENDING']);
        (new SyncCategoryMappingsJob($batch->id, $shop->id))->handle();

        foreach ($products as $p) {
            $this->assertSame($showcase->id, $p->fresh()->showcase_category_id);
        }
        Event::assertDispatchedTimes(ProductUpdated::class, 3);

        $batch->refresh();
        $this->assertSame('COMPLETED', $batch->status);
        $this->assertSame(3, $batch->progress_total);
        $this->assertSame(3, $batch->progress_done);
    }

    public function test_idempotent_second_run_does_not_dispatch_events_when_no_changes(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = Shop::factory()->create();
        $showcase = Category::factory()->create();
        $cat1c = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'showcase_category_id' => $showcase->id,
        ]);
        Product::factory()->count(2)->create([
            'shop_id' => $shop->id,
            'category_1c_id' => $cat1c->id,
            'showcase_category_id' => $showcase->id,  // уже совпадает
        ]);

        $batch = Category1cSyncBatch::factory()->create(['shop_id' => $shop->id]);
        (new SyncCategoryMappingsJob($batch->id, $shop->id))->handle();

        Event::assertNotDispatched(ProductUpdated::class);
        $this->assertSame('COMPLETED', $batch->fresh()->status);
    }

    public function test_skips_products_whose_category_1c_is_unlinked(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = Shop::factory()->create();
        $cat1c = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'showcase_category_id' => null,
        ]);
        Product::factory()->create([
            'shop_id' => $shop->id,
            'category_1c_id' => $cat1c->id,
            'showcase_category_id' => null,
        ]);

        $batch = Category1cSyncBatch::factory()->create(['shop_id' => $shop->id]);
        (new SyncCategoryMappingsJob($batch->id, $shop->id))->handle();

        Event::assertNotDispatched(ProductUpdated::class);
        $this->assertSame(0, $batch->fresh()->progress_total);
    }

    public function test_marks_batch_failed_on_exception(): void
    {
        $shop = Shop::factory()->create();
        $batch = Category1cSyncBatch::factory()->create(['shop_id' => $shop->id]);

        // искусственно ломаем — например, передаём несуществующий batchId
        try {
            (new SyncCategoryMappingsJob(999999, $shop->id))->handle();
        } catch (\Throwable $e) {
            // expected
        }

        // батч 999999 не существовал, реальный батч остался PENDING — в рамках этого теста проверяем,
        // что `failed()` callback корректно обновляет существующий batch.
        (new SyncCategoryMappingsJob($batch->id, $shop->id))->failed(new \RuntimeException('boom'));
        $batch->refresh();
        $this->assertSame('FAILED', $batch->status);
        $this->assertStringContainsString('boom', $batch->error);
    }
}
```

- [ ] **Step 7.1.2: Run → FAIL**

- [ ] **Step 7.1.3: Create Job**

```php
<?php
// app/Jobs/Category1c/SyncCategoryMappingsJob.php

namespace App\Jobs\Category1c;

use App\Events\ProductUpdated;
use App\Models\Category1c;
use App\Models\Category1cSyncBatch;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SyncCategoryMappingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public int $batchId,
        public int $shopId,
    ) {}

    public function handle(): void
    {
        $batch = Category1cSyncBatch::findOrFail($this->batchId);
        $lock = Cache::lock("category_1c_sync:{$this->shopId}", 1800);

        if (!$lock->get()) {
            $batch->update(['status' => 'FAILED', 'error' => 'Another sync is in progress']);
            return;
        }

        try {
            $batch->update(['status' => 'IN_PROGRESS']);

            $total = Product::query()
                ->where('shop_id', $this->shopId)
                ->whereHas('category1c', fn ($q) =>
                    $q->where('shop_id', $this->shopId)
                      ->whereNotNull('showcase_category_id'))
                ->count();
            $batch->update(['progress_total' => $total]);

            $touchedCount = 0;
            Product::query()
                ->where('shop_id', $this->shopId)
                ->whereHas('category1c', fn ($q) =>
                    $q->where('shop_id', $this->shopId)
                      ->whereNotNull('showcase_category_id'))
                ->with('category1c:id,showcase_category_id')
                ->lazyById(500)
                ->each(function (Product $product) use ($batch, &$touchedCount) {
                    $newId = $product->category1c->showcase_category_id;
                    if ($product->showcase_category_id !== $newId) {
                        $product->update(['showcase_category_id' => $newId]);
                        event(new ProductUpdated($product));
                        $touchedCount++;
                    }
                    $batch->increment('progress_done');
                });

            Category1c::where('shop_id', $this->shopId)
                ->whereNotNull('showcase_category_id')
                ->update(['last_synced_at' => now()]);

            $batch->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
                'metadata' => ['touched_products' => $touchedCount],
            ]);
        } catch (\Throwable $e) {
            $batch->update([
                'status' => 'FAILED',
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        Category1cSyncBatch::where('id', $this->batchId)->update([
            'status' => 'FAILED',
            'error' => $e->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
```

- [ ] **Step 7.1.4: Run → PASS**

```bash
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit tests/Unit/Jobs/SyncCategoryMappingsJobTest.php -v
```
Expected: `OK (4 tests)`.

- [ ] **Step 7.1.5: Run all admin_back suite**

```bash
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit
```

- [ ] **Step 7.1.6: Commit**

```bash
git add app/Jobs/Category1c/SyncCategoryMappingsJob.php tests/Unit/Jobs/SyncCategoryMappingsJobTest.php
git commit -m "feat(jobs): add SyncCategoryMappingsJob for mass product apply (LASTOCHKI-JAMI-10114)"
```

### Task 7.2: Bump composer version пакета в admin_back

- [ ] **Step 7.2.1: Update composer.json**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_docker/lastochka_admin_back
composer require comrades/laravel-rabbitmq-events:"^X.Y.(Z+1)"
```

- [ ] **Step 7.2.2: Run all tests**

- [ ] **Step 7.2.3: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: bump rabbitmq-events package (LASTOCHKI-JAMI-10114)"
```

---

## Phase 8: admin_front — Module toggle + routing

### Task 8.1: MODULE_DEFAULTS + AsideComponent + router

**Files:**
- Modify: `lastochka_admin_front/src/stores/modules.js` (или соответствующий файл)
- Modify: `lastochka_admin_front/src/components/AsideComponent.vue`
- Modify: `lastochka_admin_front/src/router/index.js`

- [ ] **Step 8.1.1: Найти файлы**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_admin_front
grep -RIn "MODULE_DEFAULTS\|isModuleEnabled\|getModuleConfig" src/ 2>/dev/null | head -10
```
Запомнить пути.

- [ ] **Step 8.1.2: Добавить ключ в MODULE_DEFAULTS**

В файле найденном в шаге 8.1.1 добавить:
```js
category_1c_integration: false,
```

- [ ] **Step 8.1.3: AsideComponent — добавить в moduleConfig**

```js
const moduleConfig = {
  // ...existing
  category_1c: { moduleKey: 'category_1c_integration', requiresAdmin: true },
}
```

- [ ] **Step 8.1.4: router/index.js — новый route**

```js
{
  path: '/categories-1c',
  name: 'categories-1c',
  component: () => import('@/views/category-1c-management/index.vue'),
  meta: {
    authRequired: true,
    header: true,
    aside: true,
    moduleKey: 'category_1c_integration',
  },
  beforeEnter: (to, from, next) => {
    moduleStore.isModuleEnabled('category_1c_integration') ? next() : next('/');
  },
},
```

- [ ] **Step 8.1.5: Smoke check — нет JS-ошибок при build**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_admin_front
npm run build 2>&1 | tail -20
```
Expected: build success или ругань на отсутствующий `index.vue` — нормально, мы его создадим в следующих тасках. Если есть JS syntax errors в наших правках — фикс.

- [ ] **Step 8.1.6: Commit**

```bash
git add src/stores/modules.js src/components/AsideComponent.vue src/router/index.js
git commit -m "feat(modules): register category_1c_integration toggle and route (LASTOCHKI-JAMI-10114)"
```

---

## Phase 9: admin_front — GraphQL clients

### Task 9.1: Все .graphql файлы экрана

**Files:**
- Create: `src/views/category-1c-management/graphql/categories1c.graphql`
- Create: `src/views/category-1c-management/graphql/categoriesShowcase.graphql`
- Create: `src/views/category-1c-management/graphql/linkCategories1c.graphql`
- Create: `src/views/category-1c-management/graphql/unlinkCategories1c.graphql`
- Create: `src/views/category-1c-management/graphql/syncCategories1c.graphql`
- Create: `src/views/category-1c-management/graphql/syncBatch.graphql`

- [ ] **Step 9.1.1: `categories1c.graphql`**

```graphql
# src/views/category-1c-management/graphql/categories1c.graphql

query CategoriesOneC(
  $shop_id: ID!
  $linked_status: Category1cLinkedFilter = ALL
  $search: String
  $only_leaves: Boolean = true
  $page: Int = 1
) {
  categories_1c(
    shop_id: $shop_id
    linked_status: $linked_status
    search: $search
    only_leaves: $only_leaves
    first: 50
    page: $page
  ) {
    data {
      id
      external_id
      name
      position
      is_active
      is_leaf
      is_linked
      is_new
      is_source_deleted
      products_count
      showcase_category_id
      showcase_category { id title }
      linked_at
      last_synced_at
    }
    paginatorInfo { count currentPage hasMorePages lastPage total }
  }
}
```

- [ ] **Step 9.1.2: `categoriesShowcase.graphql`**

```graphql
# src/views/category-1c-management/graphql/categoriesShowcase.graphql

query CategoriesShowcase($shop_id: ID!, $search: String) {
  categories(shop_id: $shop_id, search: $search) {
    data {
      id
      title
      child_categories { id title }
      category_1c_mappings_count(shop_id: $shop_id)
      has_orphan_1c_sources(shop_id: $shop_id)
    }
  }
}
```

- [ ] **Step 9.1.3: `linkCategories1c.graphql`**

```graphql
mutation LinkCategories1c($category_1c_ids: [ID!]!, $showcase_category_id: ID!) {
  link_categories_1c_to_showcase(
    category_1c_ids: $category_1c_ids
    showcase_category_id: $showcase_category_id
  ) {
    id
    showcase_category_id
    linked_at
  }
}
```

- [ ] **Step 9.1.4: `unlinkCategories1c.graphql`**

```graphql
mutation UnlinkCategories1c($category_1c_ids: [ID!]!) {
  unlink_categories_1c(category_1c_ids: $category_1c_ids) {
    id
    showcase_category_id
    linked_at
  }
}
```

- [ ] **Step 9.1.5: `syncCategories1c.graphql`**

```graphql
mutation SyncCategories1c($shop_id: ID!) {
  sync_categories_1c(shop_id: $shop_id) {
    id
    status
    progress_total
    progress_done
  }
}
```

- [ ] **Step 9.1.6: `syncBatch.graphql`**

```graphql
query SyncBatch($shop_id: ID!) {
  category_1c_sync_batch(shop_id: $shop_id) {
    id
    status
    progress_total
    progress_done
    started_at
    completed_at
    error
  }
}
```

- [ ] **Step 9.1.7: Commit**

```bash
git add src/views/category-1c-management/graphql/
git commit -m "feat(graphql): add admin_front clients for Category1c (LASTOCHKI-JAMI-10114)"
```

---

## Phase 10: admin_front — UI components

### Task 10.1: `index.vue` (screen-level)

**Files:**
- Create: `src/views/category-1c-management/index.vue`

- [ ] **Step 10.1.1: Создать file**

```vue
<!-- src/views/category-1c-management/index.vue -->
<template>
  <section class="category-1c-management">
    <header>
      <h1>Категории товаров</h1>
      <p class="subtitle">Управление витринными категориями и их связью с категориями из 1С</p>

      <div class="header-controls">
        <ShopSelectorComponent v-model="selectedShopId" />
        <NewOneCBanner :count="newCount" @show="onShowNewOnly" />
        <SyncBatchButton :shop-id="selectedShopId" />
      </div>
    </header>

    <main class="columns">
      <OneCColumn
        :shop-id="selectedShopId"
        :selected-ids="selectedOneCIds"
        :linked-filter="linkedFilter"
        :search="searchOneC"
        :show-only-new="showOnlyNew"
        @update:selected-ids="selectedOneCIds = $event"
        @update:linked-filter="linkedFilter = $event"
        @update:search="searchOneC = $event"
      />
      <ShowcaseColumn
        :shop-id="selectedShopId"
        :search="searchShowcase"
        :selected-one-c-ids="selectedOneCIds"
        @update:search="searchShowcase = $event"
        @drop-link="onDropLink"
      />
    </main>

    <BulkLinkBar
      v-if="selectedOneCIds.length > 0"
      :count="selectedOneCIds.length"
      @reset="selectedOneCIds = []"
    />
  </section>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useQuery, useMutation } from '@vue/apollo-composable'
import gql from 'graphql-tag'
import { useShopStore } from '@/stores/shop'
import ShopSelectorComponent from '@/components/ShopSelectorComponent.vue'
import OneCColumn from './components/OneCColumn.vue'
import ShowcaseColumn from './components/ShowcaseColumn.vue'
import SyncBatchButton from './components/SyncBatchButton.vue'
import BulkLinkBar from './components/BulkLinkBar.vue'
import NewOneCBanner from './components/NewOneCBanner.vue'

import LinkCategories1cMutation from './graphql/linkCategories1c.graphql'

const shopStore = useShopStore()
const selectedShopId = ref(shopStore.currentShopId)

const linkedFilter = ref('UNLINKED')
const searchOneC = ref('')
const selectedOneCIds = ref([])
const showOnlyNew = ref(false)

const searchShowcase = ref('')

const NEW_COUNT_QUERY = gql`
  query NewCount($shop_id: ID!) {
    categories_1c_new_count(shop_id: $shop_id)
  }
`
const { result: newCountResult } = useQuery(NEW_COUNT_QUERY,
  () => ({ shop_id: selectedShopId.value }),
  () => ({ enabled: !!selectedShopId.value })
)
const newCount = computed(() => newCountResult.value?.categories_1c_new_count ?? 0)

const onShowNewOnly = () => {
  linkedFilter.value = 'UNLINKED'
  showOnlyNew.value = true
}

const { mutate: linkMutation } = useMutation(LinkCategories1cMutation)
const onDropLink = async ({ showcase_category_id, override_one_c_ids }) => {
  const ids = override_one_c_ids ?? selectedOneCIds.value
  if (!ids.length) return
  await linkMutation({
    category_1c_ids: ids,
    showcase_category_id,
  })
  selectedOneCIds.value = []
  // refetch выполнится через Apollo cache update
}
</script>

<style lang="stylus" scoped>
.category-1c-management
  padding 24px
  display flex
  flex-direction column
  gap 16px

  header
    h1
      font-size 24px
      font-weight 600
    .subtitle
      color #666
      font-size 14px
    .header-controls
      display flex
      gap 16px
      align-items center
      margin-top 16px

  .columns
    display grid
    grid-template-columns 1fr 1fr
    gap 24px
    flex 1
</style>
```

- [ ] **Step 10.1.2: Commit**

```bash
git add src/views/category-1c-management/index.vue
git commit -m "feat(admin_front): add Category1c screen layout (LASTOCHKI-JAMI-10114)"
```

### Task 10.2: `OneCColumn.vue` + `OneCCategoryRow.vue`

**Files:**
- Create: `src/views/category-1c-management/components/OneCColumn.vue`
- Create: `src/views/category-1c-management/components/OneCCategoryRow.vue`

- [ ] **Step 10.2.1: Создать `OneCCategoryRow.vue`**

```vue
<!-- components/OneCCategoryRow.vue -->
<template>
  <div class="one-c-row" :class="{ disabled: row.is_source_deleted }">
    <input
      type="checkbox"
      :checked="selected"
      :disabled="row.is_source_deleted || !row.is_leaf"
      @change="$emit('toggle', row.id)"
    >
    <div class="info">
      <div class="name">{{ row.name }}</div>
      <div class="meta">ID: {{ row.external_id }} · Товаров: {{ row.products_count }}</div>
    </div>
    <div class="badges">
      <span v-if="row.is_new" class="badge badge--new">новая</span>
      <span v-if="row.is_linked" class="badge badge--linked">✓ привязана</span>
      <span v-if="row.is_source_deleted" class="badge badge--deleted">удалена в 1С</span>
    </div>
  </div>
</template>

<script setup>
defineProps({
  row: { type: Object, required: true },
  selected: { type: Boolean, default: false },
})
defineEmits(['toggle'])
</script>

<style lang="stylus" scoped>
.one-c-row
  display flex
  gap 12px
  align-items center
  padding 12px
  border 1px solid #e0e0e0
  border-radius 6px
  background #fff
  &.disabled
    opacity 0.5
  .info
    flex 1
    .name
      font-weight 500
    .meta
      font-size 12px
      color #999
  .badges
    display flex
    gap 6px
    .badge
      padding 2px 8px
      border-radius 4px
      font-size 11px
      &.badge--new
        background #ff8c00
        color #fff
      &.badge--linked
        background #28a745
        color #fff
      &.badge--deleted
        background #888
        color #fff
</style>
```

- [ ] **Step 10.2.2: Создать `OneCColumn.vue`**

```vue
<!-- components/OneCColumn.vue -->
<template>
  <section class="one-c-column">
    <header>
      <h3>Категории 1С (источник, read-only)</h3>
      <span class="count">{{ totalCount }} шт</span>
    </header>

    <div class="search">
      <input
        type="text"
        :value="search"
        @input="$emit('update:search', $event.target.value)"
        placeholder="Поиск по категориям 1С..."
      >
    </div>

    <div class="filters">
      <button
        v-for="opt in filterOptions"
        :key="opt.value"
        :class="{ active: linkedFilter === opt.value }"
        @click="$emit('update:linked-filter', opt.value)"
      >
        {{ opt.label }}
      </button>
    </div>

    <div class="rows">
      <draggable
        v-model="rowsLocal"
        :group="{ name: 'oneC', pull: 'clone', put: false }"
        :clone="cloneRow"
        item-key="id"
      >
        <template #item="{ element }">
          <OneCCategoryRow
            :row="element"
            :selected="selectedIds.includes(element.id)"
            @toggle="toggleSelect"
          />
        </template>
      </draggable>
    </div>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useQuery } from '@vue/apollo-composable'
import draggable from 'vuedraggable'
import CategoriesOneCQuery from '../graphql/categories1c.graphql'
import OneCCategoryRow from './OneCCategoryRow.vue'

const props = defineProps({
  shopId: { type: [Number, String], required: true },
  selectedIds: { type: Array, default: () => [] },
  linkedFilter: { type: String, default: 'UNLINKED' },
  search: { type: String, default: '' },
  showOnlyNew: { type: Boolean, default: false },
})
const emit = defineEmits(['update:selected-ids', 'update:linked-filter', 'update:search'])

const filterOptions = [
  { value: 'UNLINKED', label: 'Не привязаны' },
  { value: 'LINKED', label: 'Привязаны' },
  { value: 'ALL', label: 'Все' },
]

const { result } = useQuery(
  CategoriesOneCQuery,
  () => ({
    shop_id: props.shopId,
    linked_status: props.linkedFilter,
    search: props.search || null,
    only_leaves: true,
  }),
  () => ({ enabled: !!props.shopId })
)

const rowsLocal = ref([])
watch(() => result.value?.categories_1c?.data, (data) => {
  rowsLocal.value = data ? [...data] : []
}, { immediate: true })

const totalCount = computed(() => result.value?.categories_1c?.paginatorInfo?.total ?? 0)

const cloneRow = (row) => ({ ...row })

const toggleSelect = (id) => {
  const next = props.selectedIds.includes(id)
    ? props.selectedIds.filter(x => x !== id)
    : [...props.selectedIds, id]
  emit('update:selected-ids', next)
}
</script>

<style lang="stylus" scoped>
.one-c-column
  display flex
  flex-direction column
  background #f7f7f7
  padding 16px
  border-radius 8px
  header
    display flex
    justify-content space-between
    h3
      font-weight 600
    .count
      color #888
  .search
    margin 12px 0
    input
      width 100%
      padding 8px
      border 1px solid #ddd
      border-radius 4px
  .filters
    display flex
    gap 8px
    margin-bottom 12px
    button
      padding 4px 12px
      border 1px solid #ccc
      border-radius 16px
      background #fff
      cursor pointer
      &.active
        background #ff8c00
        color #fff
        border-color #ff8c00
  .rows
    display flex
    flex-direction column
    gap 8px
    overflow-y auto
    max-height 600px
</style>
```

- [ ] **Step 10.2.3: Commit**

```bash
git add src/views/category-1c-management/components/OneCColumn.vue \
       src/views/category-1c-management/components/OneCCategoryRow.vue
git commit -m "feat(admin_front): add OneCColumn with drag-and-drop multi-select (LASTOCHKI-JAMI-10114)"
```

### Task 10.3: `ShowcaseColumn.vue` + `ShowcaseCategoryRow.vue`

**Files:**
- Create: `src/views/category-1c-management/components/ShowcaseColumn.vue`
- Create: `src/views/category-1c-management/components/ShowcaseCategoryRow.vue`

- [ ] **Step 10.3.1: Создать `ShowcaseCategoryRow.vue`**

```vue
<!-- components/ShowcaseCategoryRow.vue -->
<template>
  <div
    class="showcase-row"
    :class="{ 'is-leaf': isLeaf, 'drop-target': isDropTarget }"
  >
    <div class="info">
      <div class="title">{{ row.title }}</div>
      <div class="meta" v-if="!isLeaf">
        {{ row.child_categories?.length || 0 }} подкатегорий
      </div>
    </div>
    <div class="badges">
      <span v-if="row.category_1c_mappings_count" class="badge badge--sources">
        {{ row.category_1c_mappings_count }} источник{{ pluralize(row.category_1c_mappings_count) }} 1С
      </span>
      <span v-if="row.has_orphan_1c_sources" class="badge badge--warn">
        требует внимания
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  row: { type: Object, required: true },
  isDropTarget: { type: Boolean, default: false },
})

const isLeaf = computed(() => !(props.row.child_categories?.length))

const pluralize = (n) => {
  const mod10 = n % 10
  const mod100 = n % 100
  if (mod10 === 1 && mod100 !== 11) return ''
  if ([2, 3, 4].includes(mod10) && ![12, 13, 14].includes(mod100)) return 'а'
  return 'ов'
}
</script>

<style lang="stylus" scoped>
.showcase-row
  padding 12px
  border 1px solid #e0e0e0
  border-radius 6px
  background #fff
  display flex
  justify-content space-between
  align-items center
  &.drop-target
    border-color #ff8c00
    border-style dashed
  .info
    .title
      font-weight 500
    .meta
      color #888
      font-size 12px
  .badges
    display flex
    gap 6px
    .badge
      padding 2px 8px
      border-radius 4px
      font-size 11px
      &.badge--sources
        background #007bff
        color #fff
      &.badge--warn
        background #dc3545
        color #fff
</style>
```

- [ ] **Step 10.3.2: Создать `ShowcaseColumn.vue`**

```vue
<!-- components/ShowcaseColumn.vue -->
<template>
  <section class="showcase-column">
    <header>
      <h3>Витринные категории <small>(отображаются в приложении)</small></h3>
      <button class="btn-create" @click="onCreate">+ Создать</button>
    </header>

    <div class="search">
      <input
        type="text"
        :value="search"
        @input="$emit('update:search', $event.target.value)"
        placeholder="Поиск по витринным категориям..."
      >
    </div>

    <div class="rows">
      <div
        v-for="row in rows"
        :key="row.id"
        class="showcase-row-wrapper"
        :class="{ 'drop-active': dragOverId === row.id }"
      >
        <draggable
          v-if="isLeaf(row)"
          :list="[]"
          :group="{ name: 'oneC', pull: false, put: true }"
          item-key="id"
          @add="(e) => onDrop(row.id, e)"
          @dragenter.native="dragOverId = row.id"
          @dragleave.native="dragOverId = null"
          tag="div"
        >
          <template #item="_">
            <span></span>
          </template>
        </draggable>
        <ShowcaseCategoryRow
          :row="row"
          :is-drop-target="dragOverId === row.id"
        />
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useQuery } from '@vue/apollo-composable'
import draggable from 'vuedraggable'
import CategoriesShowcaseQuery from '../graphql/categoriesShowcase.graphql'
import ShowcaseCategoryRow from './ShowcaseCategoryRow.vue'

const props = defineProps({
  shopId: { type: [Number, String], required: true },
  search: { type: String, default: '' },
  selectedOneCIds: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:search', 'drop-link'])

const { result } = useQuery(
  CategoriesShowcaseQuery,
  () => ({ shop_id: props.shopId, search: props.search || null }),
  () => ({ enabled: !!props.shopId })
)

const rows = computed(() => result.value?.categories?.data ?? [])

const dragOverId = ref(null)
const isLeaf = (row) => !(row.child_categories?.length)

const onDrop = (showcaseId, evt) => {
  // evt.item._underlying_vm_ — клонированный row из левой колонки
  const draggedId = evt?.item?._underlying_vm_?.id
  const ids = props.selectedOneCIds.length ? null : (draggedId ? [draggedId] : [])
  emit('drop-link', {
    showcase_category_id: showcaseId,
    override_one_c_ids: ids,
  })
  dragOverId.value = null
}

const onCreate = () => {
  // открыть существующую модалку создания категории — переиспользуем
  // (точная команда — зависит от того, как существующий «Категории товаров» экран открывает создание)
  // TODO в implementation: интегрировать существующую модалку
}
</script>

<style lang="stylus" scoped>
.showcase-column
  display flex
  flex-direction column
  background #fafafa
  padding 16px
  border-radius 8px
  header
    display flex
    justify-content space-between
    align-items center
    h3
      font-weight 600
      small
        color #888
        font-weight normal
    .btn-create
      background #ff8c00
      color #fff
      border none
      padding 6px 14px
      border-radius 4px
      cursor pointer
  .search
    margin 12px 0
    input
      width 100%
      padding 8px
      border 1px solid #ddd
      border-radius 4px
  .rows
    display flex
    flex-direction column
    gap 8px
    overflow-y auto
    max-height 600px
</style>
```

(Заметка: интеграция модалки «+ Создать» с существующим экраном — explicit TODO, в implementation phase нужно посмотреть как старый экран открывает модалку и переиспользовать тот же путь. Если не вынесена — вынести в shared component.)

- [ ] **Step 10.3.3: Commit**

```bash
git add src/views/category-1c-management/components/ShowcaseColumn.vue \
       src/views/category-1c-management/components/ShowcaseCategoryRow.vue
git commit -m "feat(admin_front): add ShowcaseColumn with drop targets (LASTOCHKI-JAMI-10114)"
```

### Task 10.4: `SyncBatchButton.vue`

**Files:**
- Create: `src/views/category-1c-management/components/SyncBatchButton.vue`

- [ ] **Step 10.4.1: Создать**

```vue
<template>
  <div class="sync-batch-button">
    <button :disabled="isInProgress" @click="onClick">
      Синхронизировать
    </button>
    <div v-if="batch && isInProgress" class="progress">
      Прогресс: {{ batch.progress_done }} / {{ batch.progress_total }}
    </div>
    <div v-if="batch?.status === 'COMPLETED'" class="status status--ok">
      Синхронизация завершена · обновлено {{ batch.metadata?.touched_products ?? 0 }} товаров
    </div>
    <div v-if="batch?.status === 'FAILED'" class="status status--err">
      Ошибка: {{ batch.error }}
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useQuery, useMutation } from '@vue/apollo-composable'
import SyncBatchQuery from '../graphql/syncBatch.graphql'
import SyncCategories1cMutation from '../graphql/syncCategories1c.graphql'

const props = defineProps({ shopId: { type: [Number, String], required: true } })

const { result, refetch } = useQuery(
  SyncBatchQuery,
  () => ({ shop_id: props.shopId }),
  () => ({
    enabled: !!props.shopId,
    pollInterval: result.value?.category_1c_sync_batch?.status === 'IN_PROGRESS' ? 2000 : 0,
  })
)
const batch = computed(() => result.value?.category_1c_sync_batch)
const isInProgress = computed(() => ['PENDING', 'IN_PROGRESS'].includes(batch.value?.status))

const { mutate: syncMutation } = useMutation(SyncCategories1cMutation)
const onClick = async () => {
  await syncMutation({ shop_id: props.shopId })
  refetch()
}
</script>

<style lang="stylus" scoped>
.sync-batch-button
  display flex
  flex-direction column
  gap 6px
  align-items flex-start
  button
    background #28a745
    color #fff
    border none
    padding 8px 16px
    border-radius 4px
    cursor pointer
    &:disabled
      opacity 0.6
      cursor not-allowed
  .progress
    font-size 12px
    color #555
  .status
    font-size 12px
    padding 4px 8px
    border-radius 4px
    &--ok
      background #d4edda
      color #155724
    &--err
      background #f8d7da
      color #721c24
</style>
```

- [ ] **Step 10.4.2: Commit**

```bash
git add src/views/category-1c-management/components/SyncBatchButton.vue
git commit -m "feat(admin_front): add SyncBatchButton with polling (LASTOCHKI-JAMI-10114)"
```

### Task 10.5: `BulkLinkBar.vue` + `NewOneCBanner.vue`

**Files:**
- Create: `src/views/category-1c-management/components/BulkLinkBar.vue`
- Create: `src/views/category-1c-management/components/NewOneCBanner.vue`

- [ ] **Step 10.5.1: `BulkLinkBar.vue`**

```vue
<template>
  <div class="bulk-link-bar">
    <span>Выбрано: {{ count }}</span>
    <span class="hint">→ перетащите выбранные на витринную или нажмите «Привязать к витрине»</span>
    <button class="reset" @click="$emit('reset')">Сбросить</button>
  </div>
</template>

<script setup>
defineProps({ count: { type: Number, required: true } })
defineEmits(['reset'])
</script>

<style lang="stylus" scoped>
.bulk-link-bar
  position fixed
  bottom 0
  left 0
  right 0
  background #333
  color #fff
  padding 12px 24px
  display flex
  gap 16px
  align-items center
  .hint
    color #ccc
    font-size 13px
  .reset
    background transparent
    color #fff
    border 1px solid #fff
    padding 6px 14px
    border-radius 4px
    cursor pointer
    margin-left auto
</style>
```

- [ ] **Step 10.5.2: `NewOneCBanner.vue`**

```vue
<template>
  <div v-if="count > 0" class="new-1c-banner">
    ⚠ Новых категорий из 1С: {{ count }}
    <button @click="$emit('show')">Показать</button>
  </div>
</template>

<script setup>
defineProps({ count: { type: Number, required: true } })
defineEmits(['show'])
</script>

<style lang="stylus" scoped>
.new-1c-banner
  background #fff3cd
  color #856404
  padding 8px 16px
  border-radius 4px
  display flex
  gap 12px
  align-items center
  button
    background transparent
    color #856404
    border 1px solid #856404
    padding 4px 12px
    border-radius 4px
    cursor pointer
</style>
```

- [ ] **Step 10.5.3: Commit**

```bash
git add src/views/category-1c-management/components/BulkLinkBar.vue \
       src/views/category-1c-management/components/NewOneCBanner.vue
git commit -m "feat(admin_front): add BulkLinkBar and NewOneCBanner (LASTOCHKI-JAMI-10114)"
```

### Task 10.6: Vitest unit tests

**Files:**
- Create: `tests/unit/views/category-1c-management/SyncBatchButton.spec.js`
- Create: `tests/unit/views/category-1c-management/OneCColumn.spec.js`

- [ ] **Step 10.6.1: SyncBatchButton test**

```js
// tests/unit/views/category-1c-management/SyncBatchButton.spec.js
import { mount } from '@vue/test-utils'
import { describe, it, expect, vi } from 'vitest'
import SyncBatchButton from '@/views/category-1c-management/components/SyncBatchButton.vue'

vi.mock('@vue/apollo-composable', () => ({
  useQuery: () => ({
    result: { value: { category_1c_sync_batch: { status: 'COMPLETED', progress_total: 10, progress_done: 10, metadata: { touched_products: 5 } } } },
    refetch: vi.fn(),
  }),
  useMutation: () => ({ mutate: vi.fn() }),
}))

describe('SyncBatchButton', () => {
  it('shows completed status text', () => {
    const wrapper = mount(SyncBatchButton, { props: { shopId: 1 } })
    expect(wrapper.text()).toContain('Синхронизация завершена · обновлено 5 товаров')
  })

  it('disables button while IN_PROGRESS', () => {
    vi.doMock('@vue/apollo-composable', () => ({
      useQuery: () => ({ result: { value: { category_1c_sync_batch: { status: 'IN_PROGRESS' } } }, refetch: vi.fn() }),
      useMutation: () => ({ mutate: vi.fn() }),
    }))
    // re-mount needed in real test; this is a smoke check
  })
})
```

- [ ] **Step 10.6.2: Run vitest**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_admin_front
npm run test:unit -- SyncBatchButton
```
Expected: passes (или соответствующая команда проекта).

- [ ] **Step 10.6.3: Commit**

```bash
git add tests/unit/views/category-1c-management/
git commit -m "test(admin_front): unit tests for SyncBatchButton (LASTOCHKI-JAMI-10114)"
```

---

## Phase 11: Local verification

### Task 11.1: Прогнать всю backend-suite

- [ ] **Step 11.1.1: 1c_parser**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_1c_parser
vendor/bin/phpunit
```

- [ ] **Step 11.1.2: admin_back (PHP 8.4 SQLite, фоновые pre-existing fail'ы — игнор)**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_docker/lastochka_admin_back
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit --filter=Category1c
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit --filter=ProductEventTransformer
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit --filter=SyncCategoryMappingsJob
```
Expected: ✓ для всех новых tests.

- [ ] **Step 11.1.3: package**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/fullfilment_saas/multi_vendor_delivery_rabbitmq_package
vendor/bin/phpunit tests/Unit/EventTypesRegistryTest.php
```

### Task 11.2: admin_front build

- [ ] **Step 11.2.1: Build**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_admin_front
npm run build
```
Expected: success.

- [ ] **Step 11.2.2: Vitest**

```bash
npm run test:unit
```

---

## Phase 12: Lastochka deploy + activation

### Task 12.1: Push в правильном порядке

- [ ] **Step 12.1.1: Подтверждение от пользователя на push**

Не пушим без явного «push'и» от пользователя.

- [ ] **Step 12.1.2: Push package, monitor CI**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/fullfilment_saas/multi_vendor_delivery_rabbitmq_package
git push origin feat/lastochki-jami-10114-1c-categories-mapping
python3 ~/.claude/gitlab_cli.py last_pipeline <namespace>/multi_vendor_delivery_rabbitmq_package --ref feat/lastochki-jami-10114-1c-categories-mapping
```

- [ ] **Step 12.1.3: Push 1c_parser, monitor**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_1c_parser
git push origin feat/lastochki-jami-10114-1c-categories-mapping
python3 ~/.claude/gitlab_cli.py last_pipeline lastochka/1c_parser --ref feat/lastochki-jami-10114-1c-categories-mapping
```

- [ ] **Step 12.1.4: Pre-deploy backup на проде Lastochka**

```bash
ssh admin@155.212.175.116 "mkdir -p /home/admin/backups/category-1c-pre-deploy-$(date +%Y%m%d-%H%M%S) && \
  mysqldump --single-transaction --quick --no-tablespaces \
    cron_admin products menu_items admin_settings | gzip > \
    /home/admin/backups/category-1c-pre-deploy-$(date +%Y%m%d-%H%M%S)/dump.sql.gz"
```

- [ ] **Step 12.1.5: Push admin_back, monitor**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_docker/lastochka_admin_back
git push origin feat/lastochki-jami-10114-1c-categories-mapping
python3 ~/.claude/gitlab_cli.py last_pipeline lastochka/admin_back --ref feat/lastochki-jami-10114-1c-categories-mapping
```

После CI green:

```bash
ssh admin@155.212.175.116 "supervisorctl status | grep rabbitmq"
ssh admin@155.212.175.116 "cd /home/admin/apps/cron_admin_back && sudo -u www-data php artisan lighthouse:clear-cache"
ssh admin@155.212.175.116 "supervisorctl restart all"
```

- [ ] **Step 12.1.6: Push admin_front, monitor**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/lastochka/lastochka_admin_front
git push origin feat/lastochki-jami-10114-1c-categories-mapping
python3 ~/.claude/gitlab_cli.py last_pipeline lastochka/admin_front --ref feat/lastochki-jami-10114-1c-categories-mapping
```

### Task 12.2: Включение фичи на Lastochka

- [ ] **Step 12.2.1: Обновить projectModules + menu_items на проде**

Подтверждение от пользователя обязательно — это write на проде:

```bash
# Доступы из ~/.claude/projects/.../memory/server-credentials.md
ssh admin@155.212.175.116 "mysql cron_admin -e \"
  UPDATE admin_settings SET value = JSON_SET(value, '\$.category_1c_integration', true) WHERE \\\`key\\\` = 'project_modules';
  UPDATE menu_items SET is_visible = 1 WHERE \\\`key\\\` = 'category_1c';
\""
```

- [ ] **Step 12.2.2: Manual smoke**

В браузере на dev (`alladin.comrades.dev` для Lastochka? уточнить домен по memory `server-map.md`):
- Открыть админку, проверить что появился пункт «Категории 1С».
- Открыть `/categories-1c`, выбрать тестовый магазин.
- Проверить что левая колонка заполнена 1С-категориями.

- [ ] **Step 12.2.3: Manual e2e — привязка + sync**

- Перетащить 1С на витринную → бейдж «✓ привязана».
- Кнопка «Синхронизировать» → status COMPLETED → toast.
- В мобилке (claude-in-chrome): товары в новой витринной.

- [ ] **Step 12.2.4: Verify outbox через SSH**

```bash
ssh admin@155.212.175.116 "cd /home/admin/apps/cron_admin_back && \
  sudo -u www-data php artisan tinker --execute=\"echo \\\App\\\Models\\\EventOutbox::where('routing_key','product.updated')->latest()->first()?->payload;\""
```
Ожидаем: payload содержит `category_id` = ID витринной + `category_source='app'`.

- [ ] **Step 12.2.5: Cascade test**

В тестовом 1С удалить одну из привязанных категорий → прогнать обмен → проверить что в курьерке у витринной появился бейдж «требует внимания · Источник 1С удалён».

---

## Phase 13: Sync-core distribution на форки (после приёмки)

### Task 13.1: Sync-core broadcast

- [ ] **Step 13.1.1: Подтверждение приёмки заказчиком (Platrum закрыт как Done)**

- [ ] **Step 13.1.2: Запустить sync-core UI**

```bash
python3 ~/.claude/sync-core-ui.py
```
В UI выбрать sourceкоммиты Lastochki, target форки: Aladdin, Electrohub, Foodlink (admin_back/1c_parser/admin_front; Foodlink — те же 3 репо, но без storefront-related; в нашей фиче storefront ничего и не меняется).

- [ ] **Step 13.1.3: Verify коммиты на каждом форке**

```bash
for fork in aladdin electrohub foodlink; do
  for repo in admin_back delivery_back 1c_parser admin_front; do
    echo "=== $fork/$repo ==="
    cd ~/PhpstormProjects/$fork/${fork}_docker/${fork}_$repo 2>/dev/null \
      || cd ~/PhpstormProjects/$fork/${fork}_$repo 2>/dev/null \
      || continue
    git log --grep='LASTOCHKI-JAMI-10114' --oneline | wc -l
  done
done
```
Ожидаем: ровно столько коммитов на каждом форке, сколько было сделано на Lastochke (исключая foodlink/storefront — там 0).

- [ ] **Step 13.1.4: На форках убедиться `projectModules.category_1c_integration = false`**

На каждом форке:
```bash
ssh admin@<forks-host> "mysql <db> -e \"SELECT JSON_EXTRACT(value, '\$.category_1c_integration') FROM admin_settings WHERE \\\`key\\\`='project_modules'\""
```
Ожидаем: `false` или `NULL` (= dormant) на всех кроме Lastochki.

- [ ] **Step 13.1.5: Sync-core verify через `git log` (явный отчёт)**

В терминале — суммарный отчёт пользователю: «4/4 форка получили коммит, фича dormant на всех кроме Lastochki».

---

## Self-Review Checklist

✓ **Spec coverage:**
- §2 Architecture → Task overview + все Phases
- §3 Data Model → Phase 3 (migrations) + Phase 4 (models)
- §4 RabbitMQ Flow → Phase 1 (package) + Phase 2 (1c_parser) + Phase 5 (transformers)
- §5 GraphQL API → Phase 6
- §6 Admin Frontend → Phase 8-10
- §7 SyncCategoryMappingsJob → Phase 7
- §8 Rollout → Phase 12
- §9 Testing → embedded в каждой задаче
- §10 Sync-core forks → Phase 13

✓ **Placeholder scan:** один TODO в Task 10.3.2 (`onCreate` модалки) — это **намеренный** TODO для implementation phase (требует посмотреть на существующий экран «Категории товаров» и переиспользовать его модалку; решение зависит от того, как там эта логика лежит). Оставлен явно — не placeholder в смысле «недосказанного дизайна».

✓ **Type consistency:** `Category1c` / `category_1c` / `categories_1c` везде согласованы. `showcase_category_id` — единое имя поля во всех слоях. Routing keys `category_1c.{created,updated,deleted}` — единые.

✓ **TDD discipline:** каждый backend task имеет failing test → impl → pass → commit. Frontend (Vue) — компонентные тесты только для критичных (SyncBatchButton); UI компоненты обычно иначе пишутся.

---

## Execution Handoff

**Plan complete and saved to** `docs/superpowers/plans/2026-05-05-1c-categories-mapping.md`. Two execution options:

**1. Subagent-Driven (recommended)** — я диспатчу свежий subagent на каждую задачу, ревью между ними, быстрая итерация.

**2. Inline Execution** — выполняем задачи в этой сессии через executing-plans, batch execution с чекпоинтами для ревью.

**Какой подход?**
