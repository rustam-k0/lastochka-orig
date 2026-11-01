# Объединение стоп-листов с полями карточки товара — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Стоп-листы магазина («не отображать» / «не доставлять») становятся представлением полей карточки товара (`status_id` / `pickup_only`); отдельные флаги `stop_list_hidden` / `stop_list_pickup_only` удаляются после merge-миграций данных.

**Architecture:** Один источник правды — поля карточки. GraphQL-фасад стоп-листов (query `shop_stop_list_products`, мутации `ProductStopListAdd`/`ProductStopListRemove`) сохраняется, внутри делегирует в логику `ProductHide`/`ProductPublish` и `pickup_only`. Даты попадания в стоп-лист ведут новые колонки `hidden_at`/`pickup_only_at` через мутаторы модели. Storefront-enforcement упрощается до уже существующих проверок `is_active`/`pickup_only`.

**Tech Stack:** admin_back — Laravel 9 + Lighthouse (GraphQL) + MySQL 8; storefront_back — Laravel 12 + REST + Meilisearch; admin_front — Vue 3 Options API + Vite.

**Спека:** `docs/superpowers/specs/2026-07-16-stop-lists-unify-design.md` (admin_back).

## Global Constraints

- Репозитории: `~/PhpstormProjects/mendev/mendev_admin_back`, `mendev_storefront_back`, `mendev_admin_front`. В каждом перед первой задачей репо создать ветку `feature/stop-lists-unify` от актуального default-бранча (admin_back/admin_front: `master`, storefront_back: `main`).
- Перед КАЖДЫМ `git add` проверять `git rev-parse --abbrev-ref HEAD` == `feature/stop-lists-unify` (грабля: прямой коммит в main).
- НЕ мержить, НЕ пушить, НЕ деплоить — merge `--no-ff` и push только по явной команде пользователя (вне этого плана).
- Тесты ТОЛЬКО локально. admin_back: `DB_HOST=127.0.0.1 vendor/bin/phpunit <один файл>` (phpunit docker-модулей принимает только первый path-аргумент). storefront_back: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=<Имя>` (SQLite in-memory, миграции накатываются сами).
- Миграции admin_back: данные только через `DB::table` (не Eloquent), guard'ы `Schema::hasColumn` на каждый шаг (форк-идемпотентность).
- «Не активный» товар = `status_id != 1` (`Status::CODE_ACTIVE`); скрытие ставит `status_id = 3` (`Status::CODE_NOT_PUBLISHED`).
- Товары в admin_back per-shop (`products.shop_id`). Создаваемый без статуса товар получает `status_id = 3` в `Product::boot creating` — учитывать в тестовых фикстурах: «активный» товар создавать явно со `status_id => 1`.
- Стиль admin_back: отступ 2 пробела, без `declare(strict_types=1)` в моделях/мутациях. Стиль storefront_back: `declare(strict_types=1)`, отступ 4.
- Деплой-ноты (вне плана, по явной команде): порядок admin_back → storefront_back → admin_front; после деплоя admin_back — `lighthouse:clear-cache` (схема GraphQL изменилась); на dev до деплоя снять счётчики `SELECT COUNT(*) FROM products WHERE stop_list_hidden=1` / `stop_list_pickup_only=1` в обеих БД и сверить после миграций с `status_id=3 AND hidden_at IS NOT NULL` / `pickup_only=1`.
- ⚠️ КРИТИЧНО для деплоя storefront: `php artisan search:reindex` выполнять СРАЗУ после миграции, тем же maintenance-шагом. Merge-миграция ставит `is_active=false` через `DB::table` (мимо Observer → Meili-документы НЕ переиндексируются), а фильтр `stop_list_hidden = false` из поиска уже удалён — до реиндекса товары, скрытые ТОЛЬКО стоп-листом, видны в поисковой выдаче (leak-window). Это находка финального ревью, не косметика.

---

### Task 1: admin_back — миграция unify + модель Product (fillable/casts/мутаторы)

**Files:**
- Create: `database/migrations/2026_07_16_100000_unify_stop_lists_with_product_card.php`
- Modify: `app/Models/Product.php` (fillable ~51-54, casts ~77-80, + два мутатора после `boot()`)
- Test: `tests/Feature/ProductStopListTest.php` (ПОЛНАЯ перезапись файла; в этой задаче — каркас + тесты схемы и мутаторов, задачи 2-4 добавляют методы)

**Interfaces:**
- Produces: колонки `products.hidden_at` (timestamp nullable), `products.pickup_only_at` (timestamp nullable); колонки `stop_list_*` удалены. Мутаторы `setStatusIdAttribute($value)` / `setPickupOnlyAttribute($value)` на `App\Models\Product` — ведут даты автоматически при любой Eloquent-записи. Casts `hidden_at`/`pickup_only_at` => datetime. В fillable дат НЕТ (пишутся только мутаторами/SQL).

- [ ] **Step 1: Создать ветку**

```bash
cd ~/PhpstormProjects/mendev/mendev_admin_back
git checkout master && git pull
git checkout -b feature/stop-lists-unify
```

- [ ] **Step 2: Перезаписать тест-файл (каркас + схема + мутаторы)**

Заменить всё содержимое `tests/Feature/ProductStopListTest.php` на:

```php
<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Status;
use App\Models\User;
use App\Events\ProductUpdated;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Стоп-листы = представление полей карточки товара (Platrum 12188 / MID-155).
 *
 * «hidden» (не отображать)  = товары магазина со status_id != CODE_ACTIVE;
 * «pickup_only» (не доставлять) = товары магазина с pickup_only = true.
 * Даты попадания в стоп-лист ведут мутаторы модели: hidden_at / pickup_only_at.
 *
 * Мутации/квери защищены @guard @can(model:"Admin",ability:"onlyAdmin")
 * @section(key:"stores"). Тест логинится глобальным админом.
 */
class ProductStopListTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::setValue('role_access_matrix_enabled', '1', 'boolean');

        $adminRole = Role::findOrCreate(Role::ADMIN);
        $adminRole->scope_type = Role::SCOPE_GLOBAL;
        $adminRole->save();

        $this->admin = User::create([
            'name' => 'StopList Admin',
            'phone' => '+7901' . mt_rand(1000000, 9999999),
            'email' => uniqid('stoplist_admin_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->admin->assignRole($adminRole);
        $this->assertTrue($this->admin->fresh()->isGlobalScope());
    }

    private function createShop(): Shop
    {
        return Shop::create(['title' => 'StopList shop ' . uniqid()]);
    }

    /**
     * ВНИМАНИЕ: без явного status_id товар создаётся НЕ активным
     * (Product::boot creating → CODE_NOT_PUBLISHED). «Активный» товар
     * в фикстурах всегда задавать явно: ['status_id' => Status::CODE_ACTIVE].
     */
    private function createProduct(Shop $shop, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'title' => 'Product ' . uniqid(),
            'articul' => 'ART-' . uniqid(),
            'shop_id' => $shop->id,
        ], $attributes));
    }

    private const ADD_MUTATION = '
        mutation ProductStopListAdd($product_id: Int!, $type: String!) {
            ProductStopListAdd(product_id: $product_id, type: $type) {
                code
                message
            }
        }
    ';

    private const REMOVE_MUTATION = '
        mutation ProductStopListRemove($product_id: Int!, $type: String!) {
            ProductStopListRemove(product_id: $product_id, type: $type) {
                code
                message
            }
        }
    ';

    private const LIST_QUERY = '
        query ShopStopList($shop_id: Int!, $type: String!) {
            shop_stop_list_products(shop_id: $shop_id, type: $type, first: 50) {
                data {
                    id
                    articul
                    title
                    categories { title }
                    hidden_at
                    pickup_only_at
                }
                paginatorInfo { total }
            }
        }
    ';

    // ==================== Схема ====================

    public function test_products_table_has_unified_date_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'hidden_at'));
        $this->assertTrue(Schema::hasColumn('products', 'pickup_only_at'));
    }

    public function test_products_table_has_no_stop_list_flag_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('products', 'stop_list_hidden'));
        $this->assertFalse(Schema::hasColumn('products', 'stop_list_hidden_at'));
        $this->assertFalse(Schema::hasColumn('products', 'stop_list_pickup_only'));
        $this->assertFalse(Schema::hasColumn('products', 'stop_list_pickup_only_at'));
    }

    // ==================== Мутаторы дат ====================

    public function test_hiding_product_sets_hidden_at(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);
        $this->assertNull($product->fresh()->hidden_at);

        $product->update(['status_id' => Status::CODE_NOT_PUBLISHED]);

        $this->assertNotNull($product->fresh()->hidden_at);
    }

    public function test_publishing_product_clears_hidden_at(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);
        $this->assertNotNull($product->fresh()->hidden_at);

        $product->update(['status_id' => Status::CODE_ACTIVE]);

        $this->assertNull($product->fresh()->hidden_at);
    }

    public function test_product_created_inactive_by_default_gets_hidden_at(): void
    {
        $shop = $this->createShop();
        // Без явного status_id: creating-хук ставит CODE_NOT_PUBLISHED →
        // мутатор обязан проставить hidden_at (дата = момент создания).
        $product = $this->createProduct($shop);

        $fresh = $product->fresh();
        $this->assertSame(Status::CODE_NOT_PUBLISHED, (int) $fresh->status_id);
        $this->assertNotNull($fresh->hidden_at);
    }

    public function test_resaving_same_status_keeps_hidden_at(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);

        $old = now()->subDay()->startOfSecond();
        DB::table('products')->where('id', $product->id)->update(['hidden_at' => $old]);

        // Повторная запись ТОГО ЖЕ статуса не должна сдвигать дату.
        $product->fresh()->update(['status_id' => Status::CODE_NOT_PUBLISHED]);

        $this->assertTrue($old->equalTo($product->fresh()->hidden_at));
    }

    public function test_pickup_only_toggle_maintains_pickup_only_at(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);
        $this->assertNull($product->fresh()->pickup_only_at);

        $product->update(['pickup_only' => true]);
        $this->assertNotNull($product->fresh()->pickup_only_at);

        $product->fresh()->update(['pickup_only' => false]);
        $this->assertNull($product->fresh()->pickup_only_at);
    }

    public function test_resaving_same_pickup_only_keeps_date(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, [
            'status_id' => Status::CODE_ACTIVE,
            'pickup_only' => true,
        ]);

        $old = now()->subDay()->startOfSecond();
        DB::table('products')->where('id', $product->id)->update(['pickup_only_at' => $old]);

        $product->fresh()->update(['pickup_only' => true]);

        $this->assertTrue($old->equalTo($product->fresh()->pickup_only_at));
    }

    public function test_mutators_work_under_without_events(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);

        // Консьюмеры RabbitMQ сохраняют через withoutEvents() — мутаторы
        // (в отличие от observer'ов) обязаны отработать и там.
        Product::withoutEvents(function () use ($product) {
            $product->update([
                'status_id' => Status::CODE_NOT_PUBLISHED,
                'pickup_only' => true,
            ]);
        });

        $fresh = $product->fresh();
        $this->assertNotNull($fresh->hidden_at);
        $this->assertNotNull($fresh->pickup_only_at);
    }
}
```

- [ ] **Step 3: Прогнать тесты — убедиться в падении**

```bash
DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/ProductStopListTest.php
```
Expected: FAIL — `hidden_at` колонки нет (`test_products_table_has_unified_date_columns`), `stop_list_hidden` ещё существует.

- [ ] **Step 4: Написать миграцию**

Создать `database/migrations/2026_07_16_100000_unify_stop_lists_with_product_card.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Объединение стоп-листов с полями карточки товара (Platrum 12188 / MID-155).
 *
 * Стоп-листы становятся представлением полей карточки: «не отображать» =
 * status_id != 1 (активный), «не доставлять» = pickup_only. Отдельные флаги
 * stop_list_* удаляются; даты попадания в стоп-лист переезжают в hidden_at /
 * pickup_only_at (ведутся мутаторами модели при любой смене статуса/самовывоза).
 *
 * Данные мержатся ДО удаления флагов: скрытые стоп-листом деактивируются
 * (status_id=3), недоставляемые получают pickup_only=1. Только DB::table —
 * Eloquent в миграциях валит CI на правах логов.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Переименование колонок дат (native RENAME COLUMN, MySQL 8).
        if (Schema::hasColumn('products', 'stop_list_hidden_at') && !Schema::hasColumn('products', 'hidden_at')) {
            DB::statement('ALTER TABLE products RENAME COLUMN stop_list_hidden_at TO hidden_at');
        }
        if (Schema::hasColumn('products', 'stop_list_pickup_only_at') && !Schema::hasColumn('products', 'pickup_only_at')) {
            DB::statement('ALTER TABLE products RENAME COLUMN stop_list_pickup_only_at TO pickup_only_at');
        }

        // Guard для форков без фичи стоп-листов: создать колонки с нуля.
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'hidden_at')) {
                $table->timestamp('hidden_at')->nullable()->after('pickup_only')
                    ->comment('Дата скрытия товара (переход в статус «не активный»)');
            }
            if (!Schema::hasColumn('products', 'pickup_only_at')) {
                $table->timestamp('pickup_only_at')->nullable()->after('hidden_at')
                    ->comment('Дата включения «только самовывоз»');
            }
        });

        // 2) Merge данных стоп-листов в поля карточки.
        if (Schema::hasColumn('products', 'stop_list_hidden')) {
            // 3 = Status::CODE_NOT_PUBLISHED (константу не тянем в миграцию).
            DB::table('products')->where('stop_list_hidden', true)->update([
                'status_id' => 3,
                'hidden_at' => DB::raw('COALESCE(hidden_at, NOW())'),
            ]);
        }
        if (Schema::hasColumn('products', 'stop_list_pickup_only')) {
            DB::table('products')->where('stop_list_pickup_only', true)->update([
                'pickup_only' => 1,
                'pickup_only_at' => DB::raw('COALESCE(pickup_only_at, NOW())'),
            ]);
        }

        // 3) Удаление флагов.
        Schema::table('products', function (Blueprint $table) {
            foreach (['stop_list_hidden', 'stop_list_pickup_only'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        // Обратный merge невозможен (флаги слиты с полями карточки) —
        // восстанавливаем только структуру с default false.
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'stop_list_hidden')) {
                $table->boolean('stop_list_hidden')->default(false)->after('pickup_only');
            }
            if (!Schema::hasColumn('products', 'stop_list_pickup_only')) {
                $table->boolean('stop_list_pickup_only')->default(false)->after('stop_list_hidden');
            }
        });

        if (Schema::hasColumn('products', 'hidden_at')) {
            DB::statement('ALTER TABLE products RENAME COLUMN hidden_at TO stop_list_hidden_at');
        }
        if (Schema::hasColumn('products', 'pickup_only_at')) {
            DB::statement('ALTER TABLE products RENAME COLUMN pickup_only_at TO stop_list_pickup_only_at');
        }
    }
};
```

- [ ] **Step 5: Накатить миграцию на локальные БД**

```bash
DB_HOST=127.0.0.1 php artisan migrate
# Тестовая БД (см. phpunit.xml, ожидаемо mendev_test):
DB_HOST=127.0.0.1 DB_DATABASE=mendev_test php artisan migrate
```
Expected: `2026_07_16_100000_unify_stop_lists_with_product_card ... DONE` на обеих.

- [ ] **Step 6: Обновить модель Product**

В `app/Models/Product.php`:

1. В `$fillable` заменить четыре строки `'stop_list_hidden'`, `'stop_list_hidden_at'`, `'stop_list_pickup_only'`, `'stop_list_pickup_only_at'` — удалить все четыре, ничего не добавляя (даты пишутся только мутаторами, защита от ручной записи).
2. В `$casts` заменить:

```php
    'stop_list_hidden' => 'boolean',
    'stop_list_hidden_at' => 'datetime',
    'stop_list_pickup_only' => 'boolean',
    'stop_list_pickup_only_at' => 'datetime',
```
на:
```php
    'hidden_at' => 'datetime',
    'pickup_only_at' => 'datetime',
```

3. После метода `boot()` (строка ~102) добавить мутаторы:

```php
  /**
   * Единая точка ведения даты скрытия товара (hidden_at): стоп-лист
   * «не отображать» показывает и сортирует по ней.
   *
   * Мутатор, а не observer: срабатывает на любом пути Eloquent-записи,
   * включая сохранение через withoutEvents() в консьюмерах RabbitMQ.
   * Массовые Query Builder-апдейты (Product::where()->update()) мутаторы
   * обходят — там дату проставлять явно (таких путей для status_id сейчас нет).
   */
  public function setStatusIdAttribute($value): void
  {
    $new = $value !== null ? (int) $value : null;
    $current = isset($this->attributes['status_id']) ? (int) $this->attributes['status_id'] : null;

    if ($new !== $current) {
      $this->attributes['hidden_at'] = $new === Status::CODE_ACTIVE ? null : now();
    }

    $this->attributes['status_id'] = $new;
  }

  /**
   * Дата включения «только самовывоз» (pickup_only_at) — стоп-лист
   * «не доставлять» показывает и сортирует по ней.
   */
  public function setPickupOnlyAttribute($value): void
  {
    $new = (bool) $value;
    $current = (bool) ($this->attributes['pickup_only'] ?? false);

    if ($new !== $current) {
      $this->attributes['pickup_only_at'] = $new ? now() : null;
    }

    $this->attributes['pickup_only'] = $new;
  }
```

`Status` уже используется в файле (boot-хук) — новый импорт не нужен; проверить наличие `use App\Models\Status;` не требуется (тот же namespace).

- [ ] **Step 7: Прогнать тесты — GREEN**

```bash
DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/ProductStopListTest.php
```
Expected: PASS все тесты файла (схема + мутаторы). Задачи 2-4 ещё не сделаны, но их тесты ещё не написаны — файл в этой точке содержит только схему/мутаторы.

- [ ] **Step 8: Commit**

```bash
git rev-parse --abbrev-ref HEAD   # ожидаем feature/stop-lists-unify
git add database/migrations/2026_07_16_100000_unify_stop_lists_with_product_card.php app/Models/Product.php tests/Feature/ProductStopListTest.php
git commit -m "feat: unify stop lists with product card fields (schema + mutators)"
```

---

### Task 2: admin_back — StopListType + query shop_stop_list_products + extend Product

**Files:**
- Modify: `app/Support/StopListType.php` (полная перезапись содержимого класса)
- Modify: `app/GraphQL/Queries/StopList/ShopStopListProducts.php` (полная перезапись builder)
- Modify: `graphql/Product/StopList/StopList.graphql` (полная перезапись)
- Test: `tests/Feature/ProductStopListTest.php` (добавить методы)

**Interfaces:**
- Consumes: колонки `hidden_at`/`pickup_only_at`, мутаторы из Task 1.
- Produces: `StopListType::HIDDEN='hidden'`, `StopListType::PICKUP_ONLY='pickup_only'`, `StopListType::dateColumn(string $type): string` (hidden→hidden_at, pickup_only→pickup_only_at), `StopListType::assertValid(string $type): void`, `StopListType::isValid`, `StopListType::values`. Методы `flagColumn()`/`columns()` УДАЛЕНЫ. GraphQL-поля `Product.hidden_at`, `Product.pickup_only_at` (DateTime). Query `shop_stop_list_products(shop_id, type)`: hidden → неактивные без вариаций, pickup_only → pickup_only=true без вариаций.

- [ ] **Step 1: Добавить тесты query в `tests/Feature/ProductStopListTest.php`**

Добавить перед закрывающей скобкой класса:

```php
    // ==================== Query shop_stop_list_products ====================

    public function test_hidden_list_returns_inactive_products_of_shop(): void
    {
        $shop = $this->createShop();
        $otherShop = $this->createShop();

        $category = Category::create(['title' => 'Cat ' . uniqid(), 'link' => 'cat-' . uniqid()]);

        // Неактивный товар нужного магазина — должен попасть.
        $hidden = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);
        $hidden->categories()->attach($category->id, ['position' => 0]);

        // Активный — не должен.
        $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);

        // pickup_only, но активный — не должен попасть в hidden-выдачу.
        $this->createProduct($shop, [
            'status_id' => Status::CODE_ACTIVE,
            'pickup_only' => true,
        ]);

        // Неактивный, но чужой магазин — не должен.
        $this->createProduct($otherShop, ['status_id' => Status::CODE_NOT_PUBLISHED]);

        // Неактивная ВАРИАЦИЯ configurable-товара — не самостоятельная позиция.
        $parent = $this->createProduct($shop, [
            'status_id' => Status::CODE_ACTIVE,
            'is_configurable' => true,
        ]);
        $this->createProduct($shop, [
            'status_id' => Status::CODE_NOT_PUBLISHED,
            'parent_id' => $parent->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->graphQL(self::LIST_QUERY, ['shop_id' => $shop->id, 'type' => 'hidden']);

        $response->assertJsonPath('data.shop_stop_list_products.paginatorInfo.total', 1);
        $response->assertJsonPath('data.shop_stop_list_products.data.0.id', $hidden->id);
        $response->assertJsonPath('data.shop_stop_list_products.data.0.categories.0.title', $category->title);
        $this->assertNotNull($response->json('data.shop_stop_list_products.data.0.hidden_at'));
    }

    public function test_pickup_only_list_returns_pickup_only_products(): void
    {
        $shop = $this->createShop();

        $pickup = $this->createProduct($shop, [
            'status_id' => Status::CODE_ACTIVE,
            'pickup_only' => true,
        ]);

        // Неактивный, но не pickup_only — не должен попасть.
        $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);

        $response = $this->actingAs($this->admin)
            ->graphQL(self::LIST_QUERY, ['shop_id' => $shop->id, 'type' => 'pickup_only']);

        $response->assertJsonPath('data.shop_stop_list_products.paginatorInfo.total', 1);
        $response->assertJsonPath('data.shop_stop_list_products.data.0.id', $pickup->id);
        $this->assertNotNull($response->json('data.shop_stop_list_products.data.0.pickup_only_at'));
    }

    public function test_hidden_list_sorted_by_hidden_at_desc(): void
    {
        $shop = $this->createShop();

        $older = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);
        DB::table('products')->where('id', $older->id)
            ->update(['hidden_at' => now()->subDays(2)]);

        $newer = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);

        $response = $this->actingAs($this->admin)
            ->graphQL(self::LIST_QUERY, ['shop_id' => $shop->id, 'type' => 'hidden']);

        $response->assertJsonPath('data.shop_stop_list_products.data.0.id', $newer->id);
        $response->assertJsonPath('data.shop_stop_list_products.data.1.id', $older->id);
    }

    public function test_query_rejects_invalid_type(): void
    {
        $shop = $this->createShop();

        $response = $this->actingAs($this->admin)
            ->graphQL(self::LIST_QUERY, ['shop_id' => $shop->id, 'type' => 'bogus']);

        $response->assertJsonPath('errors.0.extensions.category', 'validation');
    }
```

- [ ] **Step 2: Прогнать — RED**

```bash
DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/ProductStopListTest.php
```
Expected: FAIL — GraphQL ругается на поле `hidden_at` (в схеме ещё `stop_list_*`), и builder ещё фильтрует по несуществующей колонке `stop_list_hidden` (SQL error).

- [ ] **Step 3: Перезаписать StopListType**

`app/Support/StopListType.php`, полное содержимое:

```php
<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Стоп-листы магазина — представление полей карточки товара (Platrum 12188):
 *
 * «hidden»       — товары со статусом «не активный» (status_id != CODE_ACTIVE).
 * «pickup_only»  — товары с включённым «только самовывоз» (pickup_only = true).
 *
 * Дата попадания в стоп-лист = hidden_at / pickup_only_at (ведут мутаторы
 * модели Product при смене статуса / самовывоза).
 */
final class StopListType
{
    public const HIDDEN = 'hidden';
    public const PICKUP_ONLY = 'pickup_only';

    /** type => колонка даты попадания в стоп-лист (для выдачи и сортировки) */
    private const DATE_COLUMNS = [
        self::HIDDEN => 'hidden_at',
        self::PICKUP_ONLY => 'pickup_only_at',
    ];

    /** @return string[] */
    public static function values(): array
    {
        return array_keys(self::DATE_COLUMNS);
    }

    public static function isValid(string $type): bool
    {
        return array_key_exists($type, self::DATE_COLUMNS);
    }

    public static function dateColumn(string $type): string
    {
        return self::DATE_COLUMNS[$type];
    }

    /**
     * Валидировать тип; при недопустимом — бросить Lighthouse ValidationException
     * (category=validation, поле type). Фронт понимает только category=validation.
     */
    public static function assertValid(string $type): void
    {
        if (!self::isValid($type)) {
            throw ValidationException::withMessages([
                'type' => ['Недопустимый тип стоп-листа. Допустимо: hidden, pickup_only.'],
            ]);
        }
    }
}
```

- [ ] **Step 4: Перезаписать builder query**

`app/GraphQL/Queries/StopList/ShopStopListProducts.php`, полное содержимое:

```php
<?php

namespace App\GraphQL\Queries\StopList;

use App\Models\Product;
use App\Models\Status;
use App\Support\StopListType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builder для paginate-квери shop_stop_list_products — стоп-листы как
 * представление полей карточки товара:
 *
 * «hidden»      → товары магазина со статусом «не активный» (вкл. NULL);
 * «pickup_only» → товары магазина с pickup_only = true.
 *
 * Вариации configurable-товаров (parent_id NOT NULL) исключаются — они не
 * самостоятельные позиции. Сортировка — по дате попадания в стоп-лист
 * (hidden_at / pickup_only_at), свежие первыми; NULL-даты в конце (MySQL DESC).
 */
class ShopStopListProducts
{
    public function builder($root, array $args): Builder
    {
        $type = (string) $args['type'];
        StopListType::assertValid($type);

        $query = Product::query()
            ->where('shop_id', $args['shop_id'])
            ->whereNull('parent_id');

        if ($type === StopListType::HIDDEN) {
            $query->where(function (Builder $q) {
                $q->where('status_id', '!=', Status::CODE_ACTIVE)
                    ->orWhereNull('status_id');
            });
        } else {
            $query->where('pickup_only', true);
        }

        return $query->orderByDesc(StopListType::dateColumn($type));
    }
}
```

- [ ] **Step 5: Перезаписать extend Product**

`graphql/Product/StopList/StopList.graphql`, полное содержимое:

```graphql
extend type Product {
    "Дата скрытия товара (переход в статус «не активный»); null = товар активен либо скрыт до ведения дат"
    hidden_at: DateTime
    "Дата включения «только самовывоз»; null = обычная доставка"
    pickup_only_at: DateTime
}
```

- [ ] **Step 6: Прогнать — GREEN (тесты Task 1 + Task 2)**

```bash
DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/ProductStopListTest.php
```
Expected: PASS. Если Lighthouse закешировал схему локально: `DB_HOST=127.0.0.1 php artisan lighthouse:clear-cache` и повторить.

- [ ] **Step 7: Commit**

```bash
git rev-parse --abbrev-ref HEAD
git add app/Support/StopListType.php app/GraphQL/Queries/StopList/ShopStopListProducts.php graphql/Product/StopList/StopList.graphql tests/Feature/ProductStopListTest.php
git commit -m "feat: stop list query reads product card fields (status_id / pickup_only)"
```

---

### Task 3: admin_back — мутации ProductStopListAdd/Remove через логику карточки

**Files:**
- Modify: `app/GraphQL/Mutations/StopList/ProductStopListAdd.php` (полная перезапись)
- Modify: `app/GraphQL/Mutations/StopList/ProductStopListRemove.php` (полная перезапись)
- Test: `tests/Feature/ProductStopListTest.php` (добавить методы)

**Interfaces:**
- Consumes: `StopListType` из Task 2; `App\GraphQL\Mutations\Product\ProductHide` / `ProductPublish` (существующие invokable-резолверы карточки: `__invoke($_, ['id' => int])` → `['code'=>200,'message'=>'OK']`); мутаторы дат из Task 1.
- Produces: `ProductStopListAdd/Remove(product_id, type)` — прежняя GraphQL-сигнатура, новая семантика: hidden = деактивация/публикация товара (ProductHistory + событие внутри ProductHide/ProductPublish), pickup_only = тоггл поля + `event(new ProductUpdated($product))`.

- [ ] **Step 1: Добавить тесты мутаций в `tests/Feature/ProductStopListTest.php`**

Добавить перед закрывающей скобкой класса:

```php
    // ==================== Мутации Add/Remove ====================

    public function test_add_hidden_deactivates_product_like_card_toggle(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, [
            'status_id' => Status::CODE_ACTIVE,
            'publish_after_unlock' => 1,
        ]);

        $this->actingAs($this->admin)
            ->graphQL(self::ADD_MUTATION, ['product_id' => $product->id, 'type' => 'hidden'])
            ->assertJsonPath('data.ProductStopListAdd.code', 200);

        $fresh = $product->fresh();
        $this->assertSame(Status::CODE_NOT_PUBLISHED, (int) $fresh->status_id);
        $this->assertSame(0, (int) $fresh->publish_after_unlock);
        $this->assertNotNull($fresh->hidden_at);

        // Паритет с тогглером карточки: смена статуса пишется в историю товара.
        $this->assertTrue(
            ProductHistory::query()
                ->where('product_id', $product->id)
                ->where('action', ProductHistory::ACTION_STATUS_CHANGED)
                ->exists()
        );
    }

    public function test_add_hidden_on_already_inactive_product_is_noop(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);

        $old = now()->subDay()->startOfSecond();
        DB::table('products')->where('id', $product->id)->update(['hidden_at' => $old]);

        $this->actingAs($this->admin)
            ->graphQL(self::ADD_MUTATION, ['product_id' => $product->id, 'type' => 'hidden'])
            ->assertJsonPath('data.ProductStopListAdd.code', 200);

        $fresh = $product->fresh();
        // Дата не сдвинулась, дублирующая запись истории не появилась.
        $this->assertTrue($old->equalTo($fresh->hidden_at));
        $this->assertSame(
            0,
            ProductHistory::query()
                ->where('product_id', $product->id)
                ->where('action', ProductHistory::ACTION_STATUS_CHANGED)
                ->count()
        );
    }

    public function test_remove_hidden_publishes_product(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_NOT_PUBLISHED]);

        $this->actingAs($this->admin)
            ->graphQL(self::REMOVE_MUTATION, ['product_id' => $product->id, 'type' => 'hidden'])
            ->assertJsonPath('data.ProductStopListRemove.code', 200);

        $fresh = $product->fresh();
        $this->assertSame(Status::CODE_ACTIVE, (int) $fresh->status_id);
        $this->assertSame(1, (int) $fresh->publish_after_unlock);
        $this->assertNull($fresh->hidden_at);
        $this->assertTrue(
            ProductHistory::query()
                ->where('product_id', $product->id)
                ->where('action', ProductHistory::ACTION_STATUS_CHANGED)
                ->exists()
        );
    }

    public function test_add_and_remove_pickup_only_toggle_card_field(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);

        $this->actingAs($this->admin)
            ->graphQL(self::ADD_MUTATION, ['product_id' => $product->id, 'type' => 'pickup_only'])
            ->assertJsonPath('data.ProductStopListAdd.code', 200);

        $fresh = $product->fresh();
        $this->assertTrue((bool) $fresh->pickup_only);
        $this->assertNotNull($fresh->pickup_only_at);
        // Статус не задет.
        $this->assertSame(Status::CODE_ACTIVE, (int) $fresh->status_id);

        $this->actingAs($this->admin)
            ->graphQL(self::REMOVE_MUTATION, ['product_id' => $product->id, 'type' => 'pickup_only'])
            ->assertJsonPath('data.ProductStopListRemove.code', 200);

        $fresh = $product->fresh();
        $this->assertFalse((bool) $fresh->pickup_only);
        $this->assertNull($fresh->pickup_only_at);
    }

    public function test_add_dispatches_product_updated_event(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);

        $this->actingAs($this->admin)
            ->graphQL(self::ADD_MUTATION, ['product_id' => $product->id, 'type' => 'hidden'])
            ->assertJsonPath('data.ProductStopListAdd.code', 200);

        // products.updated не слушается пакетом — событие обязано уйти вручную,
        // иначе деактивация не доедет до storefront через outbox.
        Event::assertDispatched(
            ProductUpdated::class,
            fn (ProductUpdated $e) => $e->product->id === $product->id
        );
    }

    public function test_remove_dispatches_product_updated_event(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->createShop();
        $product = $this->createProduct($shop, [
            'status_id' => Status::CODE_ACTIVE,
            'pickup_only' => true,
        ]);

        $this->actingAs($this->admin)
            ->graphQL(self::REMOVE_MUTATION, ['product_id' => $product->id, 'type' => 'pickup_only'])
            ->assertJsonPath('data.ProductStopListRemove.code', 200);

        Event::assertDispatched(
            ProductUpdated::class,
            fn (ProductUpdated $e) => $e->product->id === $product->id
        );
    }

    public function test_add_rejects_invalid_type(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, ['status_id' => Status::CODE_ACTIVE]);

        $response = $this->actingAs($this->admin)
            ->graphQL(self::ADD_MUTATION, ['product_id' => $product->id, 'type' => 'bogus']);

        $response->assertJsonPath('errors.0.extensions.category', 'validation');
        $this->assertSame(Status::CODE_ACTIVE, (int) $product->fresh()->status_id);
    }
```

- [ ] **Step 2: Прогнать — RED**

```bash
DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/ProductStopListTest.php
```
Expected: FAIL — старые мутации пишут в несуществующие колонки `stop_list_*` (SQL error).

- [ ] **Step 3: Перезаписать ProductStopListAdd**

`app/GraphQL/Mutations/StopList/ProductStopListAdd.php`, полное содержимое:

```php
<?php

namespace App\GraphQL\Mutations\StopList;

use App\Events\ProductUpdated;
use App\GraphQL\Mutations\Product\ProductHide;
use App\Models\Product;
use App\Support\StopListType;
use Illuminate\Validation\ValidationException;

/**
 * Добавить товар в стоп-лист магазина — то же действие, что поля карточки:
 *
 * «hidden»       → деактивация товара (делегация в ProductHide: статус,
 *                  publish_after_unlock, ProductHistory, событие в outbox);
 * «pickup_only»  → включение «только самовывоз» (pickup_only = true).
 *
 * Дату попадания в стоп-лист (hidden_at / pickup_only_at) ведут мутаторы
 * модели Product.
 */
final class ProductStopListAdd
{
    /**
     * @param null $_
     * @param array{product_id:int,type:string} $args
     * @return array{code:int,message:string}
     */
    public function __invoke($_, array $args): array
    {
        $type = (string) $args['type'];
        StopListType::assertValid($type);

        $product = Product::find($args['product_id']);
        if (!$product) {
            throw ValidationException::withMessages(['product_id' => ['Товар не найден.']]);
        }

        if ($type === StopListType::HIDDEN) {
            return app(ProductHide::class)(null, ['id' => $product->id]);
        }

        $product->update(['pickup_only' => true]);

        // products.updated НЕ слушается пакетом (config/rabbitmq-events.php: только
        // created/deleted) — публикуем ProductUpdated вручную, как ProductHide/
        // ProductPublish, иначе storefront не пересчитает самовывоз.
        event(new ProductUpdated($product));

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
```

- [ ] **Step 4: Перезаписать ProductStopListRemove**

`app/GraphQL/Mutations/StopList/ProductStopListRemove.php`, полное содержимое:

```php
<?php

namespace App\GraphQL\Mutations\StopList;

use App\Events\ProductUpdated;
use App\GraphQL\Mutations\Product\ProductPublish;
use App\Models\Product;
use App\Support\StopListType;
use Illuminate\Validation\ValidationException;

/**
 * Убрать товар из стоп-листа магазина — то же действие, что поля карточки:
 *
 * «hidden»       → публикация товара (делегация в ProductPublish: статус,
 *                  publish_after_unlock, ProductHistory, событие в outbox);
 * «pickup_only»  → выключение «только самовывоз» (pickup_only = false).
 */
final class ProductStopListRemove
{
    /**
     * @param null $_
     * @param array{product_id:int,type:string} $args
     * @return array{code:int,message:string}
     */
    public function __invoke($_, array $args): array
    {
        $type = (string) $args['type'];
        StopListType::assertValid($type);

        $product = Product::find($args['product_id']);
        if (!$product) {
            throw ValidationException::withMessages(['product_id' => ['Товар не найден.']]);
        }

        if ($type === StopListType::HIDDEN) {
            return app(ProductPublish::class)(null, ['id' => $product->id]);
        }

        $product->update(['pickup_only' => false]);

        // products.updated НЕ слушается пакетом — публикуем вручную (см. Add).
        event(new ProductUpdated($product));

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
```

- [ ] **Step 5: Прогнать — GREEN**

```bash
DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/ProductStopListTest.php
```
Expected: PASS все тесты файла.

- [ ] **Step 6: Commit**

```bash
git rev-parse --abbrev-ref HEAD
git add app/GraphQL/Mutations/StopList/ProductStopListAdd.php app/GraphQL/Mutations/StopList/ProductStopListRemove.php tests/Feature/ProductStopListTest.php
git commit -m "feat: stop list mutations delegate to product card logic (hide/publish, pickup_only)"
```

---

### Task 4: admin_back — событие product.updated без stop_list-полей + финальная проверка репо

**Files:**
- Modify: `app/Transformers/ProductEventTransformer.php:194-197`
- Test: `tests/Feature/ProductStopListTest.php` (добавить метод)

**Interfaces:**
- Consumes: transformer `toEvent()` (payload уже содержит `active` и `pickup_only`).
- Produces: payload `product.updated` БЕЗ ключей `stop_list_hidden` / `stop_list_pickup_only` — контракт для Task 8 (storefront fromEvent).

- [ ] **Step 1: Добавить тест payload в `tests/Feature/ProductStopListTest.php`**

```php
    // ==================== Событие product.updated ====================

    public function test_event_payload_reflects_card_fields_without_stop_list_keys(): void
    {
        $shop = $this->createShop();
        $product = $this->createProduct($shop, [
            'status_id' => Status::CODE_NOT_PUBLISHED,
            'pickup_only' => true,
        ]);

        $payload = (new ProductEventTransformer())->toEvent($product->fresh());

        $this->assertArrayNotHasKey('stop_list_hidden', $payload);
        $this->assertArrayNotHasKey('stop_list_pickup_only', $payload);
        // Объединённая семантика уходит существующими ключами.
        $this->assertFalse($payload['active']);
        $this->assertTrue($payload['pickup_only']);
    }
```

- [ ] **Step 2: Прогнать — RED**

```bash
DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/ProductStopListTest.php
```
Expected: FAIL — `toEvent()` читает `$product->stop_list_hidden` (атрибут отсутствует → null → payload содержит ключ `stop_list_hidden => false`; assertArrayNotHasKey падает).

- [ ] **Step 3: Удалить поля из toEvent**

В `app/Transformers/ProductEventTransformer.php` удалить строки 195-197:

```php
      // Стоп-листы per shop (T3): скрыт на витрине магазина / только самовывоз в магазине.
      'stop_list_hidden' => (bool) $product->stop_list_hidden,
      'stop_list_pickup_only' => (bool) $product->stop_list_pickup_only,
```

(строка `'pickup_only' => (bool) $product->pickup_only,` над ними остаётся).

- [ ] **Step 4: Прогнать весь файл — GREEN**

```bash
DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/ProductStopListTest.php
```
Expected: PASS все тесты (схема, мутаторы, query, мутации, payload).

- [ ] **Step 5: Проверить отсутствие остатков stop_list в admin_back**

```bash
grep -rn "stop_list" app/ graphql/ tests/ config/ | grep -v "shop_stop_lists" | grep -v "shop_stop_list_products"
```
Expected: пусто. Допустимые остатки вне выборки: `shop_stop_lists` (дескриптор модульного тумблера вкладки в `AdminSettingQueries.php` — вкладка остаётся), `shop_stop_list_products` (имя query), историческая миграция `2026_07_10_120000_*` (не трогаем).

- [ ] **Step 6: Смоук смежных тестов (ProductHide/Publish затронуты делегацией и мутаторами)**

```bash
grep -rln "ProductHide\|ProductPublish\|PRODUCT_HIDE" tests/Feature/ | head
```
Каждый найденный файл прогнать по одному: `DB_HOST=127.0.0.1 vendor/bin/phpunit <файл>`.
Expected: PASS (либо падение воспроизводится и на `master` через `git stash` — тогда это pre-existing, зафиксировать в отчёте, не чинить).

- [ ] **Step 7: Commit**

```bash
git rev-parse --abbrev-ref HEAD
git add app/Transformers/ProductEventTransformer.php tests/Feature/ProductStopListTest.php
git commit -m "feat: drop stop_list fields from product.updated payload"
```

---

### Task 5: storefront_back — миграция merge + модель + enforcement

**Files:**
- Create: `database/migrations/2026_07_16_100000_merge_stop_lists_into_product_card_fields.php`
- Modify: `app/Models/Product.php` (fillable ~69-70, casts ~104-106, `scopeActive` ~211, `scopePurchasable` ~277-288, `isPickupOnly` ~480-489)
- Modify: `app/Http/Controllers/Api/V1/ProductController.php:301-306`
- Modify: `app/Services/Cart/AvailableShippingMethodsResolver.php:48-52`
- Modify: `app/Actions/Order/CreateOrderAction.php:156` (только комментарий)
- Create: `tests/Feature/Product/ProductInactiveVisibilityTest.php`
- Delete: `tests/Feature/Product/ProductStopListTest.php`, `tests/Feature/Order/CreateOrderStopListPickupOnlyTest.php`, `tests/Feature/Order/SupplementOrderStopListPickupOnlyTest.php`
- Modify: `tests/Feature/Cart/AvailableShippingMethodsPickupOnlyTest.php` (перенос уникального кейса), Delete: `tests/Feature/Cart/AvailableShippingMethodsStopListTest.php`

**Interfaces:**
- Consumes: существующие `is_active` / `pickup_only` (колонки и синк по событиям уже работают).
- Produces: `scopeActive` = только `is_active`; `isPickupOnly(): bool` = только `(bool) $this->pickup_only` (сигнатура и точки вызова прежние); колонки `stop_list_*` удалены.
- Примечание: сценарии stop-list-дублей уже покрыты legacy-тестами `CreateOrderPickupOnlyRestrictionTest`, `SupplementOrderPickupOnlyRestrictionTest`, `AvailableShippingMethodsPickupOnlyTest` — потому дубли удаляются, а не переписываются.

- [ ] **Step 1: Создать ветку**

```bash
cd ~/PhpstormProjects/mendev/mendev_storefront_back
git checkout main && git pull
git checkout -b feature/stop-lists-unify
```

- [ ] **Step 2: Создать новый тест видимости неактивного товара**

Создать `tests/Feature/Product/ProductInactiveVisibilityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Объединение стоп-листов с полями карточки (Platrum 12188): скрытие с витрины
 * определяет ТОЛЬКО is_active, «только самовывоз» — ТОЛЬКО pickup_only.
 * Флаги stop_list_* удалены.
 */
final class ProductInactiveVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_pickup_only_reflects_pickup_only_field(): void
    {
        $regular = Product::factory()->create(['pickup_only' => false]);
        $pickupOnly = Product::factory()->create(['pickup_only' => true]);

        $this->assertFalse($regular->isPickupOnly());
        $this->assertTrue($pickupOnly->isPickupOnly());
    }

    public function test_scope_active_hides_inactive_product(): void
    {
        $store = Store::factory()->create();

        $visible = Product::factory()->for($store)->create(['is_active' => true]);
        $inactive = Product::factory()->for($store)->create(['is_active' => false]);

        $activeIds = Product::query()->active()->pluck('id')->all();

        $this->assertContains($visible->id, $activeIds);
        $this->assertNotContains($inactive->id, $activeIds);
    }

    public function test_scope_purchasable_excludes_inactive_product(): void
    {
        $store = Store::factory()->create();

        $visible = Product::factory()->for($store)->create([
            'is_active' => true,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $inactive = Product::factory()->for($store)->create([
            'is_active' => false,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $purchasableIds = Product::query()->purchasable()->pluck('id')->all();

        $this->assertContains($visible->id, $purchasableIds);
        $this->assertNotContains($inactive->id, $purchasableIds);
    }

    public function test_product_detail_404_for_inactive_product(): void
    {
        $store = Store::factory()->create();

        $inactive = Product::factory()->for($store)->create([
            'is_active' => false,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $this->getJson("/api/v1/products/{$inactive->id}")->assertNotFound();
    }

    public function test_product_detail_available_for_active_product(): void
    {
        $store = Store::factory()->create();

        $visible = Product::factory()->for($store)->create([
            'is_active' => true,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $this->getJson("/api/v1/products/{$visible->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $visible->id);
    }

    public function test_category_listing_excludes_inactive_product(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->create();

        $visible = Product::factory()->for($store)->create([
            'is_active' => true,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $inactive = Product::factory()->for($store)->create([
            'is_active' => false,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $visible->categories()->attach($category->id, ['position' => 0]);
        $inactive->categories()->attach($category->id, ['position' => 1]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/{$category->id}/products");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }
}
```

- [ ] **Step 3: Удалить дубли и старый тест-файл, перенести уникальный кейс**

```bash
rm tests/Feature/Product/ProductStopListTest.php
rm tests/Feature/Order/CreateOrderStopListPickupOnlyTest.php
rm tests/Feature/Order/SupplementOrderStopListPickupOnlyTest.php
```

В `tests/Feature/Cart/AvailableShippingMethodsPickupOnlyTest.php` добавить перед закрывающей скобкой класса перенесённый кейс (из удаляемого `AvailableShippingMethodsStopListTest`, фикстура на `pickup_only`; проверить наличие импортов `Cart`, `CartItem`, `User`, `AvailableShippingMethodsResolver` — в legacy-файле они уже есть):

```php
    public function test_courier_unavailable_when_items_relation_preloaded_without_product(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'pickup_only' => true,
        ]);

        $user = User::factory()->create();
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        // `items` предзагружено, `items.product` — нет: ровно та ветка, где
        // loadMissing('product:id,pickup_only') должен подтянуть колонку.
        // Без неё isPickupOnly() увидит null → false.
        $preloadedCart = Cart::with('items')->find($cart->id);

        $methods = app(AvailableShippingMethodsResolver::class)->resolve($preloadedCart);
        $byMethod = collect($methods)->keyBy('method');

        $this->assertFalse($byMethod['own_courier']['available']);
        $this->assertTrue($byMethod['pickup']['available']);
    }
```

Затем:

```bash
rm tests/Feature/Cart/AvailableShippingMethodsStopListTest.php
```

- [ ] **Step 4: Написать миграцию**

Создать `database/migrations/2026_07_16_100000_merge_stop_lists_into_product_card_fields.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Объединение стоп-листов с полями карточки товара (Platrum 12188):
 * merge локальных флагов в is_active / pickup_only, затем удаление колонок.
 * Локальный merge обязателен: admin_back мержит свои данные прямым SQL и
 * событий не публикует; флаги здесь были синканы событиями ранее и консистентны.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'stop_list_hidden')) {
            DB::table('products')->where('stop_list_hidden', true)->update(['is_active' => false]);
        }
        if (Schema::hasColumn('products', 'stop_list_pickup_only')) {
            DB::table('products')->where('stop_list_pickup_only', true)->update(['pickup_only' => true]);
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['stop_list_hidden', 'stop_list_pickup_only'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        // Обратный merge невозможен — восстанавливаем только структуру.
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'stop_list_hidden')) {
                $table->boolean('stop_list_hidden')->default(false)->after('pickup_only');
            }
            if (!Schema::hasColumn('products', 'stop_list_pickup_only')) {
                $table->boolean('stop_list_pickup_only')->default(false)->after('stop_list_hidden');
            }
        });
    }
};
```

- [ ] **Step 5: Прогнать новый тест — RED**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=ProductInactiveVisibilityTest
```
Expected: FAIL — после миграции колонок `stop_list_*` нет, а `scopeActive`/`scopePurchasable` ещё ссылаются на `stop_list_hidden` (SQLSTATE no such column).

- [ ] **Step 6: Упростить модель Product**

В `app/Models/Product.php`:

1. Из `$fillable` удалить строки `'stop_list_hidden',` и `'stop_list_pickup_only',`.
2. Из `casts()` удалить строки `'stop_list_hidden' => 'boolean',` и `'stop_list_pickup_only' => 'boolean',`.
3. `scopeActive` привести к:

```php
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
```

4. В `scopePurchasable` удалить строки:

```php
            // Стоп-лист «Не отображать» (T8): товар полностью скрыт с витрины —
            // карточка /products/{id} и листинг категории обязаны его скрывать
            // (согласовано team-lead, расширение брифа поверх scopeActive).
            ->where("{$table}.stop_list_hidden", false)
```

5. `isPickupOnly()` привести к (docblock над методом заменить целиком):

```php
    /**
     * Товар доступен только для самовывоза (поле карточки `pickup_only`;
     * per-shop смысл обеспечивает сама модель данных — товары per store).
     */
    public function isPickupOnly(): bool
    {
        return (bool) $this->pickup_only;
    }
```

- [ ] **Step 7: Упростить контроллер и резолвер доставки**

1. В `app/Http/Controllers/Api/V1/ProductController.php::show()` удалить блок:

```php
        // Стоп-лист «Не отображать» (T8): товар полностью скрыт с витрины —
        // карточка недоступна по прямой ссылке. show() резолвит товар через
        // route-model binding (мимо scopePurchasable), поэтому флаг проверяем явно.
        if ($product->stop_list_hidden) {
            abort(404);
        }
```

(первый `if (! $product->is_active) { abort(404); }` остаётся).

2. В `app/Services/Cart/AvailableShippingMethodsResolver.php` заменить:

```php
            // stop_list_pickup_only обязателен в выборке: isPickupOnly() читает оба
            // флага, без колонки в select он увидел бы null и «Не доставлять» не сработал.
            $items->loadMissing('product:id,pickup_only,stop_list_pickup_only');
```
на:
```php
            // pickup_only обязателен в выборке: без колонки в select
            // isPickupOnly() увидел бы null и «Не доставлять» не сработал.
            $items->loadMissing('product:id,pickup_only');
```

3. В `app/Actions/Order/CreateOrderAction.php` строка 156: в комментарии убрать упоминание `stop_list_pickup_only` (заменить на «поле карточки pickup_only»); сам вызов `isPickupOnly()` не трогать.

- [ ] **Step 8: Прогнать тесты — GREEN**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=ProductInactiveVisibilityTest
/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=AvailableShippingMethodsPickupOnlyTest
/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=CreateOrderPickupOnlyRestrictionTest
/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=SupplementOrderPickupOnlyRestrictionTest
```
Expected: PASS все четыре.

- [ ] **Step 9: Commit**

```bash
git rev-parse --abbrev-ref HEAD
git add -A database/migrations app/Models/Product.php app/Http/Controllers/Api/V1/ProductController.php app/Services/Cart/AvailableShippingMethodsResolver.php app/Actions/Order/CreateOrderAction.php tests/
git commit -m "feat: merge stop lists into product card fields (is_active / pickup_only)"
```

---

### Task 6: storefront_back — Meilisearch без stop_list_hidden

**Files:**
- Modify: `app/Services/Search/SearchService.php` (~53: visibilityFilters; ~638: документ)
- Modify: `config/meilisearch.php` (~81: filterableAttributes)
- Create: `tests/Feature/Search/InactiveProductSearchTest.php`
- Delete: `tests/Feature/Search/StopListHiddenSearchTest.php`

**Interfaces:**
- Consumes: `is_active` уже в документе и в `filterableAttributes`.
- Produces: `visibilityFilters()` = `['is_active = true', 'price > 0']` (+ модульные условия как были); документ Meili без ключа `stop_list_hidden`.

- [ ] **Step 1: Создать новый search-тест, удалить старый**

Создать `tests/Feature/Search/InactiveProductSearchTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Product;
use App\Models\Store;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Объединение стоп-листов с полями карточки (Platrum 12188): видимость в
 * поиске определяет ТОЛЬКО is_active; поля stop_list_hidden больше нет
 * ни в документе, ни в visibilityFilters.
 */
final class InactiveProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private FakeSearchClient $searchClient;

    private SearchService $searchService;

    protected function setUp(): void
    {
        parent::setUp();

        Product::unsetEventDispatcher();

        $this->searchClient = $this->app->make(SearchClientInterface::class);
        $this->searchService = $this->app->make(SearchService::class);
        $this->searchClient->resetIndex();
    }

    public function test_document_omits_stop_list_hidden_field(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create([
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $this->searchService->indexProduct($product);

        $doc = $this->searchClient->indexedDocument($product->id);

        $this->assertNotNull($doc);
        $this->assertArrayNotHasKey('stop_list_hidden', $doc);
        $this->assertArrayHasKey('is_active', $doc);
    }

    public function test_search_hides_inactive_product(): void
    {
        $store = Store::factory()->create();

        $inactive = Product::factory()->for($store)->create([
            'title' => 'Пельмени Скрытые',
            'quantity' => 10,
            'quantity_step' => 1,
            'is_active' => false,
        ]);

        $this->searchService->indexProduct($inactive);

        $query = urlencode('Пельмени');
        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query={$query}");

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_returns_visible_product(): void
    {
        $store = Store::factory()->create();

        $visible = Product::factory()->for($store)->create([
            'title' => 'Пельмени Видимые',
            'quantity' => 10,
            'quantity_step' => 1,
            'is_active' => true,
        ]);

        $this->searchService->indexProduct($visible);

        $query = urlencode('Пельмени');
        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query={$query}");

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }
}
```

```bash
rm tests/Feature/Search/StopListHiddenSearchTest.php
```

- [ ] **Step 2: Прогнать — RED**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=InactiveProductSearchTest
```
Expected: FAIL — `test_document_omits_stop_list_hidden_field` (документ ещё содержит `stop_list_hidden`; после Task 5 значение атрибута null → `(bool) null = false`, но ключ присутствует).

- [ ] **Step 3: Убрать stop_list_hidden из SearchService и config**

1. В `app/Services/Search/SearchService.php::visibilityFilters()` заменить:

```php
        // stop_list_hidden = false — стоп-лист «Не отображать» (T8): товар полностью
        // скрыт с витрины магазина. Синхронно со {@see Product::scopeActive()}.
        $filters = ['is_active = true', 'price > 0', 'stop_list_hidden = false'];
```
на:
```php
        $filters = ['is_active = true', 'price > 0'];
```

2. Там же в сборке документа (~строка 638) удалить:

```php
            // Стоп-лист «Не отображать» (T8): фильтруется в visibilityFilters.
            // Жёсткий каст — Meilisearch фильтрует по точному типу, а на свежих
            // моделях default-колонка может вернуть null.
            'stop_list_hidden' => (bool) $product->stop_list_hidden,
```

3. В `config/meilisearch.php` из `filterableAttributes` удалить строку `'stop_list_hidden',`.

- [ ] **Step 4: Прогнать — GREEN**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=InactiveProductSearchTest
/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=SearchServiceBulkIndexTest
```
Expected: PASS оба.

- [ ] **Step 5: Commit**

```bash
git rev-parse --abbrev-ref HEAD
git add app/Services/Search/SearchService.php config/meilisearch.php tests/Feature/Search/
git commit -m "feat: drop stop_list_hidden from Meilisearch document and filters"
```

---

### Task 7: storefront_back — consumer без stop_list-маппинга + регрессия репо

**Files:**
- Modify: `app/RabbitMQ/Transformers/ProductEventTransformer.php:186-195`
- Delete: `tests/Feature/RabbitMQ/ProductEventHandlerStopListTest.php`

**Interfaces:**
- Consumes: контракт Task 4 — payload `product.updated` без ключей `stop_list_*`; маппинги `active`→`is_active` и `pickup_only` уже существуют и не меняются.
- Produces: `fromEvent()` без стоп-лист-блока. Conditional-set для `pickup_only`/`is_active` покрыт существующим `ProductEventHandlerPickupOnlyTest`.

- [ ] **Step 1: Удалить маппинг и тест**

В `app/RabbitMQ/Transformers/ProductEventTransformer.php::fromEvent()` удалить блок:

```php
        // Стоп-листы per shop (T8): «Не отображать» (stop_list_hidden) и
        // «Не доставлять» (stop_list_pickup_only). Приходят из admin_back в полном
        // событии product.updated. Conditional set: партиальные/чужие события
        // (1С, retailbridge) без ключей не затирают локальные флаги.
        if (array_key_exists('stop_list_hidden', $data)) {
            $attributes['stop_list_hidden'] = (bool) $data['stop_list_hidden'];
        }
        if (array_key_exists('stop_list_pickup_only', $data)) {
            $attributes['stop_list_pickup_only'] = (bool) $data['stop_list_pickup_only'];
        }
```

```bash
rm tests/Feature/RabbitMQ/ProductEventHandlerStopListTest.php
```

- [ ] **Step 2: Прогнать consumer-тесты**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=ProductEventHandlerPickupOnlyTest
/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=ProductEventHandler
```
Expected: PASS (кроме pre-existing падений, см. Step 3).

- [ ] **Step 3: Полная регрессия storefront + grep**

```bash
/opt/homebrew/opt/php@8.4/bin/php artisan test
grep -rn "stop_list" app/ config/ tests/ database/ | grep -v "2026_07_10_120000"
```
Expected: grep пуст (историческая миграция 2026-07-10 остаётся). Тесты: зелёные, КРОМЕ известных pre-existing падений (из памяти проекта: `OrderPublisherTest` ×2, `CreateClientOrder*` ×8, `SearchProductsTest > filter by category`, `ProductGroupEventHandlerTest`, `ProductReviewEventHandlerTest`). Любое НОВОЕ падение сверить с `main` через `git stash` — чинить только своё.

- [ ] **Step 4: Commit**

```bash
git rev-parse --abbrev-ref HEAD
git add app/RabbitMQ/Transformers/ProductEventTransformer.php tests/
git commit -m "feat: drop stop_list mapping from product event consumer"
```

---

### Task 8: admin_front — поля дат стоп-листов из карточки товара

**Files:**
- Modify: `src/graphql/pages/Stores/shop_stop_list_products.graphql:20-21`
- Modify: `src/views/stores/tabs/StopListsTab.vue:9,18`

**Interfaces:**
- Consumes: GraphQL-поля `Product.hidden_at` / `Product.pickup_only_at` из Task 2; query/мутации стоп-листов с прежними сигнатурами.
- Produces: вкладка «Стоп-листы» работает поверх объединённых полей; UI не меняется. `StopListSection.formatDate` уже обрабатывает null (`if (!value) return "—"`) — товары, скрытые до ведения дат, показывают прочерк.

- [ ] **Step 1: Создать ветку**

```bash
cd ~/PhpstormProjects/mendev/mendev_admin_front
git checkout master && git pull
git checkout -b feature/stop-lists-unify
```

- [ ] **Step 2: Обновить graphql-файл**

В `src/graphql/pages/Stores/shop_stop_list_products.graphql` заменить строки:

```graphql
      stop_list_hidden_at
      stop_list_pickup_only_at
```
на:
```graphql
      hidden_at
      pickup_only_at
```

- [ ] **Step 3: Обновить date-field пропсы**

В `src/views/stores/tabs/StopListsTab.vue`:
- строка 9: `date-field="stop_list_hidden_at"` → `date-field="hidden_at"`
- строка 18: `date-field="stop_list_pickup_only_at"` → `date-field="pickup_only_at"`

- [ ] **Step 4: Проверить отсутствие остатков и собрать**

```bash
grep -rn "stop_list_hidden_at\|stop_list_pickup_only_at\|stop_list_hidden\b\|stop_list_pickup_only\b" src/
npm run build
```
Expected: grep пуст (остаётся только `shop_stop_lists` — ключ модульного тумблера в `moduleConfig.js` и `open.vue`, и `shop_stop_list_products` — имя query). Build чистый.

- [ ] **Step 5: Commit**

```bash
git rev-parse --abbrev-ref HEAD
git add src/graphql/pages/Stores/shop_stop_list_products.graphql src/views/stores/tabs/StopListsTab.vue
git commit -m "feat: stop list tabs read unified card dates (hidden_at / pickup_only_at)"
```

---

### Task 9: Финальная верификация трёх репо

**Files:** нет новых правок (только прогоны; фиксы — если найдено своё падение).

**Interfaces:**
- Consumes: все предыдущие задачи.
- Produces: зелёный локальный статус трёх веток `feature/stop-lists-unify`, готовность к merge/push по команде пользователя.

- [ ] **Step 1: admin_back — полный файл фичи + смежные**

```bash
cd ~/PhpstormProjects/mendev/mendev_admin_back
DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/ProductStopListTest.php
```
Expected: PASS. Известный pre-existing флейк репо: `OrderItemStatusMappingTest` ×2 — не связан, не чинить.

- [ ] **Step 2: storefront_back — целевые сюиты**

```bash
cd ~/PhpstormProjects/mendev/mendev_storefront_back
/opt/homebrew/opt/php@8.4/bin/php artisan test --filter='ProductInactiveVisibilityTest|InactiveProductSearchTest|AvailableShippingMethodsPickupOnlyTest|CreateOrderPickupOnlyRestrictionTest|SupplementOrderPickupOnlyRestrictionTest|ProductEventHandlerPickupOnlyTest'
```
Expected: PASS все.

- [ ] **Step 3: admin_front — build**

```bash
cd ~/PhpstormProjects/mendev/mendev_admin_front && npm run build
```
Expected: exit 0.

- [ ] **Step 4: Сводка для пользователя**

Отчитаться: ветки/коммиты по трём репо, итоги тестов (с списком pre-existing падений), напоминание деплой-нот из Global Constraints (merge --no-ff → push → CI → lighthouse:clear-cache → search:reindex → двусторонняя верификация на dev: счётчики merge-миграций, вкладка «Стоп-листы» ↔ тогглер карточки, скрытие товара с витрины, pickup_only-ограничение доставки). Merge и push — ТОЛЬКО по явной команде.
