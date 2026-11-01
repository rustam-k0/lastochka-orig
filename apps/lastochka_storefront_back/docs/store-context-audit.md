# Аудит приёма `storeId` по ручкам витрины (MENDEV-13)

Дата: 2026-08-09. Ветка: `feature/cities-and-stores`.

## Что проверялось

В сетевом каталоге (`network_catalog`) карточка товара глобальна, а цена, остаток
и видимость живут в оффере магазина (`store_products`). Значит любая ручка,
отдающая товар, обязана понимать, **в контексте какого магазина** его отдавать.
Контекст резолвится одним сервисом — `App\Services\Catalog\StoreOfferContext`:

1. явный `?storeId=` из запроса;
2. магазин активного адреса авторизованного пользователя;
3. без того и другого — лучшее предложение сети.

Проверены все публичные ручки `routes/api.php`, отдающие товары или зависящие
от магазина.

## Итог

| Ручка | Источник контекста | Статус |
|---|---|---|
| `GET /v1/products/{product}` | `storeId` → адрес → лучший оффер | было корректно |
| `GET /v1/products/slug/{slug}` | то же | было корректно |
| `GET /v1/products/{product}/similar`, `/related` | то же | было корректно |
| `GET /v1/products/search/barcode/{barcode}` | то же | было корректно |
| `GET /v1/stores/{store}/categories/**` | `{store}` в пути | было корректно |
| `GET /v1/stores/{store}/categories/{category}/products` | `{store}` в пути | было корректно |
| `GET /v1/stores/{store}/menu-sections` | `{store}` в пути | было корректно |
| `GET /v1/stores/{store}/product-groups`, `/products` | `store_id` группы | было корректно |
| `GET /v1/product-groups`, `/{group}`, `/{group}/products` | `store_id` группы | было корректно |
| `GET /v1/promotions`, `/{promotion}/products` | `storeId` фильтром + `store_id` акции | было корректно |
| `GET /v1/favorites` | `storeId` → адрес | было корректно |
| `GET /v1/comparison`, `/{categoryId}` | `storeId` → адрес | было корректно |
| `GET /v1/estimates/**` | `storeId` → адрес | было корректно |
| `GET /v1/search/stores/{store}/products` | `{store}` в пути | было корректно |
| **`GET /v1/search/products`** | только адрес | **пробел, закрыт** (2026-08-26 дозакрыт: `storeId` теперь определяет и доступные магазины для `categories`, не только цену) |
| **`GET /v1/search/recommendations`** | только адрес | **пробел, закрыт** (2026-08-26 дозакрыт: `storeId` определяет и отбор магазинов, адрес без покрытия больше не обнуляет подборку) |
| **`GET /v1/search/suggestions`** | `storeId` → адрес | **пробел, закрыт 2026-08-26**: без `storeId` категории резолвились по адресу и пропадали, когда адрес не покрывал выбранный на фронте магазин |
| `GET /v1/cart`, `/v1/orders/**` | `store_id` корзины/заказа | контекст фиксирован сущностью |

## Что исправлено

`GET /v1/search/products` (сетевой режим) и `GET /v1/search/recommendations`
резолвили магазин **только** из активного адреса. Последствия:

- гость не мог получить цены выбранного магазина — всегда «лучшее по сети»;
- авторизованный пользователь не мог посмотреть другой магазин, не меняя
  активный адрес;
- поведение расходилось с карточкой и листингами, где `storeId` работал.

Изменения:

- `SearchProductsRequest` / `SearchRecommendationsRequest` — валидация `storeId`
  (`sometimes|integer|min:1`) и геттер `getStoreId()`;
- `SearchService::searchNetwork()` и `getRecommendations()` — параметр
  `storeId`/`explicitStoreId` с приоритетом над адресом;
- ключ кеша сетевого поиска (`getNetworkCacheKey`) дополнен `storeId` — иначе
  ответ, посчитанный для одного магазина, отдавался бы всем остальным;
- Scramble-документация ручек.

Покрыто тестами: `tests/Feature/NetworkCatalogSearchStoreIdTest.php`
(цена по магазину, скрытие товаров без оффера, приоритет над адресом,
разделение кеша, валидация).

## Примечания

- При выключенном `network_catalog` поведение всех ручек не меняется:
  `StoreOfferContext::resolveStoreId()` возвращает 0, а поиск идёт по
  стандартной (сгруппированной по магазинам) ветке.
- Явный `storeId` принимается как есть, без проверки «доступен ли магазин
  пользователю» — это осознанно и совпадает с поведением остальных ручек
  (`StoreOfferContext::resolveStoreId`). Ограничение доступа делает выдача
  магазинов (`GET /v1/stores`), а не каталог.
