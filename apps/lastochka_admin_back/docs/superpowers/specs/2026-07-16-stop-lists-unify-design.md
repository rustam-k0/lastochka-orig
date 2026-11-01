# Объединение стоп-листов магазина с полями карточки товара

**Дата:** 2026-07-16
**Задача:** Platrum 12188 (MID-155) «АП: Обсудить и настроить стоп-листы и карточку Товара. Сейчас выглядит так, как будто это два одинаковых функционала»
**Репозитории:** mendev_admin_back, mendev_storefront_back, mendev_admin_front

## Проблема

Вкладка «Стоп-листы» карточки магазина (фича shops-tabs от 2026-07-10) реализована как отдельные флаги на `products`: `stop_list_hidden(_at)` и `stop_list_pickup_only(_at)`. При этом на карточке товара уже есть поля с той же семантикой: тогглер «Активный» (`status_id`: 1 активный, 3 не опубликован) и чекбокс «Только самовывоз» (`pickup_only`). Товары в admin per-shop (`products.shop_id`), поэтому оба поля карточки уже действуют «на магазин».

Итог: два параллельных механизма одного и того же. Товар, скрытый тогглером карточки, не виден в стоп-листе «не отображать», и наоборот. В storefront enforcement задублирован: `scopeActive` проверяет `is_active AND NOT stop_list_hidden`, `isPickupOnly()` считает `pickup_only OR stop_list_pickup_only`.

## Решение

Стоп-листы становятся представлением полей карточки товара. Отдельные флаги `stop_list_hidden` / `stop_list_pickup_only` удаляются после merge-миграции данных.

- Стоп-лист «не отображать» = товары магазина со статусом «не активный» (`status_id != 1`).
- Стоп-лист «не доставлять» = товары магазина с `pickup_only = true`.
- Добавление/удаление из стоп-листа = то же действие, что тогглер/чекбокс на карточке.

### Принятые продуктовые решения

1. **Колонка «Дата» в стоп-листах сохраняется.** Ведутся timestamps `hidden_at` и `pickup_only_at` на `products` (admin_back), обновляемые на любом пути записи.
2. **«Не отображать» показывает все неактивные товары магазина, кроме вариаций configurable-товаров** (`parent_id IS NULL`). Товары, которые никогда не публиковались (создаются со статусом «не опубликован» по умолчанию, `Product::boot creating`), в список попадают: это буквальная семантика «в стоп-листе тот, у кого статус не активный».

## 1. admin_back

### Миграция (одна, с guard'ами Schema::hasColumn для форк-идемпотентности)

1. Переименовать `stop_list_hidden_at` в `hidden_at`, `stop_list_pickup_only_at` в `pickup_only_at`.
2. Merge данных прямым SQL (`DB::table`, не Eloquent):
   - `stop_list_hidden = true` → `status_id = 3`, `hidden_at = COALESCE(hidden_at, NOW())`;
   - `stop_list_pickup_only = true` → `pickup_only = 1`, `pickup_only_at = COALESCE(pickup_only_at, NOW())`.
3. Удалить колонки `stop_list_hidden`, `stop_list_pickup_only`.

Merge-миграция меняет данные напрямую и событий в outbox не публикует. Это допустимо: storefront выполняет собственный локальный merge (см. раздел 2), а его стоп-лист-флаги были синхронизированы событиями ранее и консистентны с admin.

### Ведение timestamps: мутаторы на модели Product

- `setStatusIdAttribute`: переход в «не активный» ставит `hidden_at = now()`; переход в «активный» очищает (`null`).
- `setPickupOnlyAttribute`: включение ставит `pickup_only_at = now()`; выключение очищает.

Мутаторы, а не observer: они срабатывают на любом пути записи атрибута, включая сохранение через `withoutEvents()` в консьюмерах RabbitMQ. Товар, созданный сразу неактивным, получает `hidden_at`, равный дате создания. Мутатор меняет timestamp только при фактической смене значения (сравнение с текущим), повторная запись того же значения дату не трогает.

### GraphQL: фасад сохраняется, начинка меняется

- **Query `shop_stop_list_products(shop_id, type)`** (`app/GraphQL/Queries/StopList/ShopStopListProducts.php`):
  - `type=hidden` → `where('status_id', '!=', Status::CODE_ACTIVE)`, сортировка `orderByDesc('hidden_at')`;
  - `type=pickup_only` → `where('pickup_only', true)`, сортировка `orderByDesc('pickup_only_at')`;
  - оба: `where('shop_id', ...)` и `whereNull('parent_id')` (без вариаций configurable).
- **Мутация `ProductStopListAdd(product_id, type)`**:
  - `hidden` → логика `ProductHide`: `status_id = 3`, `publish_after_unlock = 0`, запись в `ProductHistory`, `event(new ProductUpdated($product))`;
  - `pickup_only` → `$product->update(['pickup_only' => true])` + `event(new ProductUpdated($product))`.
- **Мутация `ProductStopListRemove(product_id, type)`**:
  - `hidden` → логика `ProductPublish`: `status_id = 1`, `publish_after_unlock = 1`, `ProductHistory`, событие;
  - `pickup_only` → `pickup_only = false` + событие.
  Делегирование в существующие резолверы `ProductHide`/`ProductPublish` (вызов их логики, не копия), чтобы поведение карточки и стоп-листа совпадало по определению. Разница ability (`onlyShop` у карточных, `onlyAdmin` у стоп-листов) остаётся на уровне GraphQL-схем и не мешает: внутренняя делегация через @can не проходит.
- **`graphql/Product/StopList/StopList.graphql`** (extend type Product): вместо четырёх `stop_list_*` полей экспонируются `hidden_at` и `pickup_only_at`.
- **`app/Support/StopListType.php`**: остаётся source-of-truth маппинга `type` → поведение/колонка даты, содержимое MAP переписывается.

### Событие product.updated

Из `ProductEventTransformer::toEvent()` удаляются поля `stop_list_hidden` и `stop_list_pickup_only` (строки ~196-197). Поля `active` и `pickup_only` в payload уже есть и продолжают нести объединённую семантику.

## 2. storefront_back

### Миграция

1. Merge локальных данных: `stop_list_hidden = true` → `is_active = false`; `stop_list_pickup_only = true` → `pickup_only = true`.
2. Удалить колонки `stop_list_hidden`, `stop_list_pickup_only`.

### Упрощение enforcement (точки вызова не меняются)

- `Product::scopeActive` → только `where('is_active', true)`.
- `Product::scopePurchasable` → убрать условие по `stop_list_hidden` (остальное без изменений).
- `ProductController::show` → один `abort(404)` по `is_active` (второй, по `stop_list_hidden`, удаляется).
- `Product::isPickupOnly()` → `(bool) $this->pickup_only` (метод и все его точки вызова в CreateOrderAction, SupplementOrderAction, AvailableShippingMethodsResolver сохраняются).
- `AvailableShippingMethodsResolver` → `loadMissing('product:id,pickup_only')` без `stop_list_pickup_only`.

### Meili

Убрать `stop_list_hidden` из `visibilityFilters` (SearchService), из индексируемого документа и из `filterableAttributes` (config/meilisearch.php). После деплоя обязателен `php artisan search:reindex`.

### Consumer

`ProductEventTransformer::fromEvent()`: удалить conditional-set блоки для `stop_list_hidden` и `stop_list_pickup_only`. Маппинги `active` → `is_active` и `pickup_only` не меняются.

### Модель

Убрать `stop_list_hidden`, `stop_list_pickup_only` из `$fillable` и `$casts`.

## 3. admin_front

- Вкладка `StopListsTab.vue`, секции `StopListSection.vue`, модалка `AddStopListProduct.vue`: визуально и структурно без изменений.
- GraphQL-файлы (`shop_stop_list_products.graphql`): поле даты `stop_list_hidden_at` / `stop_list_pickup_only_at` заменяется на `hidden_at` / `pickup_only_at`; маппинг даты в `StopListSection.vue` обновляется соответственно.
- Карточка товара (`products/open.vue`) не меняется: тогглер и чекбокс уже управляют целевыми полями.
- Целевой побочный эффект объединения: добавление в «не отображать» из стоп-листа переключает тогглер «Активный» на карточке, и наоборот; аналогично для самовывоза.

## 4. Зона поражения и деплой

- `delivery_back`, `purchase_back`: `stop_list` не используют (проверено grep), не затрагиваются.
- Порядок деплоя: admin_back → storefront_back (+ `search:reindex`) → admin_front.
- Окон несовместимости нет:
  - admin задеплоен, storefront ещё нет: admin перестал слать `stop_list_*` в событиях; conditional-set в storefront просто не срабатывает, его локальные флаги уже консистентны и будут смержены его же миграцией;
  - storefront задеплоен, admin ещё нет (обратный порядок не планируется, но безопасен): неизвестные поля payload storefront игнорирует.
- Изменения только в mendev. Раскатка sync-core на форки отдельно, по явной команде (фича стоп-листов на форки не раскатывалась).

## 5. Тесты (TDD, только локально)

### admin_back (переписать `tests/Feature/ProductStopListTest.php`)

- Add(hidden) ставит `status_id = 3` и `hidden_at`; Remove(hidden) возвращает `status_id = 1` и чистит `hidden_at`.
- Add/Remove(pickup_only) ставит/снимает `pickup_only` и `pickup_only_at`.
- Add(hidden) пишет `ProductHistory` (паритет с ProductHide).
- Мутаторы: смена статуса через `ProductUpdate`-путь и через прямой `update()` под `withoutEvents()` тоже ведёт timestamps; повторная запись того же значения дату не меняет.
- Query `hidden` возвращает неактивные товары магазина и не возвращает: активные, вариации (`parent_id NOT NULL`), товары другого магазина. Query `pickup_only` аналогично по флагу.
- Диспатч `ProductUpdated` при add/remove обоих типов.
- Payload `toEvent()` не содержит `stop_list_hidden` / `stop_list_pickup_only`; `active` и `pickup_only` отражают новое состояние.
- Невалидный `type` отклоняется (существующее поведение StopListType сохраняется).

### storefront_back

- Тесты enforcement (`ProductStopListTest`, `CreateOrderStopListPickupOnlyTest`, `SupplementOrderStopListPickupOnlyTest`, `AvailableShippingMethodsStopListTest`, `StopListHiddenSearchTest`) переписываются с полей `stop_list_*` на `is_active` / `pickup_only`; кейсы, дублирующие уже существующие тесты по `is_active` / `pickup_only`, удаляются.
- `ProductEventHandlerStopListTest` (маппинг `stop_list_*` из события) удаляется.
- Миграционный merge покрывается проверкой на живом dev после деплоя (данные), юнитом не тестируется.

## Не входит в задачу

- Раскатка на форки (Aladdin, Lastochka, Electrohub, Foodlink, Dashop).
- Изменение семантики `publish_after_unlock`, `ProductHistory`, механики событий.
- Многословный поиск в модалке добавления (известный бэклог-кандидат из фичи shops-tabs).
