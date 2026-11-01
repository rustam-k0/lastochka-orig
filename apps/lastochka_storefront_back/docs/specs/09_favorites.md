# ТЗ: Избранное

## 1. Цель

Избранные товары и избранные товарные группы («решения») пользователя. Локальные сущности, не синхронизируются с внешними системами.

## 2. Контекст

- **Модуль:** Favorite
- **Сущности:** `Favorite` (товары), `ProductGroupFavorite` (товарные группы)
- **Синхронизация:** нет (локальные сущности, в обмен RabbitMQ не входят)
- **Авторизация:** все ручки под `auth:sanctum`

## 3. Данные

### 3.1 Модель Favorite

```
favorites
├── id: bigint PK
├── user_id: bigint FK → users.id (cascade on delete)
├── product_id: bigint FK → products.id (cascade on delete)
├── created_at: timestamp NULL
└── UNIQUE (user_id, product_id)
```

**Индексы:**
- `user_id, product_id` — уникальный
- `user_id, created_at` — для списка избранного

Модель не использует `updated_at` (`$timestamps = false`), `created_at` проставляется в событии `creating`. Soft delete отсутствует — удаление из избранного удаляет строку физически.

### 3.2 Модель ProductGroupFavorite (новое)

```
product_group_favorites
├── id: bigint PK
├── user_id: bigint FK → users.id (cascade on delete)
├── product_group_id: bigint FK → product_groups.id (cascade on delete)
├── created_at: timestamp NULL
└── UNIQUE (user_id, product_group_id)
```

Отдельного индекса по `created_at` нет. `updated_at` не используется, `created_at` проставляется в событии `creating`. Soft delete отсутствует.

## 4. Логика

### 4.1 getFavorites [AUTH]

**Endpoint:** `GET /api/v1/favorites`

**Query параметры:**
- `page` — int, default 1
- `perPage` — int, default 20, значения больше 100 приводятся к 100

**Поведение сервера:**
1. Выбираются записи избранного текущего пользователя, сортировка — `favorites.created_at DESC` (по дате добавления в избранное, не по товару).
2. Товар подгружается через scope `purchasable()` вместе с изображениями и счётчиками дополнений (`supplements_count`, `supplements_required_count`).
3. Записи, товар которых не прошёл `purchasable()` либо удалён, исключаются из `data`. Условия `purchasable()`: `is_active = true`, `price > 0`, `parent_id IS NULL`; при выключённом модуле конфигурируемых товаров — дополнительно `is_configurable = false`; при выключённом модуле предзаказов — дополнительно `quantity >= quantity_step` либо `is_configurable = true`, либо `stock_mode = 'ignore'`, либо магазин товара с `ignore_stock = true`.
4. `total` — общее число записей избранного пользователя, включая отфильтрованные на шаге 3. Длина `data` может быть меньше `total / perPage`.
5. `hasMore` — есть ли следующая страница пагинатора.

**Ответ 200** (плоский объект, без обёртки `{data: ...}`; внутри `data` — массив `ProductResource`, полный состав полей — в [04_catalog.md](04_catalog.md)):
```json
{
  "hasMore": true,
  "data": [
    {
      "id": 1,
      "sku": "MILK-32",
      "title": "Молоко 3.2%",
      "price": 89,
      "priceOld": 99,
      "discountPercent": 10,
      "currency": "RUB",
      "images": [
        {"id": 1, "path": "https://cdn.example.com/milk.jpg"}
      ],
      "isFavorite": true,
      "rating": 4.5,
      "reviewsCount": 12,
      "hasSupplements": false,
      "hasRequiredSupplements": false
    }
  ],
  "total": 15
}
```

> В этой ручке загружены связи `images` и счётчики дополнений, поэтому `images`, `hasSupplements`, `hasRequiredSupplements` присутствуют; `attributes` и `supplements` отдаются пустыми массивами, так как связи не загружены.

### 4.2 toggleFavorite [AUTH]

**Endpoint:** `POST /api/v1/favorites/toggle`

**Входные данные:**
```json
{
  "productId": 10
}
```

**Валидация** (`ToggleFavoriteRequest`):
- `productId` — required, integer, `exists:products,id`
- Сообщения: `productId.required` — «Укажите товар», `productId.exists` — «Товар не найден»

**Поведение сервера:**
1. Товар загружается через `findOrFail` вместе с изображениями. Фильтр активности и `purchasable()` при этом не применяется — в избранное попадает любой существующий товар. Правило `exists` не учитывает soft delete, поэтому для удалённого товара валидация проходит, а загрузка даёт 404.
2. Если запись в `favorites` есть — она удаляется.
3. Если записи нет — проверяется лимит: при 500 и более записях у пользователя выбрасывается `FavoriteLimitException`; иначе создаётся новая запись.
4. Возвращается товар; `isFavorite` в ответе вычисляется отдельным запросом и отражает состояние после переключения.

**Ответ 200:**
```json
{
  "data": {
    "id": 10,
    "sku": "MILK-32",
    "title": "Молоко 3.2%",
    "price": 89,
    "priceOld": 99,
    "currency": "RUB",
    "images": [
      {"id": 1, "path": "https://cdn.example.com/milk.jpg"}
    ],
    "isFavorite": true
  }
}
```

> Ответ **обёрнут в `{data: ...}`**. Внутри `data` — `ProductResource`. Загружена только связь `images`, счётчики дополнений не загружены, поэтому полей `hasSupplements` и `hasRequiredSupplements` в ответе нет.

**Ошибки:**
- 404 — товар не найден (soft-deleted)
- 422 — ошибка валидации `productId`
- 422 — достигнут лимит избранного:
```json
{
  "message": "Достигнут лимит избранного (максимум 500 товаров).",
  "errors": {
    "productId": ["Достигнут лимит избранного (максимум 500 товаров)."]
  }
}
```

### 4.3 getFavoriteProductGroups [AUTH] (новое)

**Endpoint:** `GET /api/v1/favorites/product-groups`

Список избранных товарных групп (комплексных решений).

**Query параметры:**
- `page` — int, default 1
- `perPage` — int, default 20, значения больше 100 приводятся к 100

**Поведение сервера:**
1. Выбираются записи `product_group_favorites` текущего пользователя, сортировка — `created_at DESC`.
2. Группа подгружается с условием `is_active = true` и счётчиком товаров (`products_count`).
3. Записи, группа которых неактивна или удалена, исключаются из `data`.
4. `total` — общее число записей избранного пользователя, включая отфильтрованные.

**Ответ 200:** плоский объект `{hasMore: bool, data: ProductGroupResource[], total: int}`. Состав `ProductGroupResource` — в [13_product_groups.md](13_product_groups.md); связи `products` и `store` в этой ручке не загружены.

### 4.4 toggleFavoriteProductGroup [AUTH] (новое)

**Endpoint:** `POST /api/v1/favorites/product-groups/toggle`

**Входные данные:**
```json
{
  "productGroupId": 5
}
```

**Валидация** (правила объявлены в контроллере):
- `productGroupId` — required, integer, `exists:product_groups,id`
- Сообщения: `productGroupId.required` — «Укажите решение», `productGroupId.exists` — «Решение не найдено»

**Поведение сервера:** если запись есть — удаляется, иначе создаётся. Лимит для товарных групп не установлен. Активность группы при переключении не проверяется.

**Ответ 200:**
```json
{"isFavorite": true}
```

Обёртки `{data: ...}` нет, сама группа в ответе не возвращается.

**Ошибки:**
- 422 — ошибка валидации `productGroupId`

### 4.5 Флаг isFavorite

**В `ProductResource`:** если у модели присутствует атрибут `is_favorite` (например, подгружен через `withExists`), берётся он; иначе выполняется `Product::isFavorite($request->user())` — отдельный `exists`-запрос. Для неавторизованного запроса — всегда `false`.

```php
// ProductResource
private function getIsFavorite(Request $request): bool
{
    if (array_key_exists('is_favorite', $this->resource->getAttributes())) {
        return (bool) $this->resource->getAttribute('is_favorite');
    }

    return $this->resource->isFavorite($request->user());
}

// Product
public function isFavorite(?User $user): bool
{
    if (! $user) {
        return false;
    }

    return $this->favorites()->where('user_id', $user->id)->exists();
}
```

**В `ProductGroupResource`:** флаг всегда вычисляется методом `ProductGroup::isFavorite($request->user())` (по таблице `product_group_favorites`), атрибут-оптимизация не поддерживается. Для неавторизованного запроса — `false`.

**Оптимизация для списков товаров (eager loading):**

```php
$products = Product::with(['images'])
    ->withExists(['favorites as is_favorite' => function ($query) {
        $query->where('user_id', auth()->id());
    }])
    ->paginate();
```

## 5. Нефункциональные требования

### Лимиты
- Максимум 500 товаров в избранном (`ToggleFavoriteAction::MAX_FAVORITES`). Превышение — 422 `FavoriteLimitException`.
- Лимит на избранные товарные группы не установлен.

## 6. Реализация

### Код
- Миграции `create_favorites_table`, `create_product_group_favorites_table`
- Модели `Favorite`, `ProductGroupFavorite`
- `FavoriteController`, `ProductGroupFavoriteController`
- `ToggleFavoriteAction` (лимит 500), `FavoriteLimitException`
- `ToggleFavoriteRequest`
- `Product::isFavorite()`, `ProductGroup::isFavorite()`, `ProductResource::getIsFavorite()`

### Routes
```php
Route::middleware('auth:sanctum')->prefix('favorites')->group(function () {
    Route::get('/', [FavoriteController::class, 'index']);
    Route::post('/toggle', [FavoriteController::class, 'toggle']);

    Route::get('/product-groups', [ProductGroupFavoriteController::class, 'index']);
    Route::post('/product-groups/toggle', [ProductGroupFavoriteController::class, 'toggle']);
});
```

Группа объявлена внутри общего префикса `v1`. Ограничения частоты запросов (`throttle`) на ручках избранного не установлены.
