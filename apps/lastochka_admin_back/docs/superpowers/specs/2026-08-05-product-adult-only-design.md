# Флаг «Товар 18+» (adult_only)

Дата: 2026-08-05. Статус: реализовано, дизайн утверждён пользователем (имя поля — `adult_only`).

## Задача

Добавить товару чекбокс «Товар 18+» в админке и отдавать признак в storefront (REST API витрины) и delivery (GraphQL сборщика/курьера).

## Решение

Зеркало существующего builtin-флага `pickup_only` (бывший `is_alcohol`, коммиты `2fc3f43b` + `deda457d` + `7053354c`) и `served_warm` (admin_front `538f9486`). Поле — колонка `products.adult_only BOOLEAN DEFAULT FALSE` в общей БД docker-модулей; в storefront — своя колонка, синхронизируется RabbitMQ-событиями `product.created/updated`.

Только маркировка: флаг хранится, синхронизируется и отдаётся клиентам. Возрастная проверка при заказе (age-gate), фильтрация каталога и наследование флага вариативными товарами — вне scope (вариативные товары не наследуют ни один boolean-флаг, известный зазор, см. learnings-archive 2026-07).

## Изменения по модулям

### admin_back (источник истины)
- Миграция `2026_08_05_120000_add_adult_only_to_products_table.php` — guard `Schema::hasColumn`, `after('pickup_only')`.
- `Product`: `$fillable` + `$casts` boolean.
- `ProductFilter::adult_only()` — программный фильтр (как `pickup_only`).
- GraphQL: `Product.graphql` (`adult_only:Boolean!`), аргумент в `ProductCreate`/`ProductUpdate`, правило `nullable|boolean` в `ProductUpdateValidator`.
- `ProductEventTransformer::toEvent()` — `'adult_only' => (bool) $product->adult_only` (полный payload); `fromEvent()` — conditional set `array_key_exists` (партиальные события 1С/retailbridge не затирают флаг).
- `CustomCheckboxService::defaultCheckboxConfig()['Product']` — `['field' => 'adult_only', 'label' => 'Товар 18+', 'enabled' => true]`; видимость/подпись управляются настройкой «Чекбоксы» (entity_checkboxes).

### admin_front
- `checkboxConfig.js` `CHECKBOX_DEFAULTS.Product.adult_only` (без регистрации чекбокс не рендерится).
- `open.vue`: блок чекбокса в обоих режимах (edit c `:disabled="loading"` и create), `form.adult_only`, `collectData()`, гидрация в `get()`.
- GraphQL: переменная в `ProductCreate`/`ProductUpdate`, поле в `admin_products_item`.

### storefront_back
- Миграция аналогично admin_back.
- `Product`: fillable + casts.
- `ProductEventTransformer::fromEvent()` — conditional set рядом с `pickup_only`; батч-события проходят через тот же `fromEvent`, отдельных правок не требуют.
- Отдача: `ProductResource.adultOnly` (каталог, карточка, поиск, корзина, избранное, группы), `PromotionProductResource.adultOnly`, `ComparisonProductResource.adultOnly`. В Meilisearch-документ поле НЕ добавлено — фильтрация поиска по нему не требуется (как у `pickup_only`/`served_warm`).

### delivery_back
- `graphql/Delivery/Product/Product.graphql` — `adult_only:Boolean` (общая БД, колонку пишет admin_back; тип Product один — поле видно во всех путях: `assembly_items → order_item.product`, `replacements.product`, `orders → items.product`, product_search*).
- Модель без изменений (в модуле нет $casts, Lighthouse приводит tinyint к Boolean).
- purchase_back не затронут: products entity disabled; delivery_back публикует только `stock_updated` (минимальный payload) — асимметрии payload нет.

## Тесты (все зелёные локально)

- admin_back: `ProductAdultOnlyFilterTest` (3), `ProductEventTransformerAdultOnlyTest` (4, вкл. «партиальный апдейт без ключа не затирает»), `ProductAdultOnlyFieldTest` GraphQL (3).
- storefront_back: `ProductEventHandlerAdultOnlyTest` (3, вкл. partial update держит флаг).
- delivery_back: `ProductAdultOnlyFieldTest` (2).

## Деплой-пререквизиты

1. Миграции накатываются CI (admin_back и storefront_back).
2. После деплоя GraphQL-изменений — `php artisan lighthouse:clear-cache` на admin_back и delivery_back.
3. Для существующих товаров дефолт `false`; событие уедет при следующем `ProductUpdated` (обычное сохранение карточки).
