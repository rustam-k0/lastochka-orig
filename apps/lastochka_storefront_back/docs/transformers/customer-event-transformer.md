# CustomerEventTransformer

**Файл:** `app/RabbitMQ/Transformers/CustomerEventTransformer.php`
**Модель:** `App\Models\User`
**Routing key (outbound):** `customers`
**Routing key (inbound):** `customers`
**Направление:** Двусторонний (publish + consume)

## Описание

Двусторонний трансформер для синхронизации пользователей с внешними системами (CRM). Отправляет данные пользователей при создании/обновлении и принимает обновления от CRM, при этом **не перезаписывая** локально заполненные данные.

## Обязательные поля события

```php
['event_id', 'id', 'source', 'phone']
```

## Условие публикации (outbound)

Используется `CustomerHasPhoneCondition` — событие публикуется только для пользователей с заполненным телефоном.

## Маппинг полей

### toEvent (Model → RabbitMQ)

| Поле модели | Поле события | Примечание |
|---|---|---|
| `id` | `id` | Приводится к string |
| — | `shop_code` | Из конфига `rabbitmq-events.shop_code` (default: `STOREFRONT`) |
| — | `source` | Из конфига `rabbitmq-events.source` |
| `phone` | `phone` | |
| `first_name` | `name` | |
| `last_name` | `surname` | |
| `patronymic` | `patronymic` | |
| `email` | `email` | |
| `birth_date` | `birthday` | Формат `Y-m-d` |
| `gender` | `gender` | Значение enum |
| `created_at` | `created_at` | ISO 8601 |
| `updated_at` | `updated_at` | ISO 8601 |

### fromEvent (RabbitMQ → Model)

| Поле события | Поле модели | Примечание |
|---|---|---|
| `phone` | `phone` | |
| `name` | `first_name` | |
| `surname` | `last_name` | |
| `patronymic` | `patronymic` | |
| `email` | `email` | |
| `birthday` | `birth_date` | |
| `gender` | `gender` | |

## Хуки жизненного цикла

### `beforeUpdate`

**Ключевая логика:** Защита локальных данных от перезаписи.

При обновлении от CRM **не перезаписываются** поля, которые уже заполнены локально:
- `first_name` — если уже заполнено
- `last_name` — если уже заполнено
- `email` — если уже заполнено

Если после фильтрации не осталось полей для обновления — возвращает `null` (обновление пропускается).

## Поиск существующей сущности (findExistingEntity)

Поиск по полю `phone` в таблице `users`. Включает soft-deleted записи (`withTrashed`).

> **Примечание:** Пользователи идентифицируются по номеру телефона, так как это основной идентификатор в системе OTP-авторизации.

## Связь с EventSyncMetadata

### Запись metadata для пользователя

Пакет автоматически создаёт/обновляет запись при обработке события:

| Поле | Значение |
|---|---|
| `entity_type` | `customers` |
| `entity_id` | ID созданного/обновлённого User |
| `external_id` | `eventData['id']` (или `user->id` при outbound) |
| `source` | `eventData['source']` (или из конфига при outbound) |
| `shop_code` | `eventData['shop_code']` |

### Прямое использование EventSyncMetadata

Трансформер **не вызывает** `resolveEntityId()` и не обращается к `EventSyncMetadata` напрямую — ни в `toEvent()`, ни в `fromEvent()`. Поиск существующего пользователя выполняется по `phone` (бизнес-ключ), а не через metadata.

### Роль metadata при двусторонней синхронизации

Хотя трансформер не читает metadata явно, пакет использует запись metadata для:
- **Дедупликация** — `last_event_id` предотвращает повторную обработку входящего события
- **Версионирование** — `last_event_timestamp` игнорирует устаревшие события от CRM
- **Маппинг ID** — при outbound пакет формирует поля `id` и `source` на основе метаданных, чтобы CRM могла сопоставить пользователя

## Пример исходящего события

```json
{
    "event_id": "550e8400-e29b-41d4-a716-446655440000",
    "event_timestamp": "2024-01-20T12:00:00+03:00",
    "event_source": "storefront",
    "id": "42",
    "shop_code": "STOREFRONT",
    "source": "storefront",
    "phone": "+79991234567",
    "name": "Иван",
    "surname": "Петров",
    "patronymic": "Сергеевич",
    "email": "ivan@example.com",
    "birthday": "1990-05-15",
    "gender": "male",
    "created_at": "2024-01-10T09:00:00+03:00",
    "updated_at": "2024-01-20T12:00:00+03:00"
}
```
