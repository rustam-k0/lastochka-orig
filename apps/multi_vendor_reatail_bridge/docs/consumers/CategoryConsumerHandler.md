# CategoryConsumerHandler

**Файл:** `app/Services/Consumers/CategoryConsumerHandler.php`
**Наследует:** `BaseConsumerHandler`
**Entity type:** `category`

Обработчик событий категорий (`category.*`). Категория создается только для указанного shop_code. Дублирование на другие sites происходит "лениво" — при обработке товара через `ProductConsumerHandler.duplicateCategoryForSite()`.

## Зависимости

| Сервис | Назначение |
|---|---|
| `CategoryConverter` | Конвертация данных |
| `RetailCRMService` | API вызовы |
| `EntityHashService` | Change detection |

## Валидация

Обязательные поля (помимо базовых):
- `title`

## Алгоритм syncToRetailCRM

```
1. Hash-based deduplication
   └── Если metadata.entity_id есть → конвертируем → hasChanged()

2. ensureSiteExists(siteCode)
   └── GET sites → если нет → editSite() (создание)

3. Конвертация данных
   ├── converter.toRetailCRM()
   └── Добавляем site = siteCode

4. Резолв родительской категории
   └── findRetailCrmId('category', parent_source, parent_id, siteCode)

5. ОБНОВЛЕНИЕ (если metadata.entity_id есть)
   ├── updateProductGroup(entity_id, data)
   ├── При 404 → needsFallbackCreate = true
   ├── При другой ошибке + есть parentId → retry без parentId
   │   └── При 404 на retry → needsFallbackCreate = true
   └── fallbackCreate → очистка entity_id → переход к созданию

6. СОЗДАНИЕ
   ├── createProductGroup(data)
   ├── Если ошибка с parentId → retry без parentId
   └── saveHashAfterSync()
```

## Метод `ensureSiteExists`

Публичный метод — используется также из `ProductConsumerHandler.duplicateCategoryForSite()`.

1. `getSites()` — получает все sites
2. Перебирает, ищет совпадение по `code`
3. Если не найден → `editSite({code, name: ucfirst(code), active: true})`

## Обработка ошибок

### 404 при UPDATE
Если категория не найдена в RetailCRM (была удалена или entity_id невалидный):
- Логирует warning
- Устанавливает `metadata.entity_id = null`
- Переходит к CREATE (fallback)

### Ошибка с parentId
Если создание/обновление не удалось и есть `parentId`:
- Повторяет запрос без `parentId`
- Категория создается без родителя (лучше чем не создать вообще)

## Связка с event_sync_metadata

**Entity type:** `category`

### Как используется metadata в syncToRetailCRM

```
syncToRetailCRM(data, metadata, message):

1. Hash-проверка (ЧТЕНИЕ metadata.entity_id):
   └── Если metadata.entity_id есть → конвертируем → hasChanged()
       └── Данные не изменились → return metadata.entity_id (пропуск)

2. Резолв родительской категории (ЧТЕНИЕ через findRetailCrmId):
   └── findRetailCrmId('category', parent_source, parent_id, siteCode)
       └── SELECT event_sync_metadata WHERE entity_type='category'
           AND source=parent_source AND external_id=parent_id AND shop_code=siteCode
       └── Возвращает entity_id родителя → categoryData['parentId']

3. CREATE vs UPDATE (ЧТЕНИЕ metadata.entity_id):
   ├── metadata.entity_id есть → UPDATE (updateProductGroup)
   │   └── При 404 → metadata.entity_id = null → fallback CREATE
   └── metadata.entity_id нет → CREATE (createProductGroup)

4. Возвращает RetailCRM ID → BaseConsumerHandler записывает в metadata.entity_id
```

### Какие поля metadata читаются

| Поле | Где читается | Зачем |
|---|---|---|
| `entity_id` | hash-проверка, CREATE vs UPDATE | Определяет, есть ли сущность в RetailCRM |
| `entity_id` (родителя) | `findRetailCrmId` | Получить RetailCRM ID родительской категории |

### Какие поля metadata записываются

| Поле | Где записывается | Значение |
|---|---|---|
| `entity_id` | BaseConsumerHandler (шаг 6) | ID категории в RetailCRM (числовой) |
| `entity_id` | fallback при 404 | `null` (сброс перед повторным CREATE) |
| `last_event_id` | BaseConsumerHandler (шаг 7) | UUID обработанного события |
| `last_event_timestamp` | BaseConsumerHandler (шаг 7) | Время события |

### Пример записи в event_sync_metadata

```
entity_type: category
source: bagisto
external_id: 550e8400-e29b-41d4-a716-446655440000  (UUID из Bagisto)
shop_code: shop-almaty
entity_id: 42                                        (ID productGroup в RetailCRM)
last_event_id: a1b2c3d4-...
last_event_timestamp: 2024-06-15 12:00:00
```
