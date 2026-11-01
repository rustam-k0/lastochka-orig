# Отображение магазина в карточке товара (create/edit)

## Проблема

В форме товара курьерской системы (`aladdin_admin_front`, модуль `products`) пользователь не видит, к какому магазину принадлежит товар, который он сейчас просматривает, редактирует или создаёт. Магазин определяется параметром `shop_id` в URL (`/admin/product/:shop_id?/:id?` и `/admin/configurable-product/:shop_id?/:id?`), но нигде на странице не отображается.

## Контекст (как это устроено сейчас)

- `products.shop_id` — реальная колонка в БД (`aladdin_admin_back`), связь Product↔Shop строго 1:1 (подтверждено constraint'ом `unique(shop_id, articul)` в миграции `add_unique_articul_shop_products`). Мультивендорности на уровне товара нет.
- GraphQL-тип `Product` не содержит поля `shop`/`shop_id` — колонка используется только как write-only аргумент мутаций `ProductCreate`/`ProductUpdate` (обе реализованы одним резолвером `Product\ProductUpdate`).
- Роут формы товара всегда получает `shop_id` в параметрах URL — проверено во всех точках входа: список товаров (`src/views/products/index.vue:55,372`), карантин агрегатора (`src/views/aggregator/quarantine/index.vue:300`). И создание, и переход к редактированию всегда идут через магазин-скоуп (список товаров не показывает таблицу, пока магазин не выбран).
- В схеме уже есть готовый одиночный query `shop(id: Int): Shop`, отдающий `title` (используется, например, на странице редактирования магазина, `src/graphql/pages/Stores/shop.graphql`).
- Оба компонента формы товара — `src/views/products/open.vue` (простой товар) и `src/views/products/configurable.vue` (вариативный товар) — на `mounted()` вызывают общий query `src/graphql/pages/Product/options.graphql` с переменной `$shop_id`, причём **в обоих режимах: создание и редактирование**.

## Решение

Не требует изменений бэкенда. Переиспользуем существующий query `options.graphql` и существующее поле схемы `shop(id: Int): Shop`.

### 1. Данные

Расширить `src/graphql/pages/Product/options.graphql`, добавив в тот же запрос:

```graphql
query($shop_id: Int) {
  shop(id: $shop_id) {
    id
    title
  }
  shop_categories(shop_id: $shop_id) {
    ...
  }
}
```

Никакого нового сетевого запроса — данные магазина приходят в том же ответе, что и дерево категорий, уже сейчас запрашиваемое обоими компонентами формы.

### 2. Компоненты

В `open.vue` и `configurable.vue`:
- Добавить `shop: null` в `data()`.
- В обработчике `.then()` запроса `OPTIONS` (метод `getOptions()`) добавить `this.shop = data.shop ?? null;`.

### 3. Отображение

Оба шаблона имеют разную структуру (в `open.vue` есть блоки `product-page__status`, в `configurable.vue` их нет), но у обоих есть заголовок страницы. Показываем магазин там — единообразно для create и edit, без скролла:

`open.vue`:
```html
<div class="product-page__header-title">
  <span v-if="shop?.title" class="product-page__shop-label">Магазин: {{ shop.title }}</span>
  <span>{{ id ? form.title.value : "Новый товар" }}</span>
</div>
```

`configurable.vue` (в `configurable.vue` уже есть прецедент бейджа рядом с заголовком — `<span class="configurable-page__badge">Вариативный</span>` — используем ту же визуальную идею):
```html
<div class="configurable-page__header-title">
  <span v-if="shop?.title" class="configurable-page__shop-label">Магазин: {{ shop.title }}</span>
  <span>{{ id ? form.title.value : "Новый вариативный товар" }}</span>
  <span v-if="id" class="configurable-page__badge">Вариативный</span>
</div>
```

Текстовый префикс «Магазин: » нужен, чтобы не полагаться только на цвет/расположение — бейдж однозначно читается сам по себе. `v-if="shop?.title"` скрывает лейбл, пока запрос `OPTIONS` не отработал (без него доля секунды показывался бы пустой префикс). Стили `*-page__shop-label` — небольшой некликабельный лейбл/бейдж (аналогично уже существующим `*-page__badge`), не input — редактировать негде, поэтому поле автоматически нередактируемо.

## Вне scope

- Список товаров (`index.vue`) — там уже есть фильтр по магазину, задача касается только карточки (create/edit).
- Мутации `ProductCreate`/`ProductUpdate` — `shop_id` остаётся write-only аргументом из URL, поведение не меняется.
- Backend/GraphQL-схема `aladdin_admin_back` — изменений не требуется.

## Проверка (e2e)

- Открыть существующий товар через список (`/admin/product/:shop_id/:id`) — в заголовке виден магазин, совпадающий с выбранным в фильтре списка.
- Нажать «Добавить товар» из списка (`/admin/product/:shop_id`, без `id`) — в заголовке виден тот же магазин ещё до сохранения.
- То же для вариативного товара (`configurable.vue`).
- Открыть карточку товара по ссылке из карантина агрегатора — магазин отображается корректно.
