# Предпросмотр эффективного учёта остатков категории при создании товара

> Затрагивает два репозитория: `aladdin_admin_back` (бэкенд, GraphQL) и `aladdin_admin_front` (Vue-форма товара, `src/views/products/open.vue`). Спека и план лежат в `aladdin_admin_back`; план явно указывает рабочую директорию для каждой задачи.

## Проблема

В карточке простого товара (`aladdin_admin_front/src/views/products/open.vue`) есть блок «Учёт остатков»:

```html
<div class="product-page__stock">
  <div class="product-page__status">
    <div class="product-page__status-title">Учёт остатков</div>
    <FlagCheckboxComponent v-model="stockOverrideEnabled">
      {{ stockOverrideEnabled ? "Задать у товара" : "Из категории" }}
    </FlagCheckboxComponent>
  </div>
  <template v-if="!stockOverrideEnabled">
    <p class="product-page__quantum-line">{{ effectiveStockLabel }}</p>
  </template>
  ...
</div>
```

`effectiveStockLabel` — computed, читает `this.effective_stock_mode`/`this.effective_fixed_stock`. Эти data-свойства заполняются **только** в методе `get()` (`if (!this.loading && this.id)`) из материализованных колонок сохранённого товара (`data.admin_products_item.effective_stock_mode`/`effective_fixed_stock`). При **создании** нового товара (`this.id` пуст) эти поля никогда не запрашиваются и остаются `null` → `effectiveStockLabel` всегда возвращает fallback `"Стандартный учёт остатков"`, независимо от реальной настройки выбранной категории (может быть `ignore` или `fixed`).

Задача: чтобы при создании товара (до сохранения) блок показывал реальный эффективный режим, унаследованный от выбранной категории — по аналогии с тем, как теперь работает лейбл магазина.

## Текущая архитектура (бэкенд)

`aladdin_admin_back/app/Services/Stock/StockOverrideService.php`, метод `resolve(Product $product): array`:

1. Если у товара свой `stock_mode` (`ignore`/`fixed`) — приоритет у товара.
2. Иначе `resolveFromCategories($product)` — для каждой витринной категории товара поднимается по дереву (`climbToConfigured()`, до 10 уровней) до первой категории с `stock_mode !== 'normal'`. Среди найденных: `fixed` строже `ignore`; при нескольких `fixed` — минимальный лимит; `fixed` учитывается только при совпадении unit-семейства (масса/объём/шт, см. `UnitFamily`) с товаром.
3. Иначе `shops.ignore_stock`.
4. Иначе `normal`.

Результат материализуется в колонки `products.effective_stock_mode`/`effective_fixed_stock` при каждом сохранении товара/категории (`applyToProduct()`), их же отдаёт GraphQL-тип `Product` как обычные скалярные поля (не live-резолвер).

Для ещё несохранённого товара актуальны только шаги 2-4 (шаг 1 недостижим — блок с товарным оверрайдом при `stockOverrideEnabled=true` не показывает `effectiveStockLabel` вообще, `v-if="!stockOverrideEnabled"`).

## Решение

### 1. Backend-рефакторинг (behavior-preserving)

Вынести climb-логику (шаги 2-4) в новый публичный метод, не зависящий от `Product`:

```php
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

`resolveFromCategoryIds(array $categoryIds, ?int $unitTypeId)` — та же логика, что была в приватном `resolveFromCategories(Product $product)`, но принимает `category_id[]` + `unit_type_id` напрямую вместо `$product->categories()`/`$product->base_unit_type_id`. Дополнительно возвращает `fixed_unit_type_id` — наименьшую единицу найденного unit-семейства (`UnitFamily::smallestUnit($family)`), чтобы фронт мог корректно подписать единицу лимита (`г`/`шт`).

`resolve(Product $product)` становится тонкой обёрткой:

```php
public function resolve(Product $product): array
{
    if ($product->stock_mode === StockMode::IGNORE) {
        return ['mode' => StockMode::IGNORE, 'fixed' => null];
    }
    if ($product->stock_mode === StockMode::FIXED) {
        return ['mode' => StockMode::FIXED, 'fixed' => $this->productFixedInBaseUnit($product)];
    }

    return $this->resolveForPreview(
        $product->categories()->pluck('categories.id')->all(),
        $product->shop_id,
        $product->base_unit_type_id ?? $product->info_weight_type_id
    );
}
```

Поведение для существующих товаров не меняется (те же материализованные колонки, тот же результат climb).

### 2. Новый GraphQL query

По образцу существующего `category_family_attributes` (custom PHP-резолвер класс в `app/GraphQL/Queries/`, директива `@field(resolver: "...")`, тот же guard `@guard @can(model: "Admin", ability: "onlyAdmin")`):

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

Резолвер `App\GraphQL\Queries\EffectiveStockPreview::__invoke($_, array $args)` вызывает `StockOverrideService::resolveForPreview($args['category_ids'] ?? [], $args['shop_id'] ?? null, $args['unit_type_id'] ?? null)`.

### 3. Frontend (`aladdin_admin_front/src/views/products/open.vue`)

Расширить существующий watcher на `form.categories.value` (уже работает и при создании, и при редактировании — сейчас вызывает `loadCategoryAttributes(primaryId)`): добавить вызов нового query с `category_ids` = id всех выбранных категорий (не только первой — сервис учитывает ВСЕ витринные категории товара), `shop_id` = `this.shop_id` (уже есть, из route), `unit_type_id` = `this.baseUnitId` (уже существующий computed). Результат класть в те же `effective_stock_mode`/`effective_fixed_stock`, плюс новое data-поле `effective_fixed_stock_unit_type_id`.

Добавить второй watcher на computed `baseUnitId` (реагирует на смену «Ед. измерения»), вызывающий тот же метод обновления превью.

**Убрать** чтение `effective_stock_mode`/`effective_fixed_stock` из `get()` (сейчас — `data.admin_products_item.effective_stock_mode`/`effective_fixed_stock`) — эти поля больше не нужны как «стартовое значение из сохранённого товара»: `form.categories.value` в `get()` всё равно выставляется асинхронно при гидратации существующего товара, и это уже сегодня триггерит watcher (иначе `loadCategoryAttributes` не работал бы при редактировании) → тот же watcher вызовет и новый preview-query, вычислив `effective_stock_mode`/`effective_fixed_stock` через тот же самый (общий с бэкендом) алгоритм, включая недостающий `effective_fixed_stock_unit_type_id`. Один источник истины вместо двух (материализованное значение и live-превью), никакого риска расхождения между ними, никакого мигания «пусто → появилось».

`effectiveStockLabel` поправить, чтобы брать суффикс единицы из нового `this.effective_fixed_stock_unit_type_id` (через `stockUnits`), а не из `form.fixed_stock_unit_type_id.value` (пустого при наследовании) — это чинит существующий баг с отсутствующим суффиксом единицы и в режиме редактирования.

`stockOverrideEnabled` (тумблер «Задать у товара»/«Из категории») по-прежнему выставляется в `get()` из `data.admin_products_item.stock_mode` — это отдельное, СВОЁ поле товара, не затрагивается.

## Вне scope

- `configurable.vue` (вариативный товар) — блока «Учёт остатков» там нет вообще, не трогаем.
- Список товаров, мутации `ProductCreate`/`ProductUpdate` — не меняются.
- Аналогичная проблема для «кванта» (`category_quantum_step`) — тот же класс бага (доступен только при редактировании), но пользователь просил только про учёт остатков; квант — отдельная фича с другой архитектурой резолвинга (см. заметку ниже), не в этом scope.
- Unit-семейство «объём» (мл/литр) для fixed-лимита — фронтовый `stockUnits` уже сейчас поддерживает только грамм/штуку (существующее ограничение, не вводим и не чиним новое).

**Заметка на будущее:** `category_quantum_step`/`category_quantum_unit_type_id` резолвятся отдельным сервисом (`CategoryQuantumService`, live GraphQL-резолвер на `Product`, а не материализация), который смотрит только на категории, **напрямую привязанные к товару** (без climb по дереву) — архитектурно не может отработать без `product_id` вообще. Если понадобится аналогичный предпросмотр для кванта — это отдельная (более сложная) задача, не расширение текущей.

## Проверка (e2e)

- Открыть создание товара, выбрать категорию с `stock_mode = ignore` → блок показывает «Не учитываются (из категории)» ещё до сохранения.
- Выбрать категорию с `stock_mode = fixed` и заданным лимитом/единицей → блок показывает «Фиксированный лимит X <единица> (из категории)».
- Сменить категорию на не настроенную (`normal`/не задано) → блок возвращается к «Стандартный учёт остатков» (или к `shops.ignore_stock`, если у магазина включено).
- Сменить «Ед. измерения» на семейство, не совпадающее с unit-семейством `fixed`-категории → лимит перестаёт применяться (climb идёt дальше / fallback), лейбл обновляется соответственно.
- То же самое проверить в режиме редактирования существующего товара — исходное значение верно при загрузке, и обновляется живьём при смене категории/единицы до сохранения.
- Убедиться, что материализованное значение после реального сохранения (`ProductUpdate` → `applyToProduct`) совпадает с тем, что показывал превью до сохранения (регрессионная проверка `resolve(Product)` не изменился).
