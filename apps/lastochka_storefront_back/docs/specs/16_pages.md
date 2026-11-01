# ТЗ: Страницы (Составные endpoints), ⚠️ НЕ РЕАЛИЗОВАНО

> **Статус:** roadmap. Ни один из описанных ниже составных эндпоинтов не существует в текущем коде: в `routes/api.php` нет ни `v1/home`, ни `v1/stores/{store}/page`, ни `v1/stores/{store}/catalog`. Страницы собираются из нескольких отдельных запросов. Этот документ описывает возможную будущую оптимизацию (один запрос вместо нескольких).

## Что есть сейчас

Каждая страница собирается из следующих ручек (все пути с префиксом `/api`):

| Страница | Эндпоинты |
|---|---|
| Главная | `GET /v1/stories`, `GET /v1/promotions`, `GET /v1/product-groups?placement=home&withProducts=true`, `GET /v1/stores/{store}/banners`, `GET /v1/brands`, `GET /v1/cities` |
| Страница магазина | `GET /v1/stores/{storeId}` (+`?addressId=`), `GET /v1/stores/{storeId}/menu-sections`, `GET /v1/stores/{store}/banners`, `GET /v1/stores/{store}/product-groups?placement=store_page`, `GET /v1/stores/{store}/categories`, `GET /v1/stores/{store}/categories/home` |
| Каталог магазина | `GET /v1/stores/{store}/categories` (верхний уровень), `GET /v1/stores/{store}/categories/{category}/children`, `GET /v1/stores/{store}/categories/{category}/products`, `GET /v1/stores/{store}/product-groups?placement=catalog` |
| Категория (товарный листинг) | `GET /v1/stores/{store}/categories/{category}/products` с query `page`, `perPage`, `sort`, `priceMin`, `priceMax`, `filter[code]`, `countOnly`, `cityId`, `onlyDiscounted`, `sugarFree`; дополнительно `GET /v1/stores/{store}/categories/{category}/price-range` и `GET /v1/stores/{store}/categories/{category}/filters` |
| Карточка товара | `GET /v1/products/{product}`, `GET /v1/products/{product}/similar`, `GET /v1/products/{product}/related`, `GET /v1/products/{product}/documents` |
| Поиск | `GET /v1/search/products`, `GET /v1/search/stores/{store}/products`, `GET /v1/search/suggestions`, `GET /v1/search/recommendations` |
| Фасетный каталог комплексных решений | `GET /v1/product-group-filters`, `GET /v1/product-groups?filterValues=1,3`, `GET /v1/product-groups/{productGroup}`, `GET /v1/product-groups/{productGroup}/products`, `GET /v1/product-groups/{productGroup}/siblings` |
| Баннер страницы категории | `GET /v1/stores/{store}/banners?categoryId={id}` |

Листинг категории принимает deprecated snake_case алиасы query-параметров: `price_min`, `price_max`, `count_only`, `city_id`, `only_discounted`, `sugar_free`. При передаче обоих написаний приоритет у camelCase.

Детали по каждой группе ручек:
- `03_stores.md`: магазины
- `04_catalog.md`: каталог, категории, товары
- `11_stories.md`: истории
- `12_promotions.md`: акции
- `13_product_groups.md`: товарные группы, фильтры, комплексные решения
- `14_banners.md`: баннеры
- `search_meilisearch_spec.md`, `api_search.md`, `28_search_suggestions_metro.md`: поиск

## Предполагаемые будущие составные endpoints

Раздел остаётся как roadmap: перечисленные ручки не реализованы, потребность в агрегации не подтверждена.

### GET /v1/home (не реализован)
Агрегировал бы: stories, promotions, product-groups (`placement=home`), banners, brands.

### GET /v1/stores/{storeId}/page (не реализован)
Агрегировал бы: магазин, краткие слоты доставки, banners, product-groups (`placement=store_page`), верхние категории.

### GET /v1/stores/{storeId}/catalog (не реализован)
Агрегировал бы: категории магазина, product-groups (`placement=catalog`), избранные бренды.

### GET /v1/stores/{storeId}/categories/{categoryId} (не реализован в виде одной универсальной ручки)
Текущий API разделяет это на отдельные ручки:
- `GET /v1/stores/{store}/categories/{category}/children`: подкатегории
- `GET /v1/stores/{store}/categories/{category}/products`: товары (в том числе агрегированные по поддереву родительской категории)
- `GET /v1/stores/{store}/categories/{category}/price-range`: минимальная и максимальная цена в категории
- `GET /v1/stores/{store}/categories/{category}/filters`: доступные фильтры каталога
