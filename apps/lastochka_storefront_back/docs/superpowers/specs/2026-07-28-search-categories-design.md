# Категории в поисковой выдаче (METRO-style)

> Дата: 2026-07-28 · Тикет: MID-171 · Модуль: mendev_storefront_back

## Проблема

При поиске товаров клиенты (МП и веб-сайт) получают только товары, сгруппированные
по магазинам. По аналогии с METRO нужно, чтобы по запросу «моло» выдача содержала
и **категории**, чьё название матчится с запросом («Молоко», «Молоко, яйцо, масло»,
«Заменители молока и детские смеси»).

Сейчас категории есть только в `GET /v1/search/suggestions?structured=true`, но
там они выводятся **из найденных товаров** (`category_ids` хитов Meilisearch), что
даёт нерелевантный шум: «моло» находит «Вспениватель молока Polaris» → в категориях
всплывает «Бытовая техника». METRO-семантика — матч по названию категории.

## Решение

### 1. `GET /v1/search/products` — новый top-level ключ `categories`

Ответ: `{hasMore, data, total, categories: CategoryResource[]}`. Ключ аддитивный,
старые клиенты его игнорируют — обратная совместимость полная.

**Матчинг** (новый приватный метод `SearchService::searchCategories()`):

- Запрос trim'ится и разбивается на слова (`preg_split('/\s+/u')`); категория
  подходит, если **каждое** слово содержится в `categories.name`
  (регистронезависимо, `mb_stripos`).
- Матчинг выполняется в PHP по полной выборке категорий-кандидатов, НЕ через SQL
  `LIKE`: на SQLite (локальные тесты) `LIKE`/`LOWER` не работают с регистром
  кириллицы, а категорий в БД мало — выборка дешёвая.
- Кандидаты: `Category::active()` + привязка хотя бы к одному доступному магазину
  (`whereHas('stores', whereIn(stores.id, $allowedStoreIds) + category_store.is_active = true)`).
- `$allowedStoreIds` — существующий `resolveAllowedStoreIds($address, $user)`
  (адрес → AvailableStoreService, иначе активные магазины по B2B/B2C типу).
  Пустой список магазинов → пустой `categories`.
- Сортировка: сначала категории, у которых название **начинается** с полного
  запроса (`mb_stripos(...) === 0`), затем по алфавиту (`mb_strtolower(name)`).
- После отбора top-N — `->load('parent')` для контекста в UI
  («Молоко · Молочные продукты»). Parent грузится после фильтрации, не на всю выборку.

**Параметр `categoriesLimit`** (+ deprecated snake-алиас `categories_limit`):

- Валидация: `sometimes, integer, min:0`.
- Дефолт 5, max 20 — через `config('meilisearch.search.default_categories_limit')`
  и `...max_categories_limit` по образцу `default_per_page`.
- `categoriesLimit=0` → секция отключена (пустой массив, поиск категорий не выполняется).

**Устойчивость:** категории считаются независимо от Meilisearch — при недоступности
Meili (graceful degradation отдаёт пустые stores) секция `categories` всё равно
наполняется. Собственный try/catch: при ошибке — пустой массив + лог.

**Кеш:** ключ кеша `search()` дополняется `categoriesLimit`. TTL и отсутствие
адреса в ключе — существующее поведение, не меняется.

**Пагинация:** `categories` не пагинируется и возвращается на каждой странице
(stateless); клиент показывает секцию на первой странице.

### 2. `GET /v1/search/suggestions?structured=true` — METRO-семантика секции `categories`

- Сначала name-match категории (тот же `searchCategories()`, до `limit`),
  затем добивка существующей логикой (категории найденных товаров) до `limit`
  суммарно, дедуп по `id`.
- Формат элементов не меняется: `[{id, name}]`. Плоский режим (`structured=false`)
  не затрагивается.
- Обработка ошибок не меняется (общий try/catch, при ошибке — пустые секции).

### 3. Навигация по тапу — на стороне клиентов

У клиентов уже есть фильтр `categoryId` в `/search/products` и каталожные экраны.
Контракт отдаёт `id`, `name`, `slug`, `image`, `parent` — этого достаточно.

## Не делаем

- Meilisearch-индекс категорий (typo-tolerance) — отдельный индекс, observer,
  reindex ради таблицы в сотни строк не окупается; LIKE-семантики достаточно.
- Флаг модуля (`projectModules`) — изменение аддитивное, гейтить нечем и незачем.
- Фильтр «категория не пуста по purchasable-товарам» — каталожное дерево
  (`CategoryController::index`) пустые категории не скрывает; сохраняем консистентность.

## Влияние на другие форки

Синхронизация только через ручной sync-core. Изменение fail-safe: нет миграций,
env-переменных и новых таблиц; новый ключ ответа аддитивный; формат structured
suggestions не меняется (меняется состав — в сторону релевантности).

## Файлы

| Файл | Изменение |
|---|---|
| `app/Services/Search/SearchService.php` | `searchCategories()`, параметр `categoriesLimit` в `search()`, ключ кеша, интеграция в `executeSearch()` и `getSuggestions()` |
| `app/Http/Requests/Api/V1/SearchProductsRequest.php` | правило + snake-алиас + `getCategoriesLimit()` |
| `app/Http/Controllers/Api/V1/SearchController.php` | `categories` в ответе, Scramble-атрибуты (`@response`, `QueryParameter`) |
| `config/meilisearch.php` | `default_categories_limit`, `max_categories_limit` |
| `docs/api_search.md` | контракт для МП/веба: `categories` в searchProducts, новая семантика structured suggestions |
| `tests/Feature/Api/V1/Search/SearchProductsTest.php` | тесты секции `categories` |
| `tests/Feature/Api/V1/Search/SearchSuggestionsTest.php` | тесты name-match + добивка + дедуп |

## Тесты

`/search/products`:
1. Категории по имени приходят в ответе (id, name, slug, image).
2. Мультисловный запрос — все слова обязаны входить в название (AND).
3. Регистронезависимость кириллицы («МОЛО» находит «молоко»).
4. Неактивная категория исключена.
5. Категория, привязанная только к недоступному магазину (или с
   `category_store.is_active=false`), исключена.
6. `categoriesLimit` ограничивает выдачу; `categoriesLimit=0` отключает секцию.
7. Сортировка: prefix-совпадения раньше подстрочных.
8. Категории возвращаются и при нуле товарных хитов.
9. С активным адресом — только категории магазинов, доступных по адресу.

`/search/suggestions?structured=true`:
10. Name-match категории идут первыми, дедуп с категориями из товаров.
11. Секция ограничена `limit`; добивка работает при нехватке name-match.
