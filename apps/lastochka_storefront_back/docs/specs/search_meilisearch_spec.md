# ТЗ: Интеграция Meilisearch для поиска товаров

> **Спецификация API**: [docs/api_search.md](../api_search.md)

---

## 1. Цель

Реализовать полнотекстовый поиск товаров через Meilisearch с группировкой результатов по магазинам. Результат: API эндпоинты согласно `api_search.md`, обеспечивающие поиск с латенси < 50ms на 100k+ товаров.

---

## 2. Контекст

### Где живёт
- Новый сервис: `App\Services\SearchService`
- Новый контроллер: `App\Http\Controllers\Api\V1\SearchController`
- Маршруты: `routes/api.php` (группа `/api/v1/search/`)

### Триггеры индексации
| Событие | Действие |
|---------|----------|
| Product created/updated | Добавить/обновить документ в индексе |
| Product deleted | Удалить документ из индекса |
| Product `is_active` → false | Удалить из индекса |
| Product `quantity` → 0 | Оставить в индексе (фильтр при поиске) |
| RabbitMQ `product.*` события | Переиндексация затронутых товаров |

### Связь с системой
```
Flutter App → SearchController → SearchService → Meilisearch
                                      ↓
                               Product Model (для обогащения данных)
```

---

## 3. Данные

### Входные (поисковый запрос)

```php
// searchProducts  —  GET /api/v1/search/products (OptionalAuth, throttle 100/min)
[
    'query' => string,           // required, min:2
    'page' => int,               // default:1 (пагинация магазинов)
    'perPage' => int,            // default:10, max:50
    'productsPerStore' => int,   // default:10, max:50
    'only_discounted' => bool,   // optional (snake_case)
    'sugar_free' => bool,        // optional (snake_case)
    'brand_id' => int,           // optional (snake_case)
    'category_id' => int,        // optional (snake_case)
]

// searchProductsInStore  —  GET /api/v1/search/stores/{storeId}/products  (throttle 100/min)
// storeId передаётся В ПУТИ URL (path parameter), НЕ в query!
[
    'query' => string,           // required, min:2
    'page' => int,               // default:1
    'perPage' => int,            // default:20, max:100
    'only_discounted' => bool,   // optional
    'sugar_free' => bool,        // optional
    'brand_id' => int,           // optional
    'category_id' => int,        // optional
]

// getSearchSuggestions  —  GET /api/v1/search/suggestions  (throttle 100/min)
// Опциональный Bearer-токен (не middleware) — при наличии фильтрует по B2B/B2C магазинам.
[
    'query' => string,           // required, min:1
    'limit' => int,              // default:10, max:20
    'structured' => bool,        // optional; при true — структурированный ответ с категориями и брендами
]
```

### Индекс Meilisearch

**Имя индекса**: `products`

**Структура документа**:
```json
{
  "id": 12345,
  "store_id": 1,
  "category_id": 42,
  "brand_id": 5,
  "sku": "BORSCH-001",
  "title": "Борщ с мясом, 300 г",
  "description": "Традиционный украинский борщ...",
  "price": 220,
  "price_old": 280,
  "quantity": 50,
  "in_stock": true,
  "is_active": true,
  "rating": 4.5,
  "brand": "Babushka",
  "updated_at": 1706745600
}
```

**Настройки индекса**:
```json
{
  "searchableAttributes": ["title", "sku"],
  "filterableAttributes": ["store_id", "category_id", "brand_id", "is_active", "in_stock", "quantity", "price"],
  "sortableAttributes": ["price", "rating", "updated_at"],
  "rankingRules": [
    "words",
    "typo",
    "proximity",
    "attribute",
    "sort",
    "exactness"
  ],
  "typoTolerance": {
    "enabled": true,
    "minWordSizeForTypos": {
      "oneTypo": 4,
      "twoTypos": 8
    }
  }
}
```

### Выходные

**searchProducts** → `DefaultPaginator<StoreSearchResult>`:
```json
{
  "hasMore": true,
  "total": 5,
  "data": [
    {
      "store": { StoreResource },
      "products": [ ProductResource[] ],
      "totalInStore": 25
    }
  ]
}
```

**searchProductsInStore** → `DefaultPaginator<Product>`:
```json
{
  "hasMore": true,
  "total": 25,
  "data": [ ProductResource[] ]
}
```

**getSearchSuggestions** → `String[]`:
```json
["Борщ", "Борщ с мясом", "Борщ украинский"]
```

---

## 4. Логика

### 4.1 searchProducts (основной поиск)

**Happy path**:
1. Валидация query (min:2 символа)
2. Запрос в Meilisearch:
   ```
   filter: "is_active = true AND quantity > 0"
   limit: 1000 (для агрегации по магазинам)
   ```
3. Группировка результатов по `store_id`
4. Подсчёт `totalInStore` для каждого магазина
5. Сортировка магазинов по количеству найденных товаров (desc)
6. Пагинация по магазинам
7. Для каждого магазина: взять первые `productsPerStore` товаров
8. Загрузить полные данные Store и Product из БД (для ресурсов)
9. Сформировать ответ

**Edge cases**:
- Query < 2 символов → 422 с сообщением о минимальной длине
- Нет результатов → пустой массив `data: []`, `total: 0`
- Магазин неактивен → не включать в результаты (фильтр на уровне Store)

**Оптимизация**:
- Кеш результатов поиска: 30 секунд, ключ `search:{md5(query)}:{page}:{perPage}`
- Eager load: `products.images`, `products.activePromotions`, `store.image`

### 4.2 searchProductsInStore

**Happy path**:
1. Валидация query и storeId
2. Запрос в Meilisearch:
   ```
   filter: "store_id = {storeId} AND is_active = true AND quantity > 0"
   offset: (page - 1) * perPage
   limit: perPage
   ```
3. Загрузить полные Product из БД
4. Вернуть с пагинацией

### 4.3 getSearchSuggestions

**Happy path**:
1. Запрос в Meilisearch с `limit` и без фильтров
2. Извлечь уникальные `title` из результатов
3. Вернуть массив строк (без дубликатов)

**Альтернатива**: использовать отдельный индекс `product_titles` для подсказок (быстрее, но требует доп. синхронизации).

### 4.4 Индексация

**При создании/обновлении товара**:
```php
// В Product Observer или Event Listener
if ($product->is_active && $product->quantity > 0) {
    $searchService->indexProduct($product);
} else {
    $searchService->removeProduct($product->id);
}
```

**Массовая переиндексация** (artisan command):
```bash
php artisan search:reindex --chunk=1000
```
- Чанками по 1000 товаров
- Показывать прогресс
- Опция `--fresh` для пересоздания индекса

---

## 5. Интеграции

### Meilisearch

**Подключение**:
- Host: `MEILISEARCH_HOST` (default: `http://localhost:7700`)
- API Key: `MEILISEARCH_KEY` (master key для индексации, search key для поиска)

**Пакеты**:
```bash
composer require meilisearch/meilisearch-php
```

> **Примечание**: Laravel Scout можно использовать, но он добавляет overhead. Для данного кейса (группировка по магазинам) прямая работа с Meilisearch SDK эффективнее.

**Таймауты**:
- Connect: 2s
- Request: 5s

**Retry logic**:
- 3 попытки с exponential backoff (100ms, 200ms, 400ms)

**Fallback при недоступности**:
- Логировать ошибку
- Вернуть пустой результат с HTTP 200 (graceful degradation, не ломать UX)

### RabbitMQ (существующая интеграция)

При обработке событий `product.created`, `product.updated`, `product.deleted`:
```php
// В существующем Consumer
$searchService->handleProductEvent($event);
```

---

## 6. Нефункциональные требования

### Производительность
| Метрика | Требование |
|---------|------------|
| Латенси поиска | < 50ms (p95) |
| Индексация 1 товара | < 100ms |
| Массовая индексация | 10k товаров/мин |
| Размер индекса | до 1M документов |

### Идемпотентность
- `indexProduct()` — идемпотентен (upsert по id)
- `removeProduct()` — идемпотентен (no-op если не существует)

### Асинхронность
- Индексация при событиях — **синхронно** (Meilisearch быстрый)
- Массовая переиндексация — **через очередь** (job на каждый чанк)

### Транзакции
- НЕ требуются (Meilisearch eventually consistent)
- При сохранении Product в БД → отдельно индексация в Meilisearch

### Безопасность
- Search API Key (только чтение) для публичных запросов
- Master Key только на сервере для индексации
- Rate limiting: 100 req/min на IP для поиска

---

## 7. Структура файлов

```
app/
├── Http/
│   ├── Controllers/Api/V1/
│   │   └── SearchController.php
│   ├── Requests/Api/V1/
│   │   ├── SearchProductsRequest.php
│   │   └── SearchSuggestionsRequest.php
│   └── Resources/Api/V1/
│       └── StoreSearchResultResource.php
├── Services/
│   └── SearchService.php
├── Observers/
│   └── ProductObserver.php (обновить)
└── Console/Commands/
    └── SearchReindexCommand.php

config/
└── meilisearch.php

tests/
└── Feature/
    └── Search/
        ├── SearchProductsTest.php
        ├── SearchProductsInStoreTest.php
        └── SearchSuggestionsTest.php
```

---

## 8. API Endpoints (итого)

| Method | Endpoint | Auth | Описание |
|--------|----------|------|----------|
| GET | `/api/v1/search/products` | ❌ | Поиск с группировкой по магазинам |
| GET | `/api/v1/search/stores/{store}/products` | ❌ | Поиск в конкретном магазине |
| GET | `/api/v1/search/suggestions` | ❌ | Подсказки для автодополнения |

---

## 9. Ожидаемый результат

### Код
- [ ] `SearchService` с методами: `search()`, `searchInStore()`, `getSuggestions()`, `indexProduct()`, `removeProduct()`, `reindexAll()`
- [ ] `SearchController` с эндпоинтами согласно таблице выше
- [ ] Form Requests с валидацией
- [ ] `StoreSearchResultResource` для форматирования ответа
- [ ] `ProductObserver` обновлён для синхронизации индекса
- [ ] `SearchReindexCommand` для массовой переиндексации
- [ ] Unit/Feature тесты (покрытие > 80%)

### Конфигурация
- [ ] `config/meilisearch.php`
- [ ] `.env.example` с переменными Meilisearch

### Документация
- [ ] Обновить `api_search.md` с актуальными endpoint'ами
- [ ] README секция про Meilisearch (установка, запуск)

### Docker
- [ ] Добавить Meilisearch в `docker-compose.yml`:
```yaml
meilisearch:
  image: getmeili/meilisearch:v1.6
  ports:
    - "7700:7700"
  environment:
    - MEILI_ENV=development
    - MEILI_MASTER_KEY=${MEILISEARCH_KEY}
  volumes:
    - meilisearch_data:/meili_data
```

---

## 10. Риски и митигация

| Риск | Вероятность | Митигация |
|------|-------------|-----------|
| Meilisearch недоступен | Низкая | Graceful degradation (пустой результат), мониторинг |
| Рассинхрон индекса с БД | Средняя | Scheduled reindex (ночью), health check |
| Большой объём данных | Низкая | Шардирование по store_id (если > 1M товаров) |
| Typo tolerance слишком агрессивный | Средняя | Настроить `minWordSizeForTypos` |

---

## 11. Принятые решения

| Вопрос | Решение |
|--------|---------|
| История поиска | ❌ Не реализуем |
| Fallback на MySQL | ❌ Не нужен, пустой результат при недоступности |
| Docker | ✅ Добавить Meilisearch в docker-compose |
| Поля поиска | `title` + `sku` (без description) |
