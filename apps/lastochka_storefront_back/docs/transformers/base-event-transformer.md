# BaseEventTransformer

**Файл:** `app/RabbitMQ/Transformers/BaseEventTransformer.php`
**Тип:** Абстрактный базовый класс
**Namespace:** `App\RabbitMQ\Transformers`

## Описание

Абстрактный базовый класс для всех трансформеров событий RabbitMQ. Реализует интерфейс `EventTransformer` из пакета `comrades/laravel-rabbitmq-events` и предоставляет общие утилиты для всех дочерних трансформеров.

## Используемые трейты

| Трейт | Назначение |
|---|---|
| `HasCustomEventHandlers` | Обработка кастомных типов событий |
| `HasEventHandlerHooks` | Хуки жизненного цикла (before/after create/update/delete) |
| `ResolvesExternalReferences` | Разрешение внешних ссылок и pending references |
| `ValidatesEventData` | Валидация входящих данных событий |

## Методы

### `resolveEntityId(string $entityType, ?string $externalId, ?string $source, ?string $shopCode = null): ?int`

Поиск локального ID сущности по external_id и source через таблицу `EventSyncMetadata`.

**Особенности:**
- Для сущностей с `global_scope` (например, категории) поиск выполняется **без фильтра по `shop_code`**, что позволяет находить справочники независимо от магазина
- Возвращает `null` если `externalId` или `source` пустые

**Пример:**
```php
$categoryId = $this->resolveEntityId('categories', 'ext-123', 'crm', 'SHOP1');
// Для categories (global_scope) — ищет без фильтра по shop_code
// Для products — ищет с фильтром по shop_code
```

### `requiredFields(): array`

Возвращает список обязательных полей для валидации. Базовая реализация:
```php
return ['event_id', 'id', 'source'];
```
Дочерние классы переопределяют этот метод для добавления специфичных полей.

### `validate(array $data): bool`

Базовая валидация события — проверяет наличие всех обязательных полей из `requiredFields()`.

### `downloadImage(string $imageUrl, string $subFolder = 'downloads'): string`

Скачивание изображения по URL и сохранение в `storage/app/public/{subFolder}/`.

**Параметры:**
- `$imageUrl` — URL изображения для скачивания
- `$subFolder` — подпапка в storage (`categories`, `products`, `banners`, и т.д.)

**Возвращает:** относительный путь к сохранённому файлу (например `products/aBcDeFgHiJ...xyz.jpg`)

**Механика:**
1. HTTP GET с таймаутом 30 секунд
2. Определение расширения файла (из URL или Content-Type)
3. **Оптимизация в WebP** (`ImageOptimizer`, см. ниже)
4. Генерация уникального имени (`Str::random(40)`)
5. Сохранение на диск `public`

**Поддерживаемые форматы:** jpg, jpeg, png, gif, webp, svg, bmp

**Оптимизация (с 10.09.2026):** растровые картинки (jpg/jpeg/png/bmp) перекодируются
в WebP с ограничением ширины, поэтому путь на выходе оканчивается на `.webp`,
даже если источник был `.png`. Настройки — `config/images.php`.

Витрина хранит **производную** копию: оригинал остаётся в admin_back и доступен
по `source_url`. Поэтому перекодирование не теряет данные.

Оптимизация не применяется и файл сохраняется как есть, если:
- выключен флаг `IMAGE_OPTIMIZE_ENABLED`;
- расширение не растровое (`svg`, `gif`) или уже `webp`;
- файл не является картинкой (`pdf`, `mp4` и прочие вложения);
- перекодирование не удалось или не уменьшило файл.

Картинки уже, чем `max_width`, **не растягиваются** — апскейл увеличивает файл
и ухудшает картинку. Ограничение работает только на уменьшение.

### `determineImageExtension(string $url, ?string $contentType): string`

Определение расширения изображения. Приоритет:
1. Расширение из URL (если допустимое)
2. Маппинг Content-Type → расширение
3. Fallback: `jpg`

### `resolveStoreIdByCode(string $shopCode): ?int`

Поиск магазина по полю `code` в таблице `stores`.

### `resolveOrCreateStoreByCode(string $shopCode, string $source): int`

Поиск или создание магазина по `code`. Если магазин не существует — создаёт минимальный stub:
```php
['code' => $shopCode, 'source' => $source, 'name' => $shopCode, 'is_active' => true]
```

### `resolveStoreIdByExternalId(string $shopId, string $shopSource): ?int`

Поиск магазина с двухступенчатым fallback:
1. Через `EventSyncMetadata` по external_id и source
2. Через поле `code` в таблице `stores`

## Связь с EventSyncMetadata

`BaseEventTransformer` — центральная точка взаимодействия с таблицей `event_sync_metadata`. Все дочерние трансформеры используют методы этого класса для маппинга между локальными ID и external_id внешних систем.

### Таблица `event_sync_metadata`

Управляется пакетом `comrades/laravel-rabbitmq-events`. Содержит записи:

| Столбец | Описание |
|---|---|
| `entity_type` | Тип сущности (`shops`, `products`, `categories`, `orders`, ...) |
| `entity_id` | Локальный ID в БД storefront_back |
| `external_id` | ID сущности во внешней системе (Bagisto, CRM, 1C) |
| `source` | Источник данных (`bagisto`, `1c`, `crm`, `storefront`) |
| `shop_code` | Код магазина (NULL для global_scope сущностей) |
| `last_event_id` | UUID последнего обработанного события (дедупликация) |
| `last_event_timestamp` | Timestamp последнего события (защита от устаревших) |
| `metadata` | Дополнительные метаданные (JSON, nullable) |

### Как трансформеры используют metadata

**Inbound (consume):**
1. `resolveEntityId()` — поиск локального ID по external_id, source и (опционально) shop_code
2. После создания/обновления сущности пакет **автоматически** создаёт/обновляет запись в `event_sync_metadata`
3. Пакет проверяет `last_event_id` для дедупликации и `last_event_timestamp` для игнорирования устаревших событий

**Outbound (publish):**
1. Трансформеры читают metadata для преобразования локальных ID в external_id при формировании payload события
2. Это позволяет внешним системам идентифицировать сущности по своим ID

### global_scope

Сущности с `global_scope: true` в конфиге (например, категории) ищутся **без фильтра по `shop_code`**. Это означает, что категория, созданная для одного магазина, будет найдена при запросе от любого магазина — они являются справочниками.

## Наследники

| Трансформер | Модель |
|---|---|
| BannerEventTransformer | Banner |
| CategoryEventTransformer | Category |
| CustomerEventTransformer | User |
| OrderEventTransformer | Order |
| ProductEventTransformer | Product |
| ProductGroupEventTransformer | ProductGroup |
| ProductReviewEventTransformer | ProductReview |
| PromocodeEventTransformer | Promocode |
| PromotionEventTransformer | Promotion |
| StoreEventTransformer | Store |
| StoreReviewEventTransformer | StoreReview |
| StoryEventTransformer | Story |
