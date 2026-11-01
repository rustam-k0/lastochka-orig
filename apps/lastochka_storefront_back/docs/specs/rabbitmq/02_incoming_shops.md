# RabbitMQ: Входящие — Магазины (shops)

**Приоритет:** Критический
**Зависимости:** `01_setup.md`
**Блокирует:** Все остальные входящие сущности

---

## 1. Цель

Реализовать приём и обработку событий магазинов из внешних систем.

---

## 2. События

| Routing Key | Действие |
|-------------|----------|
| `shop.created` | Создать магазин |
| `shop.updated` | Обновить магазин |
| `shop.deleted` | Soft delete магазина |

---

## 3. Маппинг полей

```
Event                    → Model (Store)
─────────────────────────────────────────
id                       → external_id (в metadata)
shop_code                → code
source                   → source
title                    → title
active                   → is_active
latitude                 → latitude
longitude                → longitude
slot_time                → slot_duration_minutes
open_24_7                → is_24_7
schedule                 → StoreSchedule[] (отдельная таблица)
created_at               → created_at
updated_at               → updated_at
deleted_at               → deleted_at (soft delete)
```

---

## 4. Входящий payload

```json
{
  "event_id": "uuid",
  "event_type": "created",
  "event_timestamp": "2024-01-15T10:30:00Z",
  "id": "ext_shop_1",
  "shop_code": "MSK_001",
  "source": "bagisto",
  "title": "Магазин на Ленина",
  "active": true,
  "latitude": 55.7558,
  "longitude": 37.6173,
  "slot_time": 120,
  "open_24_7": false,
  "schedule": {
    "monday": {"start": "09:00", "end": "21:00"},
    "tuesday": {"start": "09:00", "end": "21:00"},
    "wednesday": {"start": "09:00", "end": "21:00"},
    "thursday": {"start": "09:00", "end": "21:00"},
    "friday": {"start": "09:00", "end": "21:00"},
    "saturday": {"start": "10:00", "end": "20:00"},
    "sunday": null
  },
  "created_at": "2024-01-15T10:30:00Z",
  "updated_at": "2024-01-15T10:30:00Z"
}
```

---

## 5. Реализация

### 5.1 Миграция

Добавить поле `source` в таблицу `stores` (если нет):

```php
Schema::table('stores', function (Blueprint $table) {
    $table->string('source')->nullable()->after('code');
    $table->index(['code', 'source']);
});
```

### 5.2 Конфигурация entity

Добавить в `config/rabbitmq-events.php` → `entities`:

```php
'shops' => [
    'enabled' => false, // Только consume, не publish
    'model' => \App\Models\Store::class,
    'transformer' => \App\RabbitMQ\Transformers\StoreEventTransformer::class,
    'relations' => ['schedules'],
    'routing_key_patterns' => [
        'shop.created',
        'shop.updated',
        'shop.deleted',
    ],
],
```

### 5.3 Transformer

```php
<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Store;
use App\Models\StoreSchedule;
use Illuminate\Database\Eloquent\Model;

final class StoreEventTransformer extends BaseEventTransformer
{
    private const DAY_MAP = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    public function toEvent(Model $store, ?Model $shop = null, ?string $eventId = null): array
    {
        // Не используется — только consume
        return [];
    }

    public function fromEvent(array $data): array
    {
        return [
            'code' => $data['shop_code'],
            'source' => $data['source'],
            'title' => $data['title'] ?? null,
            'is_active' => $data['active'] ?? true,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'slot_duration_minutes' => $data['slot_time'] ?? null,
            'is_24_7' => $data['open_24_7'] ?? false,
        ];
    }

    public function validate(array $data): bool
    {
        return isset($data['event_id'], $data['id'], $data['shop_code'], $data['source']);
    }

    /**
     * После создания магазина — синхронизировать расписание.
     */
    public function afterCreate(Model $store, array $eventData): void
    {
        $this->syncSchedule($store, $eventData['schedule'] ?? []);
    }

    /**
     * После обновления магазина — синхронизировать расписание.
     */
    public function afterUpdate(Model $store, array $eventData): void
    {
        $this->syncSchedule($store, $eventData['schedule'] ?? []);
    }

    /**
     * Синхронизация расписания работы.
     */
    private function syncSchedule(Store $store, array $schedule): void
    {
        if (empty($schedule)) {
            return;
        }

        // Удаляем старое расписание
        $store->schedules()->delete();

        // Создаём новое
        foreach ($schedule as $day => $hours) {
            $dayNumber = self::DAY_MAP[$day] ?? null;
            if (!$dayNumber) {
                continue;
            }

            if ($hours === null) {
                // Выходной
                StoreSchedule::create([
                    'store_id' => $store->id,
                    'day_of_week' => $dayNumber,
                    'type_id' => 1, // выходной
                    'opening_time' => null,
                    'closing_time' => null,
                ]);
            } else {
                StoreSchedule::create([
                    'store_id' => $store->id,
                    'day_of_week' => $dayNumber,
                    'type_id' => 2, // по расписанию
                    'opening_time' => $hours['start'] ?? null,
                    'closing_time' => $hours['end'] ?? null,
                ]);
            }
        }
    }

    /**
     * Поиск существующего магазина по code.
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        return Store::where('code', $eventData['shop_code'])->first();
    }
}
```

---

## 6. Тесты

### 6.1 Тест создания магазина

```php
public function test_shop_created_event_creates_store(): void
{
    $eventData = [
        'event_id' => Str::uuid()->toString(),
        'event_type' => 'created',
        'event_timestamp' => now()->toIso8601String(),
        'id' => 'ext_shop_1',
        'shop_code' => 'MSK_001',
        'source' => 'bagisto',
        'title' => 'Тестовый магазин',
        'active' => true,
        'latitude' => 55.7558,
        'longitude' => 37.6173,
        'schedule' => [
            'monday' => ['start' => '09:00', 'end' => '21:00'],
            'sunday' => null,
        ],
    ];

    $consumer = new EventConsumer('shops', config('rabbitmq-events.entities.shops'));
    $result = $consumer->processEvent($eventData);

    $this->assertEquals('ack', $result);
    $this->assertDatabaseHas('stores', [
        'code' => 'MSK_001',
        'title' => 'Тестовый магазин',
    ]);
    $this->assertDatabaseHas('store_schedules', [
        'day_of_week' => 1,
        'opening_time' => '09:00:00',
    ]);
}
```

### 6.2 Тест идемпотентности

```php
public function test_duplicate_event_is_skipped(): void
{
    $eventId = Str::uuid()->toString();
    $eventData = [
        'event_id' => $eventId,
        'event_type' => 'created',
        'event_timestamp' => now()->toIso8601String(),
        'id' => 'ext_shop_1',
        'shop_code' => 'MSK_001',
        'source' => 'bagisto',
        'title' => 'Магазин',
    ];

    $consumer = new EventConsumer('shops', config('rabbitmq-events.entities.shops'));

    // Первый раз
    $consumer->processEvent($eventData);

    // Второй раз с тем же event_id
    $result = $consumer->processEvent($eventData);

    $this->assertEquals('ack', $result);
    $this->assertDatabaseCount('stores', 1);
}
```

---

## 7. Ожидаемый результат

### Файлы
- [ ] `app/RabbitMQ/Transformers/StoreEventTransformer.php`
- [ ] Миграция `add_source_to_stores_table` (если нужна)
- [ ] Обновлён `config/rabbitmq-events.php`

### Тесты
- [ ] `StoreEventHandlerTest::test_shop_created_event_creates_store`
- [ ] `StoreEventHandlerTest::test_shop_updated_event_updates_store`
- [ ] `StoreEventHandlerTest::test_shop_deleted_event_soft_deletes_store`
- [ ] `StoreEventHandlerTest::test_schedule_is_synced`
- [ ] `StoreEventHandlerTest::test_duplicate_event_is_skipped`
- [ ] `StoreEventHandlerTest::test_existing_store_is_linked`

### Команды
```bash
# Запустить consumer
php artisan rabbitmq:consume shops

# Тесты
php artisan test --filter=StoreEventHandler
```

---

## 8. Критерии готовности

- [ ] Consumer обрабатывает `shop.created`, `shop.updated`, `shop.deleted`
- [ ] Расписание синхронизируется в `store_schedules`
- [ ] Идемпотентность работает
- [ ] Существующие магазины связываются по `code`
- [ ] Тесты проходят
