# ProductConsumerHandler

**Файл:** `app/Services/Consumers/ProductConsumerHandler.php`
**Наследует:** `BaseConsumerHandler`
**Entity type:** `product`

Обработчик событий товаров (`product.*`).

## Зависимости

| Сервис | Назначение |
|---|---|
| `ProductConverter` | Конвертация данных Система <-> RetailCRM |
| `RetailCRMService` | API вызовы к RetailCRM |
| `EntityHashService` | Hash-based change detection |

## Валидация

Обязательные поля (помимо базовых `id`, `source`):
- `title` или `name`
- `sku` или `article`

## Алгоритм syncToRetailCRM

```
1. Hash-based deduplication
   └── Если metadata.entity_id есть → конвертируем → hasChanged()
       └── Данные не изменились → return entity_id (пропуск)

2. Конвертация данных (converter.toRetailCRM)
   └── Добавляем site = shop_code

3. Резолв категории
   ├── findRetailCrmId('category', source, id, shopCode)
   ├── Fallback: findCategoryIgnoringShopCode() — поиск для ЛЮБОГО shop_code
   └── Если найдена → productData['groups'] = [{id: categoryRetailId}]

4. ОБНОВЛЕНИЕ (если metadata.entity_id существует)
   ├── Защита изображений: GET товар → если imageUrl уже есть → unset imageUrl
   └── updateProductInRetailCRM()

5. ПОИСК СУЩЕСТВУЮЩЕГО (если entity_id нет)
   ├── findProductInRetailCRM(externalId, site, sku)
   │   ├── searchByExternalId() — глобально, затем с фильтром site
   │   └── searchByArticle() — fuzzy по filter[name], точная проверка article
   └── Если найден → обновляем metadata → updateProductInRetailCRM()

6. СОЗДАНИЕ НОВОГО
   └── createProduct() → updatePrices → updateInventories → saveHashAfterSync
```

## Ключевые методы

### updateProductInRetailCRM
- Обновляет товар через `retailCrmService.updateProduct()`
- После успешного обновления: `updatePricesInRetailCRM()` + `updateInventoriesInRetailCRM()`
- Сохраняет хеш через `hashService.saveHashAfterSync()`

### updatePricesInRetailCRM
- Вызывает `retailCrmService.uploadPrices()`
- Предпочитает `externalId` для идентификации оффера
- Если `processedOffersCount === 0` → fallback на поиск offer ID через GET products
- Поддерживает массив `prices` или одиночное поле `price` (как `base`)

### updateInventoriesInRetailCRM
- Вызывает `retailCrmService.uploadInventories()`
- Использует `warehouse_code` из конфига
- Аналогичный fallback на offer ID при `processedOffersCount === 0`

### findProductInRetailCRM
Двухэтапный поиск для предотвращения дубликатов:

1. **По externalId:**
   - Сначала глобальный поиск (без фильтра site) — предотвращает `duplicate external IDs`
   - Затем с фильтром по site (для совместимости)

2. **По артикулу (sku):**
   - RetailCRM API `filter[name]` ищет по названию/артикулу
   - После получения — точная проверка article в товаре и офферах

### duplicateCategoryForSite
"Ленивое" дублирование категории для нового site:

1. Ищет категорию в metadata для ЛЮБОГО shop_code
2. GET данные категории из RetailCRM
3. `ensureSiteExists()` для target site
4. Проверяет, не существует ли уже в RetailCRM по externalId
5. Создает копию с правильным site и parent
6. Сохраняет metadata для новой категории
7. Обработка race condition при создании

### findCategoryIgnoringShopCode
Поиск категории в metadata без учета shop_code. Используется когда категория и товар приходят с разными shop_code.

## Связка с event_sync_metadata

**Entity type:** `product` (основной), `category` (чтение + запись при дублировании)

ProductConsumerHandler — **второй по сложности** потребитель metadata. Помимо стандартной логики, он выполняет «ленивое» дублирование категорий и прямые запросы к metadata с нестандартными условиями.

### Как используется metadata в syncToRetailCRM

```
syncToRetailCRM(data, metadata, message):

1. Hash-проверка (ЧТЕНИЕ metadata.entity_id):
   └── Если metadata.entity_id есть → конвертируем → hasChanged()
       └── Не изменилось → return metadata.entity_id (пропуск)

2. Резолв категории (ЧТЕНИЕ через findRetailCrmId + прямой SELECT):
   ├── findRetailCrmId('category', source, id, shopCode)
   │   └── SELECT event_sync_metadata WHERE entity_type='category'
   │       AND source AND external_id AND shop_code
   ├── Fallback: findCategoryIgnoringShopCode(source, id)
   │   └── SELECT event_sync_metadata WHERE entity_type='category'
   │       AND source AND external_id AND entity_id IS NOT NULL
   │       (БЕЗ фильтра по shop_code!)
   └── Если найден → productData['groups'] = [{id: categoryRetailId}]

3. CREATE vs UPDATE (ЧТЕНИЕ metadata.entity_id):
   ├── metadata.entity_id есть → UPDATE
   └── metadata.entity_id нет:
       ├── findProductInRetailCRM() → поиск по externalId/article в API
       │   └── Если найден → ЗАПИСЬ: metadata.entity_id = найденный ID
       │       + metadata.save() ← прямая ЗАПИСЬ (не через BaseConsumerHandler!)
       └── Не найден → CREATE

4. Возвращает RetailCRM ID → BaseConsumerHandler записывает в metadata.entity_id
```

### duplicateCategoryForSite — создание записей metadata

При «ленивом» дублировании категории для нового site создаются **новые записи** в `event_sync_metadata`:

```
duplicateCategoryForSite(source, categoryId, targetSiteCode):

1. ЧТЕНИЕ: SELECT event_sync_metadata WHERE entity_type='category'
   AND source AND external_id AND entity_id IS NOT NULL
   └── Ищем категорию для ЛЮБОГО shop_code

2. GET категория из RetailCRM по entity_id

3. Проверка: уже существует для target site?
   └── Если да → ЗАПИСЬ: EventSyncMetadata::updateOrCreate(
       entity_type='category', source, external_id, shop_code=targetSiteCode,
       entity_id=найденный ID)

4. CREATE категория в RetailCRM для targetSiteCode

5. ЗАПИСЬ: EventSyncMetadata::create(
   entity_type='category', source, external_id=categoryId,
   shop_code=targetSiteCode, entity_id=новый RetailCRM ID)
```

### Прямая запись в metadata (в обход BaseConsumerHandler)

В отличие от других консьюмеров, ProductConsumerHandler **напрямую** записывает в metadata:

| Место в коде | Что записывается | Зачем |
|---|---|---|
| `syncToRetailCRM` (восстановление связи) | `metadata.entity_id = existingProduct['id']` | Восстановление связи при обнаружении товара в CRM |
| `duplicateCategoryForSite` | `EventSyncMetadata::create(...)` | Новая запись для дублированной категории |
| `duplicateCategoryForSite` | `EventSyncMetadata::updateOrCreate(...)` | Привязка существующей категории к новому site |

### Какие записи metadata создаются/читаются

| entity_type | Операция | Где в коде | Назначение |
|---|---|---|---|
| `product` | READ/WRITE | BaseConsumerHandler + syncToRetailCRM | Основная запись товара |
| `category` | READ | `findRetailCrmId`, `findCategoryIgnoringShopCode` | Поиск ID категории в CRM |
| `category` | WRITE | `duplicateCategoryForSite` | Создание записи для дублированной категории |

### Пример записей в event_sync_metadata

```
── Товар ──
entity_type: product
source: 1c
external_id: GUID-from-1c-12345
shop_code: shop-almaty
entity_id: 777                    (ID товара в RetailCRM)

── Категория (оригинал) ──
entity_type: category
source: 1c
external_id: cat-guid-from-1c
shop_code: shop-almaty
entity_id: 42

── Категория (дубликат для другого site) ──
entity_type: category
source: 1c
external_id: cat-guid-from-1c    (тот же external_id!)
shop_code: shop-astana            (другой shop_code)
entity_id: 43                     (другой ID в RetailCRM)
```

## Защита изображений

При обновлении товара, если в новых данных есть `imageUrl`:
1. GET текущий товар из RetailCRM
2. Если в CRM уже есть изображение (`existingProduct.imageUrl`) → `unset($productData['imageUrl'])`
3. Предотвращает случайную перезапись изображений, добавленных вручную в CRM
