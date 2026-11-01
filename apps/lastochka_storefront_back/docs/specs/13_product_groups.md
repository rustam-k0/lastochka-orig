# ТЗ: Товарные группы (Product Groups)

## 1. Цель

Товарные группы: кастомные подборки товаров магазина с размещением на главной, на странице поиска, на странице магазина, в каталоге и на странице корзины. Отдельный подвид групп: комплексные решения, которые не участвуют в местах размещения и подбираются фасетными фильтрами.

## 2. Контекст

- **Модуль:** ProductGroup
- **Источник данных:** админ-панель, входящая синхронизация через RabbitMQ (routing keys `merchant_product_groups`, `product_group_filters`)
- **Авторизация:** три ручки работают под `OptionalAuth` (Bearer опционален), остальные публичные
- **Особенность:** группа с `is_complex_solution = true` исключена из выдачи по местам размещения и наоборот

## 3. Данные

### 3.1 Таблица product_groups

```
product_groups
├── id: bigint PK
├── store_id: bigint FK → stores.id NOT NULL (ON DELETE CASCADE)
├── source: string NULL                         // источник записи (внешняя система)
├── external_id: string NULL                    // id записи во внешней системе
├── title: string(200) NOT NULL
├── description: text NULL
├── image_path: string(500) NULL
├── meta: json NULL                             // произвольные мета-данные
├── show_on_store_page: boolean DEFAULT false   // на странице магазина
├── show_in_catalog: boolean DEFAULT false      // в каталоге магазина
├── show_on_home: boolean DEFAULT false         // на главной
├── show_on_search: boolean DEFAULT false       // на странице поиска (новое)
├── is_recommended: boolean DEFAULT false
├── is_cart_page: boolean DEFAULT false         // на странице корзины
├── is_complex_solution: boolean DEFAULT false  // комплексное решение (новое)
├── position: int DEFAULT 0
├── is_active: boolean DEFAULT true
├── custom_checkboxes: json NULL                // динамические чекбоксы (новое)
├── created_at: timestamp
├── updated_at: timestamp
└── deleted_at: timestamp NULL
```

**Индексы:**
- `store_id, is_active, show_on_store_page`
- `store_id, is_active, show_in_catalog`
- `is_complex_solution`

### 3.2 Таблица product_group_items (pivot)

```
product_group_items
├── id: bigint PK
├── product_group_id: bigint FK → product_groups.id (ON DELETE CASCADE)
├── product_id: bigint FK → products.id (ON DELETE CASCADE)
├── position: int DEFAULT 0
├── quantity: unsigned int DEFAULT 1            // количество товара в группе (новое)
├── created_at: timestamp NULL
└── UNIQUE (product_group_id, product_id)
```

### 3.3 Таблицы фильтров товарных групп (новое)

```
product_group_filters                      product_group_filter_values
├── id: bigint PK                          ├── id: bigint PK
├── name: string(100) NOT NULL             ├── product_group_filter_id: bigint FK (CASCADE)
├── position: int DEFAULT 0                ├── value: string(100) NOT NULL
├── is_active: boolean DEFAULT true        ├── image_path: string(500) NULL
├── created_at / updated_at                ├── position: int DEFAULT 0
└── deleted_at: timestamp NULL             ├── is_active: boolean DEFAULT true
                                           ├── created_at / updated_at
                                           └── deleted_at: timestamp NULL

product_group_filter_value (pivot)
├── id: bigint PK
├── product_group_id: bigint FK → product_groups.id (CASCADE)
├── product_group_filter_value_id: bigint FK → product_group_filter_values.id (CASCADE)
├── created_at / updated_at
└── UNIQUE (product_group_id, product_group_filter_value_id)
```

### 3.4 Таблица product_group_favorites (новое)

```
product_group_favorites
├── id: bigint PK
├── user_id: bigint FK → users.id (ON DELETE CASCADE)
├── product_group_id: bigint FK → product_groups.id (ON DELETE CASCADE)
├── created_at: timestamp NULL
└── UNIQUE (user_id, product_group_id)
```

Ручки управления избранными группами (`GET /v1/favorites/product-groups`, `POST /v1/favorites/product-groups/toggle`) описаны в `09_favorites.md`.

### 3.5 Ответ API (ProductGroupResource)

```json
{
  "id": 1,
  "title": "Хиты продаж",
  "description": "Самые популярные товары сезона",
  "image": {"id": 1, "path": "https://cdn.example.com/group.jpg"},
  "meta": {"subtitle": "Самые популярные товары"},
  "productsCount": 12,
  "is_recommended": false,
  "isComplexSolution": false,
  "isFavorite": false,
  "customCheckboxes": {}
}
```

Поля `products` и `store` условные: они появляются только в тех ручках, где загружены соответствующие связи (см. таблицу ниже).

| Поле | Тип | Комментарий |
|---|---|---|
| `id` | int | |
| `title` | string | |
| `description` | string\|null | Может быть null |
| `image` | object\|null | `{id, path}`, где `id` совпадает с id группы. Null, если `image_path` не заполнен |
| `meta` | object\|null | Произвольный JSON из админки. Может быть null |
| `productsCount` | int | Количество товаров группы, доступных к покупке (те же условия, что и для списка товаров в п. 4.4) |
| `products` | array | Присутствует только когда товары загружены: при `withProducts=true` в п. 4.1 и 4.2. Элемент: `ProductResource` (см. `04_catalog.md`). Изображения товаров в этом ответе не подгружаются, поэтому `images` у элемента пустой массив. Поле `groupQuantity` содержит `quantity` из pivot |
| `store` | object | Присутствует только когда магазин загружен: в п. 4.1 и 4.3. Формат `StoreResource` (см. `03_stores.md`), без блока `deliveryInfo` и без `city` |
| `is_recommended` | bool | Историческое имя в snake_case, остальные поля в camelCase. Приведение к camelCase будет breaking change |
| `isComplexSolution` | bool | Признак комплексного решения (новое) |
| `isFavorite` | bool | Признак избранной группы для текущего пользователя. Под `OptionalAuth` заполняется по Bearer-токену, без токена и в ручках без `OptionalAuth` равен `false` |
| `customCheckboxes` | object | Динамические чекбоксы, map `код флага` → bool (новое). При отсутствии значения отдаётся `{}` |

## 4. Логика

### 4.1 Группы для главной, поиска и корзины `[OPTIONAL AUTH]`

**Endpoint:** `GET /api/v1/product-groups`

**Query параметры:**

| Параметр | Тип | Правила |
|---|---|---|
| `placement` | string | `home` или `search` (новое: значение `search`). Обязателен, если не переданы ни `isCartPage`, ни `filterValues` |
| `isCartPage` | bool | Optional. Фильтр по колонке `is_cart_page`: `true` отбирает группы корзины, `false` отбирает группы с `is_cart_page = false` |
| `is_cart_page` | bool | Deprecated snake_case алиас `isCartPage`; при передаче обоих приоритет у `isCartPage` |
| `withProducts` | bool | Optional, default false |
| `productsLimit` | int | Optional, default 4, min 1, max 20 |
| `filterValues` | string | Optional. CSV из положительных целых, id значений `product_group_filter_values` (например `1,3,5`) |

**Middleware:** `OptionalAuth`. Bearer опционален, при валидном токене заполняется `isFavorite`.

**Логика:**
1. Отбираются группы с `is_active = true`, сортировка по `position` по возрастанию.
2. `placement=home` добавляет условие `show_on_home = true`, `placement=search` добавляет `show_on_search = true`.
3. Переданный `isCartPage` добавляет условие по колонке `is_cart_page` с этим значением.
4. При переданном `filterValues` выдача ограничивается комплексными решениями (`is_complex_solution = true`), и для каждого id значения добавляется отдельное условие «у группы есть это значение фильтра», то есть значения объединяются по AND. Без `filterValues` комплексные решения из выдачи исключаются (`is_complex_solution = false`).
5. При `withProducts=true` подгружаются товары группы с лимитом `productsLimit` и счётчиками добавок.
6. Всегда подгружаются магазин и счётчик товаров.

Параметры комбинируются: например `placement=home&filterValues=1,3` отбирает комплексные решения с `show_on_home = true` и обоими значениями фильтров.

**Response 200:** `{"data": [ProductGroupResource, ...]}`. Пагинации нет.

**Ошибки:**
- 422: `placement` не передан при отсутствии `isCartPage` и `filterValues`, значение `placement` вне списка, `productsLimit` вне диапазона 1..20, `filterValues` не соответствует формату CSV из положительных целых.

### 4.2 Группы магазина `[OPTIONAL AUTH]`

**Endpoint:** `GET /api/v1/stores/{store}/product-groups`

**Query параметры:**

| Параметр | Тип | Правила |
|---|---|---|
| `placement` | string | `store_page`, `catalog` или `all`, default `all` |
| `withProducts` | bool | Optional, default false |
| `productsLimit` | int | Optional, default 4, min 1, max 20 |

**Middleware:** `OptionalAuth`.

**Логика:**
1. Отбираются группы указанного магазина с `is_active = true`, сортировка по `position` по возрастанию.
2. Фильтр по `placement`: `store_page` добавляет `show_on_store_page = true`, `catalog` добавляет `show_in_catalog = true`, `all` не добавляет условий. Комплексные решения здесь не исключаются.
3. При `withProducts=true` подгружаются товары с лимитом `productsLimit` и счётчиками добавок.
4. Подгружается счётчик товаров. Магазин не подгружается, поэтому поле `store` в элементах отсутствует.

**Response 200:** `{"data": [ProductGroupResource, ...]}`. Пагинации нет.

**Ошибки:**
- 404: магазин не найден.
- 422: `placement` вне списка, `productsLimit` вне диапазона 1..20.

### 4.3 Детали группы `[OPTIONAL AUTH]`

**Endpoint:** `GET /api/v1/product-groups/{productGroup}`

**Middleware:** `OptionalAuth`.

**Логика:** группа ищется по id, проверяется `is_active`, подгружаются магазин и счётчик товаров. Товары не подгружаются, поле `products` отсутствует.

**Response 200:** `{"data": {...ProductGroupResource...}}`.

**Ошибки:**
- 404: группа не найдена, мягко удалена или неактивна.

### 4.4 Товары группы

**Endpoint:** `GET /api/v1/product-groups/{productGroup}/products`

**Query параметры:**
- `page`: int, default 1
- `perPage`: int, default 20, значения больше 100 усекаются до 100

**Логика:**
1. Проверяется `is_active` группы.
2. Отбираются товары группы, доступные к покупке: `is_active = true`, `price > 0`, не вариация (`parent_id IS NULL`); при выключенном модуле `configurable_products` дополнительно `is_configurable = false`; при выключенном модуле `preorders` дополнительно требуется наличие (остаток покрывает шаг покупки, либо товар или магазин работает в режиме «не учитывать остатки»).
3. Сортировка по `position` из pivot.
4. Подгружаются счётчики добавок. Изображения не подгружаются, поэтому `images` у товаров пустой массив.

Ручка не подключает `OptionalAuth`, поэтому `isFavorite` у товаров равен `false`.

**Response 200:** `{"hasMore": bool, "data": [ProductResource, ...], "total": int}`.

**Ошибки:**
- 404: группа не найдена, мягко удалена или неактивна.

### 4.5 Похожие решения (новое)

**Endpoint:** `GET /api/v1/product-groups/{productGroup}/siblings`

Возвращает комплексные решения с теми же значениями нестилевых фильтров (например Комната и Площадь), что и у исходной группы.

**Логика:**
1. Проверяется `is_active` группы.
2. Значения фильтров исходной группы группируются по имени фильтра. Значения фильтра с именем `Стиль` (регистр не важен) в подбор не входят, остальные образуют набор совпадения.
3. Если нестилевых значений нет, отдаётся пустой список.
4. Отбираются группы с `is_active = true` и `is_complex_solution = true`, исключая исходную, у которых есть все значения из набора совпадения (AND).
5. Сортировка по `position` по возрастанию, подгружается счётчик товаров. Магазин и товары не подгружаются, поэтому поля `store` и `products` отсутствуют.

Ручка не подключает `OptionalAuth`, поэтому `isFavorite` равен `false`.

**Response 200:** `{"data": [ProductGroupResource, ...]}`.

**Ошибки:**
- 404: группа не найдена, мягко удалена или неактивна.

### 4.6 Справочник фильтров товарных групп (новое)

**Endpoint:** `GET /api/v1/product-group-filters`

Публичная ручка. Работает при включённом модуле `product_group_filters`: если модуль выключен или его значение не читается, отдаётся `{"data": []}`.

**Логика:** отбираются фильтры с `is_active = true`, сортировка по `position`; у каждого фильтра отдаются значения с `is_active = true`, отсортированные по `position`.

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Стиль",
      "position": 1,
      "values": [
        {"id": 10, "value": "Минимализм", "image": {"id": 10, "path": "https://cdn.example.com/style.jpg"}, "position": 0},
        {"id": 11, "value": "Лофт", "image": null, "position": 1}
      ]
    }
  ]
}
```

`image` у значения равен null, если `image_path` не заполнен.

## 5. Использование групп на страницах

Составных ручек, которые отдавали бы группы вместе с содержимым страницы, нет. Группы запрашиваются отдельно:

- главная: `GET /v1/product-groups?placement=home`;
- страница поиска: `GET /v1/product-groups?placement=search`;
- страница корзины: `GET /v1/product-groups?isCartPage=true`;
- страница магазина: `GET /v1/stores/{store}/product-groups?placement=store_page`;
- каталог магазина: `GET /v1/stores/{store}/product-groups?placement=catalog`;
- фасетный каталог комплексных решений: `GET /v1/product-group-filters` для справочника и `GET /v1/product-groups?filterValues=...` для выдачи.

Ответ `GET /v1/stores/{storeId}` товарные группы не содержит.

## 6. Реализация

### Код
- [x] Миграции `create_product_groups_table`, `create_product_group_items_table` и надстройки: `add_source_*`, `add_is_recommended_is_cart_page_*`, `add_description_*`, `add_quantity_to_product_group_items_table`, `add_show_on_search_*`, `add_is_complex_solution_*`, `add_custom_checkboxes_to_content_entities`
- [x] Миграции `create_product_group_filters_tables`, `create_product_group_favorites_table`
- [x] Модели `ProductGroup`, `ProductGroupItem`, `ProductGroupFilter`, `ProductGroupFilterValue`, `ProductGroupFavorite`
- [x] `ProductGroupController`, `ProductGroupFilterController`
- [x] `GetProductGroupsRequest`, `GetStoreProductGroupsRequest`
- [x] `ProductGroupResource`, `ProductGroupFilterResource`, `ProductGroupFilterValueResource`

### Тесты
- [x] `ProductGroupTest`: главная и поиск (`test_get_home_product_groups`, `test_get_search_product_groups`, валидация `placement`), фасетные фильтры (`test_filter_values_uses_AND_filtering`, `test_filter_values_with_invalid_format_returns_422`, `test_placement_home_with_filter_values_intersection`), группы магазина и `placement` (`test_filter_by_placement_store_page`, `..._catalog`, `..._all`), `withProducts` и лимиты, детали группы, листинг товаров и пагинация
- [x] `ProductGroupComplexSolutionTest`: исключение комплексных решений из `home`, `search` и корзины, выдача только комплексных при `filterValues`, флаг `isComplexSolution` в ресурсе
- [x] `ProductGroupSiblingsTest`: подбор по нестилевым фильтрам, пустой ответ без фильтров, 404 для неактивной группы

### Routes
```php
Route::prefix('v1')->group(function () {
    Route::get('product-group-filters', [ProductGroupFilterController::class, 'index']);                 // Справочник фильтров
    Route::get('product-groups', [ProductGroupController::class, 'home'])                                // Главная / поиск / корзина / фасеты
        ->middleware(OptionalAuth::class);
    Route::get('stores/{store}/product-groups', [ProductGroupController::class, 'index'])                // Страница магазина
        ->middleware(OptionalAuth::class);
    Route::get('product-groups/{productGroup}', [ProductGroupController::class, 'show'])                 // Карточка группы
        ->middleware(OptionalAuth::class);
    Route::get('product-groups/{productGroup}/products', [ProductGroupController::class, 'products']);   // Листинг товаров
    Route::get('product-groups/{productGroup}/siblings', [ProductGroupController::class, 'siblings']);   // Похожие решения
});
```
