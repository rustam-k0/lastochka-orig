# RabbitMQ: Исходящие — Клиенты (customers)

**Приоритет:** Высокий
**Зависимости:** `01_setup.md`

---

## 1. Цель

Реализовать публикацию событий клиентов при регистрации и обновлении профиля.

---

## 2. События

| Routing Key | Когда публикуется |
|-------------|-------------------|
| `customer.created` | Новый пользователь зарегистрировался |
| `customer.updated` | Обновлён профиль пользователя |

---

## 3. Исходящий payload

### 3.1 customer.created / customer.updated

```json
{
  "event_id": "uuid",
  "event_type": "created",
  "event_timestamp": "2024-01-15T10:30:00Z",
  "event_source": "storefront",
  "id": "1",
  "shop_code": "STOREFRONT",
  "source": "storefront",
  "phone": "+79991234567",
  "name": "Иван",
  "surname": "Иванов",
  "patronymic": null,
  "email": "ivan@example.com",
  "birthday": "1990-01-15",
  "gender": "male",
  "created_at": "2024-01-15T10:30:00Z",
  "updated_at": "2024-01-15T10:30:00Z"
}
```

---

## 4. Реализация

### 4.1 Конфигурация entity

```php
'customers' => [
    'enabled' => true, // Публикуем
    'model' => \App\Models\User::class,
    'transformer' => \App\RabbitMQ\Transformers\CustomerEventTransformer::class,
    'events' => ['created', 'updated'],
    'routing_key_prefix' => 'customer',
    'exchange' => 'default',
    'condition' => \App\RabbitMQ\Conditions\CustomerHasPhoneCondition::class,
],
```

### 4.2 Transformer

```php
<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CustomerEventTransformer extends BaseEventTransformer
{
    /**
     * Преобразовать модель в событие для RabbitMQ.
     */
    public function toEvent(Model $user, ?Model $shop = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $user->updated_at->toIso8601String(),
            'event_source' => config('rabbitmq-events.source'),
            'id' => (string) $user->id,
            'shop_code' => config('rabbitmq-events.shop_code', 'STOREFRONT'),
            'source' => config('rabbitmq-events.source'),
            'phone' => $user->phone,
            'name' => $user->first_name,
            'surname' => $user->last_name,
            'patronymic' => $user->patronymic,
            'email' => $user->email,
            'birthday' => $user->birthday?->format('Y-m-d'),
            'gender' => $user->gender?->value,
            'created_at' => $user->created_at->toIso8601String(),
            'updated_at' => $user->updated_at->toIso8601String(),
        ];
    }

    /**
     * Преобразовать входящее событие в данные модели.
     * Используется при получении customer.* от CRM.
     */
    public function fromEvent(array $data): array
    {
        return [
            'phone' => $data['phone'],
            'first_name' => $data['name'] ?? null,
            'last_name' => $data['surname'] ?? null,
            'patronymic' => $data['patronymic'] ?? null,
            'email' => $data['email'] ?? null,
            'birthday' => $data['birthday'] ?? null,
            'gender' => $data['gender'] ?? null,
        ];
    }

    public function validate(array $data): bool
    {
        return isset($data['event_id'], $data['id'], $data['source'], $data['phone']);
    }

    /**
     * Поиск существующего пользователя по телефону.
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        return User::where('phone', $eventData['phone'])->first();
    }

    /**
     * Перед обновлением — не перезаписывать локальные данные, если они есть.
     */
    public function beforeUpdate(Model $user, array $eventData, array $transformedData): ?array
    {
        // Не перезаписываем имя/email если уже заполнены локально
        if ($user->first_name && isset($transformedData['first_name'])) {
            unset($transformedData['first_name']);
        }
        if ($user->last_name && isset($transformedData['last_name'])) {
            unset($transformedData['last_name']);
        }
        if ($user->email && isset($transformedData['email'])) {
            unset($transformedData['email']);
        }

        return empty($transformedData) ? null : $transformedData;
    }
}
```

### 4.3 Condition (опционально)

```php
<?php

declare(strict_types=1);

namespace App\RabbitMQ\Conditions;

use Comrades\RabbitMQEvents\Contracts\PublishCondition;
use Illuminate\Database\Eloquent\Model;

/**
 * Публиковать только клиентов с телефоном.
 */
final class CustomerHasPhoneCondition implements PublishCondition
{
    public function __invoke(Model $user): bool
    {
        return !empty($user->phone);
    }
}
```

### 4.4 Миграция

```php
// add_external_id_to_users_table
Schema::table('users', function (Blueprint $table) {
    $table->string('external_id')->nullable()->after('id');
    $table->string('external_source')->nullable()->after('external_id');
    $table->index(['external_id', 'external_source']);
});
```

---

## 5. Интеграция с Actions

В существующих Actions ничего менять не нужно — пакет автоматически отслеживает Eloquent события через Observer.

При необходимости отключить публикацию:

```php
// Отключить события для конкретной операции
User::withoutEvents(function () use ($userData) {
    User::create($userData);
});
```

---

## 6. Тесты

```php
public function test_customer_created_event_published(): void
{
    // Создаём пользователя
    $user = User::factory()->create([
        'phone' => '+79991234567',
        'first_name' => 'Иван',
        'last_name' => 'Иванов',
    ]);

    // Проверяем что событие в outbox
    $this->assertDatabaseHas('event_outbox', [
        'entity_type' => 'customers',
        'entity_id' => $user->id,
        'event_type' => 'created',
    ]);

    // Проверяем payload
    $outbox = \Comrades\RabbitMQEvents\Models\EventOutbox::where('entity_id', $user->id)->first();
    $payload = json_decode($outbox->payload, true);

    $this->assertEquals($user->phone, $payload['phone']);
    $this->assertEquals('Иван', $payload['name']);
    $this->assertEquals('storefront', $payload['source']);
}

public function test_customer_without_phone_not_published(): void
{
    $user = User::factory()->create(['phone' => null]);

    $this->assertDatabaseMissing('event_outbox', [
        'entity_type' => 'customers',
        'entity_id' => $user->id,
    ]);
}

public function test_customer_updated_event_published(): void
{
    $user = User::factory()->create(['phone' => '+79991234567']);

    // Очищаем outbox после created
    \Comrades\RabbitMQEvents\Models\EventOutbox::truncate();

    // Обновляем
    $user->update(['first_name' => 'Пётр']);

    $this->assertDatabaseHas('event_outbox', [
        'entity_type' => 'customers',
        'entity_id' => $user->id,
        'event_type' => 'updated',
    ]);
}
```

---

## 7. Ожидаемый результат

### Файлы
- [ ] `app/RabbitMQ/Transformers/CustomerEventTransformer.php`
- [ ] `app/RabbitMQ/Conditions/CustomerHasPhoneCondition.php`
- [ ] Миграция `add_external_id_to_users_table`
- [ ] Обновлён `config/rabbitmq-events.php`

### Тесты
- [ ] `CustomerPublisherTest::test_customer_created_event_published`
- [ ] `CustomerPublisherTest::test_customer_updated_event_published`
- [ ] `CustomerPublisherTest::test_customer_without_phone_not_published`

### Команды
```bash
# Запустить outbox publisher
php artisan rabbitmq:publish-outbox customers

# Первоначальная синхронизация существующих клиентов
php artisan rabbitmq:sync-entity customers
```

---

## 8. Критерии готовности

- [ ] При создании User → событие в `event_outbox`
- [ ] При обновлении User → событие в `event_outbox`
- [ ] Condition фильтрует пользователей без телефона
- [ ] Payload соответствует формату из aladdin_exchange.md
- [ ] Тесты проходят
