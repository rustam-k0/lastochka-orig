# Верификация документации RabbitMQ-пакета

**Приоритет:** Средний
**Зависимости:** Нет
**Цель:** Привести документацию в соответствие с реальным кодом `multi_vendor_delivery_rabbitmq_package`
**Охват:** Все проекты — storefront_back, docker (admin/delivery/purchase), 1c_parser, retail_bridge, пакет

---

## Часть 1. Расхождения в самом пакете (laravel-rabbitmq-events)

### P1. ARCHITECTURE.md и README.md — INSERT-first vs SELECT-first

**Файлы:**
- `laravel-rabbitmq-events/ARCHITECTURE.md` (строки 9, 125, 143, 256)
- `laravel-rabbitmq-events/README.md` (строка 9)

**Что написано:**
> INSERT-first паттерн: Вставляем metadata ПЕРВЫМ, используем unique constraint как распределённую блокировку.

**Как на самом деле** (из `multi_vendor_delivery_rabbitmq_package/src/Services/EventConsumer.php`, строка 184):
```php
// ✅ SELECT-first подход: сначала ищем, потом создаем если не нашли
```

Реальная логика:
1. `SELECT` — ищем metadata по `(entity_type, source, external_id)`
2. Если найдена — используем (isNew=false)
3. Если НЕ найдена — `INSERT` с `entity_id = null`
4. При race condition (Duplicate Entry) — повторный `SELECT`

**Важно:** Документация ARCHITECTURE.md описывает INSERT-first как **целевой** паттерн, но реальная имплементация использует SELECT-first. Диаграмма на строке 71 (`INSERT metadata ПЕРВЫМ`) тоже не соответствует коду.

**Что нужно исправить:**
- Переименовать секцию в "SELECT-first с INSERT fallback"
- Обновить диаграммы потоков
- Обновить README.md строка 9

---

## Часть 2. Расхождения в electrohub_storefront_back

### S1. EventPendingReference — несуществующие поля в примере

**Файл:** `docs/specs/18_rabbitmq_integration.md`, секция 5.3 (строки ~485-494)

**Что написано (НЕВЕРНО):**
```php
EventPendingReference::create([
    ...
    'description' => 'Product waiting for category',       // НЕ СУЩЕСТВУЕТ
    'callback_method' => 'linkCategory',                   // НЕ СУЩЕСТВУЕТ
]);
```

**Реальные fillable поля модели:**
```php
'entity_type', 'entity_id', 'field_name', 'reference_type',
'reference_source', 'reference_external_id', 'shop_code',
'resolved_at', 'attempts', 'last_attempt_at', 'metadata'
```

**Правильный пример:**
```php
EventPendingReference::create([
    'entity_type' => 'products',
    'entity_id' => $product->id,
    'field_name' => 'category_id',
    'reference_type' => 'categories',
    'reference_source' => $eventData['category_source'],
    'reference_external_id' => $eventData['category_id'],
    'shop_code' => $eventData['shop_code'],
    'metadata' => ['event_id' => $eventData['event_id']],
]);
```

**Критичность:** Высокая — код по этому примеру не заработает

---

### S2. DownloadsExternalImages — смешение пакетного трейта и app-метода

**Файл 1:** `docs/specs/18_rabbitmq_integration.md`, секция 5.4 (строки ~498-517)
**Файл 2:** `docs/specs/rabbitmq/01_setup.md`, секция 2.5 (строки ~130-201)

**Проблема:** Оба документа показывают `use DownloadsExternalImages` из пакета, но вызывают `$this->downloadImage()` — метод, которого в пакетном трейте **нет**.

| Аспект | Пакетный `downloadExternalImage()` | Storefront `downloadImage()` |
|--------|-------------------------------------|------------------------------|
| Сигнатура | `(Model, array, imageUrlField, relationMethod, headCode, subFolder, altText)` | `(string $imageUrl, string $subFolder)` |
| Storage | `Storage::put()` (default disk) | `Storage::disk('public')->put()` |
| Привязка к модели | Вызывает `$model->saveImg()` — требует HasImage trait | Возвращает путь — привязка вручную |
| Localhost замена | Через `replaceLocalhostUrl()` | Нет |

**Что нужно исправить:**
- В 01_setup.md: убрать `use DownloadsExternalImages` из шаблона BaseEventTransformer
- В 18_rabbitmq_integration.md: убрать `use DownloadsExternalImages`, показать наследование от BaseEventTransformer

**Критичность:** Высокая — код по этому примеру не заработает

---

### S3. Отсутствие колонки `metadata` в документации

**Файл 1:** `docs/transformers/base-event-transformer.md`, таблица event_sync_metadata (строки 96-107) — нет колонки `metadata`
**Файл 2:** Через ссылку в `rabbitmq-technical.md` § 4.3 — нет колонки `metadata` в SQL-схеме event_pending_references

**Критичность:** Низкая

---

## Часть 3. Расхождения в electrohub_docker

### D1. INSERT-first терминология в rabbitmq-technical.md

**Файл:** `docs/rabbitmq-technical.md`, секция 4.2 (строка ~379)

**Что написано:**
> INSERT-first pattern: сначала создаём metadata, потом сущность.

**Реальный код:** SELECT-first (см. P1)

**Критичность:** Средняя

---

### D2. INSERT-first терминология в rabbitmq-guide.md

**Файл:** `docs/rabbitmq-guide.md`, секция 4.2 (строки 184-208)

**Что написано:** Подробное описание INSERT-first pattern с диаграммой и аналогией про курьеров.

**Реальный код:** SELECT-first. Диаграмма на строках 194-206 показывает:
```
Процесс A: INSERT metadata → ...
Процесс B: INSERT metadata → (Duplicate Entry!)
```
Но в реальном коде:
```
Процесс A: SELECT metadata → не найдено → INSERT metadata
Процесс B: SELECT metadata → не найдено → INSERT metadata → Duplicate Entry → SELECT
```

**Критичность:** Средняя — функционально эквивалентно (оба защищают от race condition), но терминология вводит в заблуждение

---

### D3. Отсутствие колонки `metadata` в event_pending_references

**Файл:** `docs/rabbitmq-technical.md`, секция 4.3 SQL-схема

SQL-описание не содержит `metadata JSON NULL`, но реальная модель имеет это поле.

**Критичность:** Низкая

---

### D4. Не документирован массив $externalReferences

В трансформерах admin_back активно используется конструкция:
```php
protected array $externalReferences = [
    'category_id' => [
        'type' => Category::class,
        'source_field' => 'category_source',
        'id_field' => 'category_id',
        'nullable' => true,
        'shop_code_scoped' => false,
    ],
];
```
Но в rabbitmq-technical.md и rabbitmq-guide.md этот механизм не описан.

**Критичность:** Средняя — пробел в документации, важен при добавлении новых сущностей

---

## Часть 4. Расхождения в electrohub_1c_parser

### C1. ARCHITECTURE.md пакета (через симлинк laravel-rabbitmq-events) — те же проблемы

Проект 1c_parser ссылается на `laravel-rabbitmq-events/ARCHITECTURE.md`, где описаны `description` и `callback_method` для EventPendingReference. См. P1.

**Критичность:** Высокая (если 1c_parser будет использовать pending references по этим примерам)

---

### C2. OrderEventDTO — недокументированный маппинг product_id → externalId

**Файл:** `app/RabbitMQ/DTO/OrderItemDTO.php`

```php
externalId: (string) $data['product_id'],  // product_id трактуется как external_id
```

А в `OrderImportService` товар ищется:
```php
$product = Product::where('external_id', $itemDto->externalId)->first();
```

**Проблема:** В ТЗ (`docs/TZ-orders-rabbitmq-to-1c.md`) не документировано, что `product_id` из события — это external_id (GUID из 1С), а не внутренний ID.

**Критичность:** Высокая — может привести к ошибкам при интеграции

---

### C3. OrderEventDTO — поле number берётся из externalId

```php
number: (string) ($message['externalId'] ?? $message['id']),
```

ТЗ (строка 59) описывает формат события с полем `number`, но DTO берёт `externalId` с fallback на `id`.

**Критичность:** Средняя

---

## Часть 5. Расхождения в multi_vendor_reatail_bridge

### R1. INSERT-first терминология в документации

**Файлы:**
- `CLAUDE.md` (строка 188)
- `docs/consumers/BaseConsumerHandler.md` (строки 5, 20, 37)

**Что написано:** "INSERT-first паттерн"

**Реальный код** (`BaseConsumerHandler.php`, строки 213-330): SELECT-first с обработкой race condition через try-catch Duplicate Entry.

**Критичность:** Средняя

---

## Сводная таблица по всем проектам

| # | Проект | Файл | Проблема | Критичность |
|---|--------|------|----------|-------------|
| P1 | пакет | `ARCHITECTURE.md`, `README.md` | INSERT-first → SELECT-first | Средняя |
| S1 | storefront | `18_rabbitmq_integration.md` § 5.3 | Несуществующие поля `description`, `callback_method` | **Высокая** |
| S2 | storefront | `18_rabbitmq_integration.md` § 5.4, `01_setup.md` § 2.5 | Смешение пакетного трейта и app-метода | **Высокая** |
| S3 | storefront | `base-event-transformer.md` | Нет колонки `metadata` в таблице | Низкая |
| D1 | docker | `rabbitmq-technical.md` § 4.2 | INSERT-first → SELECT-first | Средняя |
| D2 | docker | `rabbitmq-guide.md` § 4.2 | INSERT-first с неверной диаграммой | Средняя |
| D3 | docker | `rabbitmq-technical.md` § 4.3 | Нет колонки `metadata` в SQL | Низкая |
| D4 | docker | rabbitmq-technical/guide | Не документирован `$externalReferences` | Средняя |
| C1 | 1c_parser | через `ARCHITECTURE.md` пакета | Те же несуществующие поля | **Высокая** |
| C2 | 1c_parser | `OrderItemDTO.php` + ТЗ | `product_id` → `externalId` не документирован | **Высокая** |
| C3 | 1c_parser | `OrderEventDTO.php` + ТЗ | `number` берётся из `externalId` | Средняя |
| R1 | retail_bridge | `CLAUDE.md`, `BaseConsumerHandler.md` | INSERT-first → SELECT-first | Средняя |

---

## Корректная информация (проверено, ОК)

### storefront_back
- `rabbitmq-technical.md` § 1 — структура пакета, список файлов
- `rabbitmq-technical.md` § 2 — формат конфигурации, параметры `entities`
- `rabbitmq-technical.md` § 4.1 — схема `event_outbox`
- `rabbitmq-technical.md` § 4.2 — схема `event_sync_metadata` (кроме описания паттерна)
- `base-event-transformer.md` — методы `resolveEntityId()`, `requiredFields()`, `validate()`, `downloadImage()`, `resolveStoreIdByCode()`, `resolveOrCreateStoreByCode()`, `resolveStoreIdByExternalId()`, секция global_scope
- `00_index.md` — граф зависимостей, файлы, порядок выполнения
- `01_setup.md` — конфигурация, env-переменные, директории

### docker (admin_back/delivery_back/purchase_back)
- config/rabbitmq-events.php — конфигурация всех entities корректна
- Трейты в трансформерах — используются правильно
- `downloadExternalImage()` — вызывается с правильной 7-параметровой сигнатурой
- delivery_back и purchase_back — корректно настроены как только publishers
- Supervisor-матрица — соответствует реальности

### retail_bridge
- EventSyncMetadata поля — полное соответствие
- EventOutbox поля — полное соответствие
- config/rabbitmq-events.php — корректна
- Consumer логика (loopback, idempotency) — работает правильно
