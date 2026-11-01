# Предпросмотр учёта остатков категории при создании товара — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** В блоке «Учёт остатков» карточки простого товара (`open.vue`) показывать реальный эффективный режим, унаследованный от выбранной категории, ТАКЖЕ при создании нового товара (сейчас — только при редактировании существующего).

**Architecture:** Бэкенд (`aladdin_admin_back`): вынести climb-логику из `StockOverrideService::resolve(Product)` в новый публичный метод `resolveForPreview(array $categoryIds, ?int $shopId, ?int $unitTypeId): array`, не зависящий от сохранённого товара; выставить новый GraphQL root-query `effective_stock_preview` поверх него. Фронтенд (`aladdin_admin_front`): расширить уже существующий watcher на выбранные категории (он и так работает в обоих режимах) + добавить watcher на единицу измерения, дёргающие новый query и кладущие результат в существующие `effective_stock_mode`/`effective_fixed_stock` (плюс новое поле единицы измерения лимита).

**Tech Stack:** Laravel 9 + PHP 8.1/8.2, Lighthouse GraphQL (backend); Vue 3 Options API + Apollo Client (frontend).

## Global Constraints

- Показывается только `effective_stock_mode`/`effective_fixed_stock` (+ единица измерения лимита) — read-only, никакого нового input.
- `configurable.vue` (вариативный товар) не трогаем — там блока «Учёт остатков» нет вообще.
- `resolve(Product $product)` — поведение для СУЩЕСТВУЮЩИХ товаров не меняется (regression-safe рефакторинг); это проверяется прогоном уже существующих 11 тестов в `tests/Feature/Stock/StockOverrideTest.php` без единого изменения в них.
- Backend-тесты — **только локально**, командой `DB_DATABASE=aladdin_admin_test php artisan test --filter=<Test>` (документировано в `~/PhpstormProjects/aladdin/learnings.md`, строка 380). НЕ гонять `RefreshDatabase`/`migrate:fresh`, только `DatabaseTransactions` (уже используется в существующих тестах). НЕ гонять тесты на сервере/в поде.
- Frontend (`aladdin_admin_front`) без юнит-тестов для Vue-компонентов — верификация `npm run build`. **НЕ запускать `npm run lint`** — в этом проекте это `eslint . --fix`, мутирующий файлы по всему репозиторию (уже ловили это в предыдущей задаче).
- GraphQL-изменения бэкенда после деплоя требуют `lighthouse:clear-cache` на сервере (см. `~/PhpstormProjects/aladdin/CLAUDE.md`, раздел Lighthouse cache) — не забыть на этапе финального деплоя (вне scope этого плана, будет сделано при `/push`).

---

### Task 1: Backend — вынести climb-логику в `resolveForPreview()`

**Files:**
- Modify: `aladdin_docker/aladdin_admin_back/app/Services/Stock/StockOverrideService.php`
- Modify: `aladdin_docker/aladdin_admin_back/tests/Feature/Stock/StockOverrideTest.php`

**Interfaces:**
- Produces: `StockOverrideService::resolveForPreview(array $categoryIds, ?int $shopId, ?int $unitTypeId): array` возвращает `['mode' => string, 'fixed' => ?int, 'fixed_unit_type_id' => ?int]`. Используется в Task 2 GraphQL-резолвером.
- Consumes: существующие `App\Models\Category`, `App\Models\Shop`, `App\Services\Quantum\UnitFamily`, `App\Services\Stock\StockMode` — без изменений их публичного API.

Работать из директории: `/Users/daibelbuzarov/PhpstormProjects/aladdin/aladdin_docker/aladdin_admin_back`

- [ ] **Step 1: Запустить существующие тесты (baseline)**

Run: `DB_DATABASE=aladdin_admin_test php artisan test --filter=StockOverrideTest`
Expected: `Tests: 11 passed`

- [ ] **Step 2: Написать новые тесты для `resolveForPreview` (RED)**

Добавить в конец класса `StockOverrideTest` (перед закрывающей `}` файла), после метода `test_recalc_category_covers_subcategories`:

```php
    public function test_preview_returns_fixed_mode_from_category(): void
    {
        $cat = Category::forceCreate([
            'title' => 'C', 'link' => 'preview-fixed',
            'stock_mode' => 'fixed', 'fixed_stock_quantity' => 7,
            'fixed_stock_unit_type_id' => Type::INFO_WEIGHT_PIECE,
        ]);

        $result = app(StockOverrideService::class)->resolveForPreview(
            [$cat->id], null, Type::INFO_WEIGHT_PIECE
        );

        $this->assertSame('fixed', $result['mode']);
        $this->assertSame(7, $result['fixed']);
        $this->assertSame(Type::INFO_WEIGHT_PIECE, $result['fixed_unit_type_id']);
    }

    public function test_preview_returns_ignore_from_category(): void
    {
        $cat = Category::forceCreate(['title' => 'C', 'link' => 'preview-ignore', 'stock_mode' => 'ignore']);

        $result = app(StockOverrideService::class)->resolveForPreview([$cat->id], null, null);

        $this->assertSame('ignore', $result['mode']);
        $this->assertNull($result['fixed']);
        $this->assertNull($result['fixed_unit_type_id']);
    }

    public function test_preview_inherits_from_parent_category(): void
    {
        $parent = Category::forceCreate(['title' => 'P', 'link' => 'preview-parent', 'stock_mode' => 'ignore']);
        $child = Category::forceCreate(['title' => 'Ch', 'link' => 'preview-child', 'parent_id' => $parent->id, 'stock_mode' => 'normal']);

        $result = app(StockOverrideService::class)->resolveForPreview([$child->id], null, null);

        $this->assertSame('ignore', $result['mode']);
    }

    public function test_preview_skips_fixed_on_unit_family_mismatch(): void
    {
        $cat = Category::forceCreate([
            'title' => 'C', 'link' => 'preview-mismatch',
            'stock_mode' => 'fixed', 'fixed_stock_quantity' => 3,
            'fixed_stock_unit_type_id' => Type::INFO_WEIGHT_PIECE,
        ]);

        // unit_type_id = грамм (masса) не совпадает с fixed-категорией (шт)
        $result = app(StockOverrideService::class)->resolveForPreview(
            [$cat->id], null, Type::INFO_WEIGHT_GRAM
        );

        $this->assertSame('normal', $result['mode']);
    }

    public function test_preview_falls_back_to_shop_ignore_stock(): void
    {
        $shop = $this->shop(true);

        $result = app(StockOverrideService::class)->resolveForPreview([], $shop->id, Type::INFO_WEIGHT_PIECE);

        $this->assertSame('ignore', $result['mode']);
    }

    public function test_preview_defaults_to_normal_with_no_categories_or_shop(): void
    {
        $result = app(StockOverrideService::class)->resolveForPreview([], null, null);

        $this->assertSame('normal', $result['mode']);
        $this->assertNull($result['fixed']);
    }
```

- [ ] **Step 3: Запустить новые тесты — убедиться, что падают (метод ещё не существует)**

Run: `DB_DATABASE=aladdin_admin_test php artisan test --filter=StockOverrideTest`
Expected: FAIL — `Call to undefined method App\Services\Stock\StockOverrideService::resolveForPreview()`

- [ ] **Step 4: Отрефакторить `StockOverrideService.php`**

Найти:
```php
    public function resolve(Product $product): array
    {
        // 1. Товар — явный режим (ignore/fixed) приоритетнее всего.
        if ($product->stock_mode === StockMode::IGNORE) {
            return ['mode' => StockMode::IGNORE, 'fixed' => null];
        }
        if ($product->stock_mode === StockMode::FIXED) {
            return ['mode' => StockMode::FIXED, 'fixed' => $this->productFixedInBaseUnit($product)];
        }

        // 2. Категории товара (с наследованием от родителей).
        $fromCategory = $this->resolveFromCategories($product);
        if ($fromCategory !== null) {
            return $fromCategory;
        }

        // 3. Магазин.
        $shop = Shop::find($product->shop_id);
        if ($shop && $shop->ignore_stock) {
            return ['mode' => StockMode::IGNORE, 'fixed' => null];
        }

        // 4. Стандарт.
        return ['mode' => StockMode::NORMAL, 'fixed' => null];
    }
```

Заменить на:
```php
    public function resolve(Product $product): array
    {
        // 1. Товар — явный режим (ignore/fixed) приоритетнее всего.
        if ($product->stock_mode === StockMode::IGNORE) {
            return ['mode' => StockMode::IGNORE, 'fixed' => null];
        }
        if ($product->stock_mode === StockMode::FIXED) {
            return ['mode' => StockMode::FIXED, 'fixed' => $this->productFixedInBaseUnit($product)];
        }

        // 2-4. Категории → магазин → стандарт (общая логика с предпросмотром
        // ещё не сохранённого товара, см. resolveForPreview()).
        return $this->resolveForPreview(
            $product->categories()->pluck('categories.id')->all(),
            $product->shop_id,
            $product->base_unit_type_id ?? $product->info_weight_type_id
        );
    }

    /**
     * Предпросмотр эффективного режима остатков ДО сохранения товара:
     * категории (с наследованием от родителей) → shops.ignore_stock → normal.
     * Не учитывает собственный stock_mode товара — для ещё несохранённого
     * товара его не существует (используется только для незаданного/normal
     * состояния, откуда и вызывается resolve()).
     *
     * @param int[] $categoryIds
     * @return array{mode: string, fixed: ?int, fixed_unit_type_id: ?int}
     */
    public function resolveForPreview(array $categoryIds, ?int $shopId, ?int $unitTypeId): array
    {
        $fromCategory = $this->resolveFromCategoryIds($categoryIds, $unitTypeId);
        if ($fromCategory !== null) {
            return $fromCategory;
        }

        $shop = $shopId ? Shop::find($shopId) : null;
        if ($shop && $shop->ignore_stock) {
            return ['mode' => StockMode::IGNORE, 'fixed' => null, 'fixed_unit_type_id' => null];
        }

        return ['mode' => StockMode::NORMAL, 'fixed' => null, 'fixed_unit_type_id' => null];
    }
```

Найти:
```php
    /**
     * Режим из витринных категорий товара с наследованием от родителей.
     * Среди найденных: fixed строже ignore; при нескольких fixed — минимальный лимит.
     * fixed применяется только к товарам совпадающего семейства.
     *
     * @return array{mode: string, fixed: ?int}|null
     */
    private function resolveFromCategories(Product $product): ?array
    {
        $family = UnitFamily::family($product->base_unit_type_id ?? $product->info_weight_type_id);

        $best = null; // ['mode'=>..., 'fixed'=>...]
        foreach ($product->categories()->get() as $category) {
            $node = $this->climbToConfigured($category);
            if ($node === null) {
                continue;
            }

            if ($node->stock_mode === StockMode::FIXED) {
                // fixed только для совпадающего семейства.
                $unitFamily = UnitFamily::family($node->fixed_stock_unit_type_id);
                if ($family === null || $unitFamily !== $family) {
                    continue;
                }
                $fixed = (int) ($node->fixed_stock_quantity ?? 0) * UnitFamily::sizeInSmallest($node->fixed_stock_unit_type_id);
                if ($fixed <= 0) {
                    continue;
                }
                if ($best === null || $best['mode'] !== StockMode::FIXED || $fixed < $best['fixed']) {
                    $best = ['mode' => StockMode::FIXED, 'fixed' => $fixed];
                }
            } elseif ($node->stock_mode === StockMode::IGNORE) {
                if ($best === null) {
                    $best = ['mode' => StockMode::IGNORE, 'fixed' => null];
                }
            }
        }

        return $best;
    }
```

Заменить на:
```php
    /**
     * Режим из категорий (climb по дереву от каждой к первому предку с
     * stock_mode != normal). Среди найденных: fixed строже ignore; при
     * нескольких fixed — минимальный лимит. fixed применяется только при
     * совпадении unit-семейства (масса/объём/шт, см. UnitFamily).
     *
     * @param int[] $categoryIds
     * @return array{mode: string, fixed: ?int, fixed_unit_type_id: ?int}|null
     */
    private function resolveFromCategoryIds(array $categoryIds, ?int $unitTypeId): ?array
    {
        $family = UnitFamily::family($unitTypeId);

        $best = null; // ['mode'=>..., 'fixed'=>..., 'fixed_unit_type_id'=>...]
        foreach ($categoryIds as $categoryId) {
            $category = Category::find($categoryId);
            if ($category === null) {
                continue;
            }
            $node = $this->climbToConfigured($category);
            if ($node === null) {
                continue;
            }

            if ($node->stock_mode === StockMode::FIXED) {
                // fixed только для совпадающего семейства.
                $unitFamily = UnitFamily::family($node->fixed_stock_unit_type_id);
                if ($family === null || $unitFamily !== $family) {
                    continue;
                }
                $fixed = (int) ($node->fixed_stock_quantity ?? 0) * UnitFamily::sizeInSmallest($node->fixed_stock_unit_type_id);
                if ($fixed <= 0) {
                    continue;
                }
                if ($best === null || $best['mode'] !== StockMode::FIXED || $fixed < $best['fixed']) {
                    $best = ['mode' => StockMode::FIXED, 'fixed' => $fixed, 'fixed_unit_type_id' => UnitFamily::smallestUnit($family)];
                }
            } elseif ($node->stock_mode === StockMode::IGNORE) {
                if ($best === null) {
                    $best = ['mode' => StockMode::IGNORE, 'fixed' => null, 'fixed_unit_type_id' => null];
                }
            }
        }

        return $best;
    }
```

- [ ] **Step 5: Запустить все тесты файла — старые (regression) и новые должны пройти (GREEN)**

Run: `DB_DATABASE=aladdin_admin_test php artisan test --filter=StockOverrideTest`
Expected: `Tests: 17 passed` (11 существующих без изменений + 6 новых)

- [ ] **Step 6: Commit**

```bash
git add app/Services/Stock/StockOverrideService.php tests/Feature/Stock/StockOverrideTest.php
git commit -m "refactor: extract category-only stock resolution into resolveForPreview()"
```

---

### Task 2: Backend — GraphQL query `effective_stock_preview`

**Files:**
- Create: `aladdin_docker/aladdin_admin_back/app/GraphQL/Queries/EffectiveStockPreview.php`
- Create: `aladdin_docker/aladdin_admin_back/graphql/Product/Stock/StockQueries.graphql`
- Modify: `aladdin_docker/aladdin_admin_back/graphql/schema.graphql`
- Create: `aladdin_docker/aladdin_admin_back/tests/Feature/EffectiveStockPreviewTest.php`

**Interfaces:**
- Consumes: `App\Services\Stock\StockOverrideService::resolveForPreview(array $categoryIds, ?int $shopId, ?int $unitTypeId): array` из Task 1.
- Produces: GraphQL query `effective_stock_preview(category_ids: [Int!], shop_id: Int, unit_type_id: Int): EffectiveStockPreview` с полями `mode: String!`, `fixed: Int`, `fixed_unit_type_id: Int`. Используется фронтендом в Task 3.

Работать из директории: `/Users/daibelbuzarov/PhpstormProjects/aladdin/aladdin_docker/aladdin_admin_back`

- [ ] **Step 1: Написать GraphQL feature-тест (RED)**

Создать файл `tests/Feature/EffectiveStockPreviewTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class EffectiveStockPreviewTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;

    protected function setUp(): void
    {
        $prop = new \ReflectionProperty(
            \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class,
            'observersRegistered'
        );
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();
    }

    private const PREVIEW_QUERY = '
        query ($category_ids: [Int!], $shop_id: Int, $unit_type_id: Int) {
            effective_stock_preview(category_ids: $category_ids, shop_id: $shop_id, unit_type_id: $unit_type_id) {
                mode
                fixed
                fixed_unit_type_id
            }
        }
    ';

    /** @test */
    public function it_returns_fixed_mode_from_category()
    {
        $cat = Category::forceCreate([
            'title' => 'C', 'link' => 'preview-gql-fixed',
            'stock_mode' => 'fixed', 'fixed_stock_quantity' => 7,
            'fixed_stock_unit_type_id' => Type::INFO_WEIGHT_PIECE,
        ]);

        $this->actingAs($this->adminUser)
            ->graphQL(self::PREVIEW_QUERY, [
                'category_ids' => [$cat->id],
                'unit_type_id' => Type::INFO_WEIGHT_PIECE,
            ])
            ->assertJson([
                'data' => [
                    'effective_stock_preview' => [
                        'mode' => 'fixed',
                        'fixed' => 7,
                        'fixed_unit_type_id' => Type::INFO_WEIGHT_PIECE,
                    ],
                ],
            ]);
    }

    /** @test */
    public function it_returns_normal_when_no_categories_selected()
    {
        $this->actingAs($this->adminUser)
            ->graphQL(self::PREVIEW_QUERY, [
                'category_ids' => [],
            ])
            ->assertJson([
                'data' => [
                    'effective_stock_preview' => [
                        'mode' => 'normal',
                        'fixed' => null,
                        'fixed_unit_type_id' => null,
                    ],
                ],
            ]);
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает (query ещё не существует)**

Run: `DB_DATABASE=aladdin_admin_test php artisan test --filter=EffectiveStockPreviewTest`
Expected: FAIL — GraphQL-ошибка `Cannot query field "effective_stock_preview"` (или resolver-класс не найден)

- [ ] **Step 3: Создать PHP-резолвер**

Создать файл `app/GraphQL/Queries/EffectiveStockPreview.php`:

```php
<?php

namespace App\GraphQL\Queries;

use App\Services\Stock\StockOverrideService;

final class EffectiveStockPreview
{
    public function __construct(private StockOverrideService $stockOverrideService)
    {
    }

    public function __invoke($_, array $args): array
    {
        return $this->stockOverrideService->resolveForPreview(
            $args['category_ids'] ?? [],
            $args['shop_id'] ?? null,
            $args['unit_type_id'] ?? null
        );
    }
}
```

- [ ] **Step 4: Создать GraphQL-схему**

Создать файл `graphql/Product/Stock/StockQueries.graphql`:

```graphql
extend type Query {
  "Предпросмотр эффективного учёта остатков для набора категорий (до сохранения товара)"
  effective_stock_preview(
    category_ids: [Int!]
    shop_id: Int
    unit_type_id: Int
  ): EffectiveStockPreview @field(resolver: "App\\GraphQL\\Queries\\EffectiveStockPreview") @guard @can(model: "Admin", ability: "onlyAdmin")
}

type EffectiveStockPreview {
  mode: String!
  fixed: Int
  fixed_unit_type_id: Int
}
```

- [ ] **Step 5: Зарегистрировать импорт схемы**

В файле `graphql/schema.graphql` найти:
```graphql
#import Product/ShopZone/*.graphql
#import City/*.graphql
```

Заменить на:
```graphql
#import Product/ShopZone/*.graphql
#import Product/Stock/*.graphql
#import City/*.graphql
```

- [ ] **Step 6: Запустить тест — должен пройти (GREEN)**

Run: `DB_DATABASE=aladdin_admin_test php artisan test --filter=EffectiveStockPreviewTest`
Expected: `Tests: 2 passed`

- [ ] **Step 7: Прогнать Task 1 тесты — убедиться, что рефакторинг не сломан импортом схемы**

Run: `DB_DATABASE=aladdin_admin_test php artisan test --filter=StockOverrideTest`
Expected: `Tests: 17 passed`

- [ ] **Step 8: Commit**

```bash
git add app/GraphQL/Queries/EffectiveStockPreview.php graphql/Product/Stock/StockQueries.graphql graphql/schema.graphql tests/Feature/EffectiveStockPreviewTest.php
git commit -m "feat: add effective_stock_preview GraphQL query"
```

---

### Task 3: Frontend — показать превью в `open.vue`

**Files:**
- Create: `aladdin_admin_front/src/graphql/pages/Product/effective_stock_preview.graphql`
- Modify: `aladdin_admin_front/src/graphql/queries/admin_products_item.graphql`
- Modify: `aladdin_admin_front/src/views/products/open.vue`

**Interfaces:**
- Consumes: GraphQL query `effective_stock_preview(category_ids, shop_id, unit_type_id): EffectiveStockPreview { mode, fixed, fixed_unit_type_id }` из Task 2.
- Consumes: существующие computed `this.shop_id`, `this.baseUnitId`, существующий data `this.form.categories.value`.

Работать из директории: `/Users/daibelbuzarov/PhpstormProjects/aladdin/aladdin_admin_front`

- [ ] **Step 1: Создать GraphQL-запрос**

Создать файл `src/graphql/pages/Product/effective_stock_preview.graphql`:

```graphql
query ($category_ids: [Int!], $shop_id: Int, $unit_type_id: Int) {
  effective_stock_preview(category_ids: $category_ids, shop_id: $shop_id, unit_type_id: $unit_type_id) {
    mode
    fixed
    fixed_unit_type_id
  }
}
```

- [ ] **Step 2: Убрать неиспользуемые поля из `admin_products_item.graphql`**

Найти:
```graphql
    stock_mode
    fixed_stock_quantity
    fixed_stock_unit_type_id
    effective_stock_mode
    effective_fixed_stock
    remains {
```

Заменить на:
```graphql
    stock_mode
    fixed_stock_quantity
    fixed_stock_unit_type_id
    remains {
```

- [ ] **Step 3: Добавить импорт нового query в `open.vue`**

Найти:
```javascript
import OPTIONS from "@/graphql/pages/Product/options.graphql";
import DEFAULT_ATTRIBUTES from "@/graphql/queries/default_attributes.graphql";
import CATEGORY_FAMILY_ATTRIBUTES from "@/graphql/queries/category_family_attributes.graphql";
```

Заменить на:
```javascript
import OPTIONS from "@/graphql/pages/Product/options.graphql";
import DEFAULT_ATTRIBUTES from "@/graphql/queries/default_attributes.graphql";
import CATEGORY_FAMILY_ATTRIBUTES from "@/graphql/queries/category_family_attributes.graphql";
import EFFECTIVE_STOCK_PREVIEW from "@/graphql/pages/Product/effective_stock_preview.graphql";
```

- [ ] **Step 4: Добавить data-поле для единицы измерения лимита**

Найти:
```javascript
      stockOverrideEnabled: false,
      effective_stock_mode: null,
      effective_fixed_stock: null,
      price_per_small_unit: null,
```

Заменить на:
```javascript
      stockOverrideEnabled: false,
      effective_stock_mode: null,
      effective_fixed_stock: null,
      effective_fixed_stock_unit_type_id: null,
      price_per_small_unit: null,
```

- [ ] **Step 5: Убрать чтение `effective_*` из `get()` (материализованное значение больше не источник истины)**

Найти:
```javascript
              this.effective_stock_mode = data.admin_products_item.effective_stock_mode ?? null;
              this.effective_fixed_stock = data.admin_products_item.effective_fixed_stock ?? null;
              this.stockOverrideEnabled = !!data.admin_products_item.stock_mode;
```

Заменить на:
```javascript
              this.stockOverrideEnabled = !!data.admin_products_item.stock_mode;
```

- [ ] **Step 6: Добавить метод обновления превью**

Найти метод `loadCategoryAttributes` (заканчивается строкой `.finally(() => { this.categoryAttributesLoading = false; });\n    },`):

```javascript
    loadCategoryAttributes(categoryId) {
      this.categoryAttributesLoading = true;
      this.apollo()
        .admin.query({
          query: CATEGORY_FAMILY_ATTRIBUTES,
          variables: { category_id: categoryId },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.categoryAttributes = (data.category_family_attributes || [])
            .filter((item) => item.attribute.category_type_id === 103);
        })
        .catch((err) => {
          console.error("Error loading category attributes:", err);
          this.categoryAttributes = [];
        })
        .finally(() => {
          this.categoryAttributesLoading = false;
        });
    },
```

Заменить на (тот же код + новый метод сразу после):

```javascript
    loadCategoryAttributes(categoryId) {
      this.categoryAttributesLoading = true;
      this.apollo()
        .admin.query({
          query: CATEGORY_FAMILY_ATTRIBUTES,
          variables: { category_id: categoryId },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.categoryAttributes = (data.category_family_attributes || [])
            .filter((item) => item.attribute.category_type_id === 103);
        })
        .catch((err) => {
          console.error("Error loading category attributes:", err);
          this.categoryAttributes = [];
        })
        .finally(() => {
          this.categoryAttributesLoading = false;
        });
    },
    // Превью эффективного учёта остатков (категория/магазин/единица) — работает
    // и при создании (нет ещё сохранённого товара), и при редактировании (живой
    // пересчёт при смене категории/единицы до сохранения).
    refreshEffectiveStockPreview() {
      this.apollo()
        .admin.query({
          query: EFFECTIVE_STOCK_PREVIEW,
          variables: {
            category_ids: (this.form.categories.value ?? []).map((c) => c.id),
            shop_id: this.shop_id || undefined,
            unit_type_id: this.baseUnitId || undefined,
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const preview = data.effective_stock_preview;
          this.effective_stock_mode = preview?.mode ?? null;
          this.effective_fixed_stock = preview?.fixed ?? null;
          this.effective_fixed_stock_unit_type_id = preview?.fixed_unit_type_id ?? null;
        })
        .catch(() => {
          this.effective_stock_mode = null;
          this.effective_fixed_stock = null;
          this.effective_fixed_stock_unit_type_id = null;
        });
    },
```

- [ ] **Step 7: Расширить watcher категорий + добавить watcher единицы измерения**

Найти:
```javascript
  watch: {
    'form.categories.value': {
      handler(newVal) {
        const primaryId = (newVal ?? [])[0]?.id;
        if (primaryId) {
          this.loadCategoryAttributes(primaryId);
        } else {
          this.categoryAttributes = [];
        }
      },
      deep: true,
    },
```

Заменить на:
```javascript
  watch: {
    'form.categories.value': {
      handler(newVal) {
        const primaryId = (newVal ?? [])[0]?.id;
        if (primaryId) {
          this.loadCategoryAttributes(primaryId);
        } else {
          this.categoryAttributes = [];
        }
        this.refreshEffectiveStockPreview();
      },
      deep: true,
    },
    baseUnitId() {
      this.refreshEffectiveStockPreview();
    },
```

- [ ] **Step 8: Вызвать превью сразу на mount (чтобы работало и без выбранной категории — fallback на магазин/normal)**

Найти:
```javascript
  mounted() {
    this.get();
    this.getOptions();

    if (!this.id) {
      // this.$refs.supplements.add();
      this.$refs.images.add();
    }
  },
```

Заменить на:
```javascript
  mounted() {
    this.get();
    this.getOptions();
    this.refreshEffectiveStockPreview();

    if (!this.id) {
      // this.$refs.supplements.add();
      this.$refs.images.add();
    }
  },
```

- [ ] **Step 9: Починить единицу измерения в `effectiveStockLabel`**

Найти:
```javascript
    effectiveStockLabel() {
      const mode = this.effective_stock_mode;
      if (!mode || mode === "normal") return "Стандартный учёт остатков";
      if (mode === "ignore") return "Не учитываются (из категории)";
      if (mode === "fixed") {
        const qty = this.effective_fixed_stock;
        const unitId = this.form.fixed_stock_unit_type_id.value?.id ?? null;
        const unitTitle = this.stockUnits.find((u) => u.id === unitId)?.title ?? "";
        return qty != null
          ? `Фиксированный лимит ${qty}${unitTitle ? " " + unitTitle : ""} (из категории)`
          : "Фиксированный лимит (из категории)";
      }
      return mode;
    },
```

Заменить на:
```javascript
    effectiveStockLabel() {
      const mode = this.effective_stock_mode;
      if (!mode || mode === "normal") return "Стандартный учёт остатков";
      if (mode === "ignore") return "Не учитываются (из категории)";
      if (mode === "fixed") {
        const qty = this.effective_fixed_stock;
        const unitTitle = this.stockUnits.find((u) => u.id === this.effective_fixed_stock_unit_type_id)?.title ?? "";
        return qty != null
          ? `Фиксированный лимит ${qty}${unitTitle ? " " + unitTitle : ""} (из категории)`
          : "Фиксированный лимит (из категории)";
      }
      return mode;
    },
```

- [ ] **Step 10: Проверить сборку**

Run: `npm run build`
Expected: exit code 0. **НЕ запускать `npm run lint`** (мутирует репозиторий, см. Global Constraints).

- [ ] **Step 11: Проверить, что дифф минимален**

Run: `git diff --stat`
Expected: только 3 файла — `src/graphql/pages/Product/effective_stock_preview.graphql` (new), `src/graphql/queries/admin_products_item.graphql`, `src/views/products/open.vue`. Никаких посторонних правок форматирования в других файлах (если появились — значит что-то запустило автоформат, откатить через `git restore <файл>` и повторить конкретные Edit-шаги).

- [ ] **Step 12: Commit**

```bash
git add src/graphql/pages/Product/effective_stock_preview.graphql src/graphql/queries/admin_products_item.graphql src/views/products/open.vue
git commit -m "feat: show effective stock preview from category on product create"
```

---

## Self-Review Notes

- **Spec coverage:** backend refactor (Task 1) ✅, новый GraphQL-запрос (Task 2) ✅, фронтенд-вайринг + фикс бага единицы измерения (Task 3, шаги 6/9) ✅. `configurable.vue` и список товаров явно вне scope — не тронуты ни в одной задаче.
- **Regression safety:** Task 1 явно прогоняет все 11 существующих тестов `StockOverrideTest` без изменений в них — рефакторинг `resolve(Product)` доказанно поведенчески идентичен.
- **Единый источник истины:** после Task 3 `effective_stock_mode`/`effective_fixed_stock` больше не читаются из материализованных колонок товара нигде на фронте — только через живое превью (устраняет риск расхождения между сохранённым и отображаемым значением, убирает и «мигание» при гидратации).

## Execution Handoff

После выполнения задач 1-3 — задеплоить оба репозитория (`aladdin_admin_back` → `master`, дождаться `lighthouse:clear-cache`; `aladdin_admin_front` → `master`) и вручную проверить в браузере: создание товара с выбором категории, настроенной на `ignore`/`fixed`, показывает верный лейбл ДО сохранения; смена категории/единицы измерения обновляет лейбл живьём; после сохранения материализованное значение совпадает с превью. Мёржить в master **только по явной команде пользователя**.
