# Категории в поисковой выдаче (MID-171) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `GET /v1/search/products` и `GET /v1/search/suggestions?structured=true` возвращают категории, чьё название матчится с поисковым запросом (METRO-семантика).

**Architecture:** Новый приватный метод `SearchService::searchCategories()` — PHP-матчинг (`mb_stripos`) по выборке активных категорий доступных магазинов; интеграция в `executeSearch()` (новый ключ `categories` ответа, вне Meili try/catch) и в structured-ветку `getSuggestions()` (name-match первыми + добивка старой логикой). HTTP-слой: параметр `categoriesLimit` + `CategoryResource` в ответе.

**Tech Stack:** PHP 8.4, Laravel 12, Meilisearch (не затрагивается), PHPUnit (SQLite in-memory).

**Spec:** `docs/superpowers/specs/2026-07-28-search-categories-design.md`

## Global Constraints

- `declare(strict_types=1)`, `final` классы — как во всём модуле.
- Только `mb_*`-функции для строк (кириллица!): `mb_stripos`, `mb_strtolower`. Никаких `stripos`/`strtolower`/SQL `LIKE` для матчинга (SQLite в тестах не умеет регистр кириллицы в `LIKE`/`LOWER`).
- Имена зафиксированы спекой: ключ ответа `categories`, параметр `categoriesLimit` (snake-алиас `categories_limit`), метод `searchCategories()`, конфиг `meilisearch.search.default_categories_limit` (=5) / `max_categories_limit` (=20).
- Формат элементов categories в structured suggestions — `{id, name}`, НЕ менять.
- **НЕ коммитить и НЕ пушить** — пользователь даёт команду на коммит отдельно (глобальное правило). Шагов commit в плане нет намеренно.
- Тесты: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=<Имя>` из корня `mendev_storefront_back` (локальная машина, SQLite in-memory; НИКОГДА не на сервере).

---

### Task 1: `SearchService::searchCategories()` + интеграция в `search()`

**Files:**
- Modify: `config/meilisearch.php` (секция `search`)
- Modify: `app/Services/Search/SearchService.php`
- Test (create): `tests/Feature/Search/SearchCategoriesTest.php`

**Interfaces:**
- Consumes: `Category::scopeActive()`, `Category::stores()` (pivot `category_store.is_active`), `SearchService::resolveAllowedStoreIds(?Address, ?User): array<int>` — всё существующее.
- Produces: `SearchService::search(..., int $categoriesLimit = 5): array` — в возвращаемом массиве новый ключ `categories: EloquentCollection<int, Category>` (с загруженным `parent`). Приватный `searchCategories(string $query, array $allowedStoreIds, int $limit): EloquentCollection`. Task 2 полагается на ключ `categories` и параметр `categoriesLimit`.

- [ ] **Step 1: Написать падающие тесты**

Создать `tests/Feature/Search/SearchCategoriesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SearchCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private SearchService $searchService;

    protected function setUp(): void
    {
        parent::setUp();

        // Observer отключаем — товары в этих тестах не индексируем
        Product::unsetEventDispatcher();

        $this->searchService = $this->app->make(SearchService::class);
    }

    public function test_search_returns_name_matched_categories(): void
    {
        $store = Store::factory()->create();
        $milk = Category::factory()->forStore($store)->create(['name' => 'Молоко']);
        $dairy = Category::factory()->forStore($store)->create(['name' => 'Молоко, яйцо, масло']);
        $chemistry = Category::factory()->forStore($store)->create(['name' => 'Бытовая химия']);

        $result = $this->searchService->search(query: 'моло');

        $ids = $result['categories']->pluck('id')->all();

        $this->assertContains($milk->id, $ids);
        $this->assertContains($dairy->id, $ids);
        $this->assertNotContains($chemistry->id, $ids);
    }

    public function test_matching_is_case_insensitive_for_cyrillic(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create(['name' => 'молочные продукты']);

        $result = $this->searchService->search(query: 'МОЛО');

        $this->assertContains($category->id, $result['categories']->pluck('id')->all());
    }

    public function test_multi_word_query_requires_all_words(): void
    {
        $store = Store::factory()->create();
        $dairy = Category::factory()->forStore($store)->create(['name' => 'Молоко, яйцо, масло']);
        $milk = Category::factory()->forStore($store)->create(['name' => 'Молоко']);

        $result = $this->searchService->search(query: 'моло яйц');

        $ids = $result['categories']->pluck('id')->all();

        $this->assertContains($dairy->id, $ids);
        $this->assertNotContains($milk->id, $ids);
    }

    public function test_prefix_matches_sorted_first(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create(['name' => 'Растительное молоко']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко']);

        $result = $this->searchService->search(query: 'моло');

        $this->assertSame(
            ['Молоко', 'Растительное молоко'],
            $result['categories']->pluck('name')->all(),
        );
    }

    public function test_inactive_category_excluded(): void
    {
        $store = Store::factory()->create();
        Category::factory()->inactive()->forStore($store)->create(['name' => 'Молоко скрытое']);

        $result = $this->searchService->search(query: 'моло');

        $this->assertCount(0, $result['categories']);
    }

    public function test_inactive_pivot_excluded(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store, ['is_active' => false])->create(['name' => 'Молоко отвязанное']);

        $result = $this->searchService->search(query: 'моло');

        $this->assertCount(0, $result['categories']);
    }

    public function test_category_of_inactive_store_excluded(): void
    {
        $inactiveStore = Store::factory()->create(['is_active' => false]);
        Category::factory()->forStore($inactiveStore)->create(['name' => 'Молоко недоступное']);

        $result = $this->searchService->search(query: 'моло');

        $this->assertCount(0, $result['categories']);
    }

    public function test_categories_limit_zero_disables_section(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create(['name' => 'Молоко']);

        $result = $this->searchService->search(query: 'моло', categoriesLimit: 0);

        $this->assertCount(0, $result['categories']);
    }

    public function test_categories_limit_is_part_of_cache_key(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create(['name' => 'Молоко']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко козье']);

        $first = $this->searchService->search(query: 'моло', categoriesLimit: 1);
        $second = $this->searchService->search(query: 'моло', categoriesLimit: 2);

        $this->assertCount(1, $first['categories']);
        $this->assertCount(2, $second['categories']);
    }

    public function test_parent_relation_loaded(): void
    {
        $store = Store::factory()->create();
        $parent = Category::factory()->forStore($store)->create(['name' => 'Молочные продукты']);
        $child = Category::factory()->childOf($parent)->forStore($store)->create(['name' => 'Молоко']);

        $result = $this->searchService->search(query: 'молоко');

        $found = $result['categories']->firstWhere('id', $child->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->relationLoaded('parent'));
        $this->assertSame($parent->id, $found->parent->id);
    }
}
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=SearchCategoriesTest`
Expected: FAIL — `Unknown named parameter $categoriesLimit` / `Undefined array key "categories"`.

- [ ] **Step 3: Реализация**

3a. `config/meilisearch.php` — в массив `'search'` после `'suggestions_limit' => 20,` добавить:

```php
        'default_categories_limit' => 5,
        'max_categories_limit' => 20,
```

3b. `app/Services/Search/SearchService.php`:

В `search()` — добавить параметр и прокинуть в кеш-ключ и `executeSearch`:

```php
    public function search(
        string $query,
        int $page = 1,
        int $perPage = 10,
        int $productsPerStore = 10,
        ?bool $onlyDiscounted = null,
        ?bool $sugarFree = null,
        ?Address $address = null,
        ?User $user = null,
        ?int $brandId = null,
        ?int $categoryId = null,
        int $categoriesLimit = 5
    ): array {
        $isB2b = $user?->is_b2b ?? false;
        $cacheKey = $this->getCacheKey($query, $page, $perPage, $productsPerStore, $isB2b, $onlyDiscounted, $sugarFree, $brandId, $categoryId, $categoriesLimit);
        $cacheTtl = config('meilisearch.cache_ttl', 30);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($query, $page, $perPage, $productsPerStore, $onlyDiscounted, $sugarFree, $address, $user, $brandId, $categoryId, $categoriesLimit) {
            return $this->executeSearch($query, $page, $perPage, $productsPerStore, $onlyDiscounted, $sugarFree, $address, $user, $brandId, $categoryId, $categoriesLimit);
        });
    }
```

В `getCacheKey()` — новый параметр в сигнатуру и ключ:

```php
    private function getCacheKey(string $query, int $page, int $perPage, int $productsPerStore, bool $isB2b = false, ?bool $onlyDiscounted = null, ?bool $sugarFree = null, ?int $brandId = null, ?int $categoryId = null, int $categoriesLimit = 5): string
    {
        $hash = md5($query);
        $type = $isB2b ? 'b2b' : 'b2c';
        $discountedFlag = $onlyDiscounted ? '1' : '0';
        $sugarFreeFlag = $sugarFree ? '1' : '0';
        $brandFlag = $brandId ?? '0';
        $categoryFlag = $categoryId ?? '0';

        return "search:{$hash}:{$page}:{$perPage}:{$productsPerStore}:{$type}:{$discountedFlag}:{$sugarFreeFlag}:{$brandFlag}:{$categoryFlag}:{$categoriesLimit}";
    }
```

В `executeSearch()` — параметр, вычисление категорий ДО Meili-try (при недоступности Meili секция всё равно наполняется), ключ `categories` в обоих return:

```php
    private function executeSearch(
        string $query,
        int $page,
        int $perPage,
        int $productsPerStore,
        ?bool $onlyDiscounted = null,
        ?bool $sugarFree = null,
        ?Address $address = null,
        ?User $user = null,
        ?int $brandId = null,
        ?int $categoryId = null,
        int $categoriesLimit = 5
    ): array {
        // Категории по названию (METRO-семантика) — независимо от Meilisearch:
        // при его недоступности товарная выдача деградирует, категории остаются.
        // Собственный try: сбой resolveAllowedStoreIds (сервис магазинов/БД) не
        // должен ронять весь поиск — раньше он был под общим try-catch.
        try {
            $categories = $this->searchCategories(
                $query,
                $this->resolveAllowedStoreIds($address, $user),
                $categoriesLimit,
            );
        } catch (\Exception $e) {
            Log::error('Search categories failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            $categories = new EloquentCollection();
        }

        try {
```

...в успешном `return` добавить `'categories' => $categories,`, в catch-блоке graceful degradation тоже `'categories' => $categories,`.

Новый метод (рядом с `getSuggestions()`):

```php
    /**
     * Поиск категорий по названию (METRO-семантика): каждое слово запроса
     * должно входить в название. Матчинг в PHP через mb_stripos, а не SQL
     * LIKE — на SQLite (тесты) LIKE/LOWER регистронезависимы только для
     * ASCII, а категорий мало и полная выборка кандидатов дешёвая.
     *
     * @param  array<int>  $allowedStoreIds
     * @return EloquentCollection<int, Category>
     */
    private function searchCategories(string $query, array $allowedStoreIds, int $limit): EloquentCollection
    {
        if ($limit <= 0 || empty($allowedStoreIds)) {
            return new EloquentCollection();
        }

        $query = trim($query);
        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return new EloquentCollection();
        }

        try {
            $candidates = Category::query()
                ->active()
                ->whereHas('stores', fn ($q) => $q
                    ->whereIn('stores.id', $allowedStoreIds)
                    ->where('category_store.is_active', true))
                ->get();

            $matched = $candidates
                ->filter(function (Category $category) use ($words): bool {
                    foreach ($words as $word) {
                        if (mb_stripos($category->name, $word) === false) {
                            return false;
                        }
                    }

                    return true;
                })
                ->sort(function (Category $a, Category $b) use ($query): int {
                    $aPrefix = mb_stripos($a->name, $query) === 0 ? 0 : 1;
                    $bPrefix = mb_stripos($b->name, $query) === 0 ? 0 : 1;

                    return $aPrefix <=> $bPrefix
                        ?: mb_strtolower($a->name) <=> mb_strtolower($b->name);
                })
                ->take($limit)
                ->values();

            // parent — после отбора top-N, чтобы не грузить родителей всей выборки
            $matched->load('parent');

            return $matched;
        } catch (\Exception $e) {
            Log::error('Search categories failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return new EloquentCollection();
        }
    }
```

Импорты `Category` и `EloquentCollection` в файле уже есть.

- [ ] **Step 4: Тесты зелёные**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=SearchCategoriesTest`
Expected: PASS (10 тестов). Затем регресс существующего:
`/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=SearchProductsTest`
Expected: PASS без изменений.

---

### Task 2: HTTP-слой `/v1/search/products` — параметр `categoriesLimit` + `categories` в ответе

**Files:**
- Modify: `app/Http/Requests/Api/V1/SearchProductsRequest.php`
- Modify: `app/Http/Controllers/Api/V1/SearchController.php` (метод `products()`)
- Test (modify): `tests/Feature/Api/V1/Search/SearchProductsTest.php`

**Interfaces:**
- Consumes: `SearchService::search(..., categoriesLimit: int)` с ключом `categories` в результате (Task 1); `CategoryResource` (существующий).
- Produces: JSON `{hasMore, data, total, categories: CategoryResource[]}`; query-параметр `categoriesLimit` (int ≥ 0, default 5, clamp max 20, snake-алиас `categories_limit`); `SearchProductsRequest::getCategoriesLimit(): int`.

- [ ] **Step 1: Написать падающие тесты**

В `tests/Feature/Api/V1/Search/SearchProductsTest.php` дополнить импорты:

```php
use App\Models\Address;
use App\Models\User;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
```

(Namespace'ы точные — сверены с `SearchSuggestionsTest.php`, где этот же квартет уже используется.)

Добавить тесты:

```php
    public function test_search_products_response_includes_matching_categories(): void
    {
        $store = Store::factory()->create();
        $milk = Category::factory()->forStore($store)->create(['name' => 'Молоко']);
        $chemistry = Category::factory()->forStore($store)->create(['name' => 'Бытовая химия']);

        $product = Product::factory()->for($store)->inCategory($milk)->create([
            'title' => 'Молоко 3.2%',
        ]);
        $this->searchService->indexProduct($product);

        $response = $this->getJson('/api/v1/search/products?query=моло');

        $response->assertOk()
            ->assertJsonStructure([
                'hasMore',
                'total',
                'data',
                'categories' => [['id', 'name', 'slug']],
            ]);

        $ids = array_column($response->json('categories'), 'id');
        $this->assertContains($milk->id, $ids);
        $this->assertNotContains($chemistry->id, $ids);

        // Товарная выдача не сломана
        $this->assertSame(1, $response->json('total'));
    }

    public function test_search_products_categories_present_when_no_product_hits(): void
    {
        $store = Store::factory()->create();
        $milk = Category::factory()->forStore($store)->create(['name' => 'Молоко']);

        $response = $this->getJson('/api/v1/search/products?query=моло');

        $response->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(1, 'categories')
            ->assertJsonPath('categories.0.id', $milk->id);
    }

    public function test_search_products_categories_limit_param(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create(['name' => 'Молоко']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко козье']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко овсяное']);

        $this->getJson('/api/v1/search/products?query=моло&categoriesLimit=2')
            ->assertOk()
            ->assertJsonCount(2, 'categories');

        $this->getJson('/api/v1/search/products?query=моло&categoriesLimit=0')
            ->assertOk()
            ->assertJsonCount(0, 'categories');

        // deprecated snake_case алиас
        $this->getJson('/api/v1/search/products?query=моло&categories_limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'categories');
    }

    public function test_search_products_categories_respect_available_stores_for_address(): void
    {
        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $availableStore = Store::factory()->create(['code' => '1', 'name' => 'Available']);
        $unavailableStore = Store::factory()->create(['code' => '2', 'name' => 'Unavailable']);

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        $reachable = Category::factory()->forStore($availableStore)->create(['name' => 'Молоко']);
        $unreachable = Category::factory()->forStore($unavailableStore)->create(['name' => 'Молоко фермерское']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/search/products?query=моло');

        $response->assertOk();

        $ids = array_column($response->json('categories'), 'id');
        $this->assertContains($reachable->id, $ids);
        $this->assertNotContains($unreachable->id, $ids);
    }
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=SearchProductsTest`
Expected: FAIL — в ответе нет ключа `categories`.

- [ ] **Step 3: Реализация**

3a. `SearchProductsRequest`:

- В карту алиасов `prepareForValidation()` добавить `'categories_limit' => 'categoriesLimit',`.
- В `rules()` добавить `'categoriesLimit' => ['sometimes', 'integer', 'min:0'],`.
- Новый геттер (после `getCategoryId()`):

```php
    public function getCategoriesLimit(): int
    {
        $default = config('meilisearch.search.default_categories_limit', 5);
        $max = config('meilisearch.search.max_categories_limit', 20);

        return min($this->integer('categoriesLimit', $default), $max);
    }
```

3b. `SearchController::products()`:

- Импорт: `use App\Http\Resources\Api\V1\CategoryResource;`
- В вызов `$this->searchService->search(...)` добавить аргумент `categoriesLimit: $request->getCategoriesLimit()`.
- Ответ:

```php
        return response()->json([
            'hasMore' => $result['hasMore'],
            'data' => StoreSearchResultResource::collection($result['stores']),
            'total' => $result['total'],
            'categories' => CategoryResource::collection($result['categories']),
        ]);
```

- Docblock метода: `@response array{hasMore: bool, data: StoreSearchResultResource[], total: int, categories: CategoryResource[]}`; в описание добавить строку «Дополнительно возвращает секцию `categories` — категории, название которых матчится с запросом (по аналогии с METRO).»
- Scramble-атрибут после `categoryId`:

```php
    #[QueryParameter('categoriesLimit', description: 'Макс. количество категорий в секции categories (max: 20, 0 — отключить секцию)', type: 'int', default: 5, example: 5)]
```

- [ ] **Step 4: Тесты зелёные**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=SearchProductsTest`
Expected: PASS (все, включая 4 новых).

---

### Task 3: METRO-семантика секции categories в `getSuggestions()` structured

**Files:**
- Modify: `app/Services/Search/SearchService.php` (метод `getSuggestions()`)
- Test (modify): `tests/Feature/Api/V1/Search/SearchSuggestionsTest.php`

**Interfaces:**
- Consumes: `searchCategories(string, array, int): EloquentCollection` (Task 1), `resolveAllowedStoreIds()` — уже вызывается в `getSuggestions()` (переменная `$allowedStoreIds`).
- Produces: секция `categories` structured-ответа: name-match первыми, добивка категориями найденных товаров до `limit` суммарно, дедуп по `id`. Формат `[{id, name}]` без изменений.

- [ ] **Step 1: Написать падающие тесты**

В `tests/Feature/Api/V1/Search/SearchSuggestionsTest.php` добавить:

```php
    public function test_structured_suggestions_name_matched_categories_first(): void
    {
        $store = Store::factory()->create();
        // Матчится по названию, товаров-хитов в ней нет
        $nameCat = Category::factory()->forStore($store)->create(['name' => 'Молоко']);
        // Название не матчится, но категория у товара-хита
        $hitCat = Category::factory()->forStore($store)->create(['name' => 'Бытовая техника']);

        $product = Product::factory()->for($store)->inCategory($hitCat)->create([
            'title' => 'Вспениватель молока Polaris',
        ]);
        $this->searchService->indexProduct($product);

        $response = $this->getJson('/api/v1/search/suggestions?query=моло&structured=1');

        $response->assertOk();

        $ids = array_column($response->json('categories'), 'id');

        // name-match первым, категория из товарных хитов — добивкой
        $this->assertSame($nameCat->id, $ids[0]);
        $this->assertContains($hitCat->id, $ids);
    }

    public function test_structured_suggestions_categories_dedup(): void
    {
        $store = Store::factory()->create();
        $milk = Category::factory()->forStore($store)->create(['name' => 'Молоко']);

        // Товар-хит лежит в той же name-match категории
        $product = Product::factory()->for($store)->inCategory($milk)->create([
            'title' => 'Молоко 3.2%',
        ]);
        $this->searchService->indexProduct($product);

        $response = $this->getJson('/api/v1/search/suggestions?query=моло&structured=1');

        $response->assertOk();

        $ids = array_column($response->json('categories'), 'id');

        $this->assertSame([$milk->id], $ids);
    }

    public function test_structured_suggestions_categories_capped_by_limit(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create(['name' => 'Молоко']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко козье']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко овсяное']);

        $response = $this->getJson('/api/v1/search/suggestions?query=моло&structured=1&limit=2');

        $response->assertOk()
            ->assertJsonCount(2, 'categories');
    }
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=SearchSuggestionsTest`
Expected: FAIL — первый: `$ids[0]` это `$hitCat` (name-match ещё нет); второй/третий — старая логика без дедупа/капа может выдать иной состав.

- [ ] **Step 3: Реализация**

В `getSuggestions()` (structured-ветка) заменить блок вычисления `$categoryIds`/`$categories` на:

```php
            // Категории: сначала name-match по названию (METRO-семантика),
            // добивка — категории найденных товаров, дедуп по id, суммарно ≤ limit.
            $nameMatched = $this->searchCategories($query, $allowedStoreIds, $limit);

            $categories = $nameMatched
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name])
                ->values();

            if ($categories->count() < $limit) {
                $hitCategoryIds = $hits->pluck('category_ids')->flatten()->unique()->filter()->values()->all();
                $hitCategoryIds = array_values(array_diff($hitCategoryIds, $nameMatched->pluck('id')->all()));

                if (! empty($hitCategoryIds)) {
                    $categories = $categories->concat(
                        Category::query()
                            ->whereIn('id', $hitCategoryIds)
                            ->active()
                            ->get()
                            ->take($limit - $categories->count())
                            ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name])
                            ->values()
                    )->values();
                }
            }
```

и в `return` structured-ветки использовать `'categories' => $categories->all(),` (блок `$brands` и `$titles` не трогать).

- [ ] **Step 4: Тесты зелёные**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=SearchSuggestionsTest`
Expected: PASS (все 12 старых + 3 новых). Старый `test_structured_suggestions_returns_categories_and_brands` должен пройти без правок: его категории («Circuit breakers», «Control systems») не матчатся с запросом «Switch» по названию и приходят добивкой из хитов.

---

### Task 4: Документация контракта + полный регресс

**Files:**
- Modify: `docs/api_search.md`
- Test: полный прогон search-тестов

**Interfaces:**
- Consumes: финальные контракты Task 2 и Task 3.
- Produces: актуальный контракт для разработчиков МП и веб-сайта.

- [ ] **Step 1: Обновить `docs/api_search.md`**

1a. В таблицу параметров `searchProducts` добавить строку:

```markdown
| categoriesLimit | int | ❌ | 5 | Макс. кол-во категорий в секции `categories` (max 20, `0` — отключить). Snake-алиас: `categories_limit` |
```

1b. Строку ответа заменить на `{hasMore: bool, data: StoreSearchResultResource[], total: int, categories: CategoryResource[]}` и после JSON-примера ответа добавить:

```markdown
**Секция `categories`** (по аналогии с METRO): категории, в названии которых
встречаются все слова запроса (регистронезависимо). Только активные категории,
привязанные хотя бы к одному доступному магазину (адресная/B2B-логика та же, что
у товаров). Сортировка: сначала совпадения с начала названия, затем по алфавиту.
Секция не пагинируется и возвращается на каждой странице — показывать её следует
на первой. У категории подгружен `parent` (для контекста «Молоко · Молочные
продукты»). Возвращается даже при нуле найденных товаров. Тап по категории —
на стороне клиента: фильтр `categoryId` этого же эндпоинта или каталожный экран.

```json
"categories": [
  {
    "id": 12,
    "name": "Молоко",
    "slug": "moloko",
    "position": 3,
    "image": { "id": 12, "path": "https://..." },
    "backgroundImage": null,
    "parent": { "id": 5, "name": "Молочные продукты", "slug": "molochnye-produkty" },
    "children": null,
    "customCheckboxes": {}
  }
]
```
```

1c. В секции `getSearchSuggestions` описание параметра `structured` дополнить: «Секция `categories`: сначала категории, чьё название матчится со всеми словами запроса (METRO-семантика), затем добивка категориями найденных товаров; дедуп по id; суммарно не более `limit`.»

- [ ] **Step 2: Полный регресс поиска**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test tests/Feature/Api/V1/Search tests/Feature/Search`
Expected: PASS все.

- [ ] **Step 3: Финальная сверка со спекой**

Пройтись по чеклисту тестов спеки (пп. 1–11) и таблице «Файлы» — каждая строка закрыта соответствующим Task. Коммит НЕ делать — доложить пользователю о готовности, коммит по его команде.
