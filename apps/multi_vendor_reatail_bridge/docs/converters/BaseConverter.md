# BaseConverter

**Файл:** `app/Services/Converters/BaseConverter.php`

Абстрактный базовый класс для всех конвертеров данных.

## Трейты

- `ResolvesShopCode` — методы для извлечения и резолва shop_code

## Свойства

| Свойство | Тип | Описание |
|---|---|---|
| `$retailcrmSource` | `string` | Идентификатор источника RetailCRM (из конфига) |

## Абстрактные методы

```php
// Система → RetailCRM
abstract public function toRetailCRM(array $data): array;

// RetailCRM → Система
abstract public function fromRetailCRM(array $data, $metadata = null): array;
```

Параметр `$metadata` (`EventSyncMetadata|null`) — используется для восстановления оригинального `source` и `external_id` при получении данных из CRM.

## Метод `findMetadataByEntityId`

```php
protected function findMetadataByEntityId(
    string $entityType,
    string $entityId,
    ?string $shopCode = null
): ?EventSyncMetadata
```

Поиск метаданных по ID сущности в RetailCRM (`entity_id`). Опционально фильтрует по `shop_code`.

## Метод `resolveSourceAndExternalId`

```php
protected function resolveSourceAndExternalId(
    string $entityType,
    string $entityId,
    ?string $shopCode = null
): array  // ['source' => string, 'external_id' => string]
```

Определяет оригинальный `source` и `external_id` сущности по её ID в RetailCRM:
1. Ищет в `EventSyncMetadata` по `entity_id`
2. Если найдено → возвращает `source` и `external_id` из metadata
3. Fallback → `{source: retailcrmSource, external_id: entityId}`

Используется в `fromRetailCRM()` для восстановления оригинальных идентификаторов при обратной конвертации.

## Трейт ResolvesShopCode

### extractShopCode(array $data): ?string
Извлекает `shop_code` из данных: `$data['site'] ?? $data['shop_code'] ?? null`

### resolveShopCode(?string $shopCode): string
Fallback-цепочка:
1. `$shopCode` (если передан)
2. `config('retailcrm.shop_code')`
3. `'default'`

## Связка с event_sync_metadata

BaseConverter определяет **базовые методы** для работы с `event_sync_metadata`, которые используются всеми конвертерами в методе `fromRetailCRM()` (обратная конвертация: RetailCRM → Система).

### Роль metadata в конвертерах

Конвертеры используют `event_sync_metadata` **только при обратной конвертации** (`fromRetailCRM`). Цель — восстановить оригинальные `source` и `external_id` сущности, т.к. RetailCRM хранит данные под своими внутренними ID.

```
Прямая конвертация (toRetailCRM):
  Система → RetailCRM
  metadata НЕ используется (данные берутся из события напрямую)

Обратная конвертация (fromRetailCRM):
  RetailCRM → Система
  metadata ИСПОЛЬЗУЕТСЯ для восстановления source/external_id
```

### findMetadataByEntityId — поиск по ID в RetailCRM

```
findMetadataByEntityId(entityType, entityId, shopCode?):
  SELECT event_sync_metadata
  WHERE entity_type = entityType
    AND entity_id = entityId
    [AND shop_code = shopCode]  ← опционально
  LIMIT 1
```

Используется для:
- Восстановления source/external_id основной сущности
- Резолва связанных сущностей (parent category, customer, product)

### resolveSourceAndExternalId — определение оригинального источника

```
resolveSourceAndExternalId(entityType, entityId, shopCode?):
  1. findMetadataByEntityId(entityType, entityId, shopCode)
  2. Если найдено → {source: metadata.source, external_id: metadata.external_id}
  3. Fallback    → {source: retailcrmSource, external_id: entityId}
```

Паттерн «найти или fallback» гарантирует, что конвертер всегда возвращает валидные `source` и `id`, даже если metadata не найдена.

### Где используются эти методы

| Конвертер | Метод | Для чего |
|---|---|---|
| `CategoryConverter` | прямой запрос к `EventSyncMetadata` | Резолв parent category |
| `CustomerConverter` | параметр `$metadata` | Восстановление source/id клиента |
| `OrderConverter` | `resolveSourceAndExternalId` | Резолв customer, order_item, product |
| `OrderConverter` | `findMetadataByExternalId` (собственный) | Поиск product source по external_id |
| `ProductConverter` | прямой запрос к `EventSyncMetadata` | Резолв category |
| `ShopConverter` | параметр `$metadata` | Восстановление source/id магазина |
| `UserConverter` | параметр `$metadata` | Восстановление source/id пользователя |
