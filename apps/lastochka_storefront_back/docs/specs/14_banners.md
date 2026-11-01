# ТЗ: Баннеры (Banners)

## 1. Цель

Баннеры магазина с переходом на товар, категорию или другую сущность из общего enum ссылок. Баннер размещается либо на главной странице, либо на странице конкретной категории.

## 2. Контекст

- **Модуль:** Banner
- **Источник данных:** админ-панель, входящая синхронизация через RabbitMQ (routing key `merchant_banners`)
- **Авторизация:** ручка публичная, токен не требуется и на состав ответа не влияет
- **Пагинация:** отсутствует, список отдаётся целиком

## 3. Данные

### 3.1 Таблица banners

```
banners
├── id: bigint PK
├── store_id: bigint FK → stores.id NOT NULL (ON DELETE CASCADE)
├── source: string NULL                          // источник записи (внешняя система)
├── external_id: string NULL                     // id записи во внешней системе
├── title: string(200) NOT NULL
├── image_path: string(500) NOT NULL
├── link_type: string(20) DEFAULT 'none'
├── link_product_id: bigint FK → products.id NULL (ON DELETE SET NULL)
├── link_category_id: bigint FK → categories.id NULL (ON DELETE SET NULL)
├── display_category_id: bigint FK → categories.id NULL (ON DELETE SET NULL)  // новое
├── position: int DEFAULT 0
├── is_active: boolean DEFAULT true
├── starts_at: datetime NULL
├── ends_at: datetime NULL
├── custom_checkboxes: json NULL                 // динамические чекбоксы (новое)
├── created_at: timestamp
├── updated_at: timestamp
└── deleted_at: timestamp NULL
```

**Индексы:**
- `store_id, is_active, position`

**Ограничения целостности:**
- Внешние ключи на `products` и `categories` с обнулением при удалении связанной записи.
- Принадлежность товара или категории тому же магазину на уровне БД и на уровне storefront-кода не проверяется: за это отвечает источник данных.

`display_category_id` определяет место показа: `NULL` соответствует баннеру главной страницы, заполненное значение соответствует баннеру страницы указанной категории. `link_category_id` определяет цель перехода и с `display_category_id` не связан.

### 3.2 Ответ API (BannerResource)

```json
{
  "id": 1,
  "storeId": 1,
  "title": "Скидки на молочку",
  "image": {"id": 1, "path": "https://cdn.example.com/banner.jpg"},
  "linkType": "category",
  "linkProductId": null,
  "linkCategoryId": 5,
  "displayCategoryId": null,
  "customCheckboxes": {}
}
```

| Поле | Тип | Комментарий |
|---|---|---|
| `id` | int | |
| `storeId` | int | Присутствует всегда |
| `title` | string | |
| `image` | object | `{id, path}`, где `id` совпадает с id баннера, `path` содержит полный URL. Колонка `image_path` не допускает NULL, поэтому объект присутствует всегда |
| `linkType` | string | Значение общего enum `App\Enums\LinkType`: `none`, `product`, `category`, `store`, `shop`, `external`. Для баннеров обычно приходят `none`, `product`, `category`, но RabbitMQ-трансформер может записать `store` или `external` |
| `linkProductId` | int\|null | Может быть null |
| `linkCategoryId` | int\|null | Может быть null |
| `displayCategoryId` | int\|null | Категория, на странице которой показывается баннер; null соответствует баннеру главной (новое) |
| `customCheckboxes` | object | Динамические чекбоксы, map `код флага` → bool (новое). При отсутствии значения отдаётся `{}` |

Путь изображения приводится к полному URL: значение, начинающееся с `http://` или `https://`, отдаётся как есть, относительный путь разворачивается через публичный диск Storage.

## 4. Логика

### 4.1 Баннеры магазина

**Endpoint:** `GET /api/v1/stores/{store}/banners`

**Query параметры:**
- `categoryId`: int, optional (новое). Без параметра отдаются только баннеры главной страницы (`display_category_id IS NULL`). С параметром отдаются только баннеры страницы указанной категории (`display_category_id = categoryId`).
- `placement`: string, optional. Ключ кастомного чекбокса баннера (место показа). Отдаются только баннеры, у которых `custom_checkboxes->{placement} = true`. Комбинируется с `categoryId` через AND. Без параметра (или с пустым значением) тумблеры не учитываются: это слайдер главной.

Параметр `categoryId` учитывается, если он непустой; значение приводится к int, нечисловое значение даёт 0 и, как следствие, пустой список. Принадлежность категории магазину не проверяется.

Допустимые значения `placement` не зашиты в код: это поля секции `MerchantBanner` настройки `entity_checkboxes` (приходит из admin_back через admin_settings-событие, хранится в `storefront_settings`), у которых `custom = true` и `enabled = true` и поле проходит regex слуга `^[a-z][a-z0-9_]{1,49}$`. Список отдаёт `ModuleConfigService::bannerPlacements()` (кеш 10 минут, ключ `module_config:entity_checkboxes:banner_placements`, сбрасывается в `invalidateCache()` при каждом admin_settings-событии). Ключ вне списка (в т.ч. выключенный в админке) даёт 422 с перечнем допустимых; при пустой секции 422 «Места показа баннеров не настроены». Валидация через closure-правило в `GetStoreBannersRequest`, а не `Rule::in`: список динамический, а `Rule::in` зафиксировал бы его в OpenAPI при генерации доки.

На Electrohub (сентябрь 2026) заведены шесть мест: `main_after_catalog`, `main_after_recommended`, `main_after_solutions`, `catalog_listing`, `product_card`, `solution_page`. На других форках секция `MerchantBanner` пуста, и любой `placement` даёт 422.

**Логика:**
1. Отбираются баннеры указанного магазина с `is_active = true`.
2. Фильтр по датам: `starts_at IS NULL OR starts_at <= now()` и `ends_at IS NULL OR ends_at >= now()`.
3. Применяется фильтр места показа по `categoryId` (см. выше).
4. При переданном `placement` дополнительно `custom_checkboxes->{placement} = true` (`Banner::scopeForPlacement`). Ключ `false`, отсутствующий ключ и `custom_checkboxes IS NULL` не проходят.
5. Сортировка по `position` по возрастанию.

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "storeId": 1,
      "title": "Скидки на молочку",
      "image": {"id": 1, "path": "https://cdn.example.com/banner.jpg"},
      "linkType": "category",
      "linkProductId": null,
      "linkCategoryId": 5,
      "displayCategoryId": null,
      "customCheckboxes": {}
    }
  ]
}
```

Если подходящих баннеров нет, отдаётся `{"data": []}`.

**Ошибки:**
- 404: магазин не найден.
- 422: `placement` вне списка допустимых. Тело: `{"message": "...", "errors": {"placement": ["Допустимые значения: main_after_catalog, product_card"]}}`; при пустой секции настроек `["Места показа баннеров не настроены"]`.

### 4.2 Скоупы модели

```php
// Только активные
public function scopeActive(Builder $query): Builder
{
    return $query->where('is_active', true);
}

// Баннеры главной страницы
public function scopeForMainPage(Builder $query): Builder
{
    return $query->whereNull('display_category_id');
}

// Баннеры страницы категории
public function scopeForDisplayCategory(Builder $query, int $categoryId): Builder
{
    return $query->where('display_category_id', $categoryId);
}

// Баннеры места показа: кастомный чекбокс включён.
// Ключ интерполируется в JSON-path, поэтому до вызова он обязан
// пройти через список ModuleConfigService::bannerPlacements() (regex слуга).
public function scopeForPlacement(Builder $query, string $placement): Builder
{
    return $query->where("custom_checkboxes->{$placement}", true);
}

// Фильтр по датам показа
public function scopeWithinDates(Builder $query): Builder
{
    return $query
        ->where(function ($q) {
            $q->whereNull('starts_at')
              ->orWhere('starts_at', '<=', now());
        })
        ->where(function ($q) {
            $q->whereNull('ends_at')
              ->orWhere('ends_at', '>=', now());
        });
}
```

## 5. Реализация

### Код
- [x] Миграция `create_banners_table` (+ `add_source_to_banners_table`, `add_display_category_to_banners_table`, `add_custom_checkboxes_to_content_entities`)
- [x] Модель `Banner` с factory, связи `store`, `linkProduct`, `linkCategory`, `displayCategory`
- [x] `BannerController`
- [x] `GetStoreBannersRequest` (валидация `placement` по `ModuleConfigService::bannerPlacements()`)
- [x] `ModuleConfigService::bannerPlacements()` (+ общий `customCheckboxes()` с `productFlagFilters()`)
- [x] `BannerResource`
- [x] Scopes: `active()`, `withinDates()`, `forMainPage()`, `forDisplayCategory()`, `forPlacement()`

### Тесты (`tests/Feature/Api/V1/Banner/BannerTest.php`)
- [x] `test_get_store_banners`, `test_get_store_banners_returns_correct_structure`
- [x] `test_get_store_banners_excludes_inactive`, `test_get_store_banners_excludes_other_store_banners`
- [x] `test_get_store_banners_returns_empty_for_no_banners`, `test_get_store_banners_returns_404_for_nonexistent_store`
- [x] `test_banners_endpoint_returns_only_main_page_banners_by_default`, `test_banners_endpoint_filters_by_category`
- [x] `test_category_banners_respect_active_and_dates`, `test_banners_endpoint_returns_empty_for_category_without_banners`
- [x] `test_placement_returns_only_banners_with_flag_true`, `test_placement_keeps_position_order_and_active_dates_filters`
- [x] `test_placement_is_combined_with_category_id`, `test_placement_scoped_to_store`
- [x] `test_without_placement_returns_all_main_banners_regardless_of_flags`, `test_empty_placement_is_ignored`
- [x] `test_unknown_placement_returns_422_with_allowed_values`, `test_disabled_placement_returns_422`, `test_placement_returns_422_when_no_placements_configured`
- [x] `test_banners_filtered_by_dates`, `test_banners_with_only_starts_at`, `test_banners_with_only_ends_at`

### Тесты (`tests/Unit/BannerPlacementsTest.php`)
- [x] `test_returns_only_custom_enabled_with_valid_slug`, `test_label_falls_back_to_field`
- [x] `test_returns_empty_on_missing_or_broken_config`, `test_does_not_share_cache_with_product_flag_filters`, `test_invalidate_cache_resets_placements`

### Routes
```php
Route::prefix('v1')->group(function () {
    Route::get('stores/{store}/banners', [BannerController::class, 'index']);
});
```
