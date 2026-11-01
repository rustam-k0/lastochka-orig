# RabbitMQ: Исходящие — Отзывы (reviews)

**Приоритет:** Низкий
**Зависимости:** `01_setup.md`

---

## 1. Цель

Реализовать публикацию отзывов клиентов на мерчантов и товары для модерации во внешней админке.

---

## 2. События

| Entity | Routing Keys |
|--------|--------------|
| `store_reviews` | `merchant_review.created`, `merchant_review.updated` |
| `product_reviews` | `product_review.created`, `product_review.updated` |

---

## 3. Исходящие payload'ы

### 3.1 merchant_review.created

```json
{
  "event_id": "uuid",
  "event_type": "created",
  "event_timestamp": "2024-01-15T10:30:00Z",
  "event_source": "storefront",
  "id": "1",
  "source": "storefront",
  "shop_id": "1",
  "shop_source": "storefront",
  "user_id": "1",
  "user_source": "storefront",
  "rating": 5,
  "description": "Отличный магазин!",
  "author_name": "Иван И.",
  "author_phone": "+79991234567",
  "is_published": false,
  "created_at": "2024-01-15T10:30:00Z"
}
```

### 3.2 product_review.created

```json
{
  "event_id": "uuid",
  "event_type": "created",
  "event_timestamp": "2024-01-15T10:30:00Z",
  "event_source": "storefront",
  "id": "1",
  "source": "storefront",
  "product_id": "ext_prod_123",
  "product_source": "bagisto",
  "shop_id": "1",
  "shop_source": "storefront",
  "user_id": "1",
  "user_source": "storefront",
  "rating": 5,
  "description": "Вкусное молоко!",
  "author_name": "Мария М.",
  "author_phone": "+79997654321",
  "is_published": false,
  "created_at": "2024-01-15T10:30:00Z"
}
```

---

## 4. Реализация

### 4.1 Конфигурация entities

```php
'store_reviews' => [
    'enabled' => true, // Публикуем
    'model' => \App\Models\StoreReview::class,
    'transformer' => \App\RabbitMQ\Transformers\StoreReviewEventTransformer::class,
    'events' => ['created', 'updated'],
    'routing_key_prefix' => 'merchant_review',
    'exchange' => 'default',
    'condition' => \App\RabbitMQ\Conditions\ReviewHasUserCondition::class,
],

'product_reviews' => [
    'enabled' => true, // Публикуем
    'model' => \App\Models\ProductReview::class,
    'transformer' => \App\RabbitMQ\Transformers\ProductReviewEventTransformer::class,
    'events' => ['created', 'updated'],
    'routing_key_prefix' => 'product_review',
    'exchange' => 'default',
    'condition' => \App\RabbitMQ\Conditions\ReviewHasUserCondition::class,
],
```

### 4.2 StoreReviewEventTransformer (дополнить toEvent)

```php
public function toEvent(Model $review, ?Model $shop = null, ?string $eventId = null): array
{
    $review->load(['store', 'user']);

    // Получить external_id магазина
    $storeMeta = EventSyncMetadata::where('entity_type', 'shops')
        ->where('entity_id', $review->store_id)
        ->first();

    return [
        'event_id' => $eventId ?? Str::uuid()->toString(),
        'event_timestamp' => $review->updated_at->toIso8601String(),
        'event_source' => config('rabbitmq-events.source'),
        'id' => (string) $review->id,
        'source' => config('rabbitmq-events.source'),
        'shop_id' => $storeMeta?->external_id ?? (string) $review->store_id,
        'shop_source' => $storeMeta?->source ?? config('rabbitmq-events.source'),
        'user_id' => (string) $review->user_id,
        'user_source' => config('rabbitmq-events.source'),
        'rating' => $review->rating,
        'description' => $review->description,
        'author_name' => $review->author_name,
        'author_phone' => $review->author_phone,
        'is_published' => $review->is_published,
        'created_at' => $review->created_at->toIso8601String(),
    ];
}
```

### 4.3 ProductReviewEventTransformer (дополнить toEvent)

```php
public function toEvent(Model $review, ?Model $shop = null, ?string $eventId = null): array
{
    $review->load(['product', 'store', 'user']);

    // Получить external_id товара
    $productMeta = EventSyncMetadata::where('entity_type', 'products')
        ->where('entity_id', $review->product_id)
        ->first();

    // Получить external_id магазина
    $storeMeta = EventSyncMetadata::where('entity_type', 'shops')
        ->where('entity_id', $review->store_id)
        ->first();

    return [
        'event_id' => $eventId ?? Str::uuid()->toString(),
        'event_timestamp' => $review->updated_at->toIso8601String(),
        'event_source' => config('rabbitmq-events.source'),
        'id' => (string) $review->id,
        'source' => config('rabbitmq-events.source'),
        'product_id' => $productMeta?->external_id ?? (string) $review->product_id,
        'product_source' => $productMeta?->source ?? config('rabbitmq-events.source'),
        'shop_id' => $storeMeta?->external_id ?? (string) $review->store_id,
        'shop_source' => $storeMeta?->source ?? config('rabbitmq-events.source'),
        'user_id' => (string) $review->user_id,
        'user_source' => config('rabbitmq-events.source'),
        'rating' => $review->rating,
        'description' => $review->description,
        'author_name' => $review->author_name,
        'author_phone' => $review->author_phone,
        'is_published' => $review->is_published,
        'created_at' => $review->created_at->toIso8601String(),
    ];
}
```

### 4.4 Condition

```php
<?php

declare(strict_types=1);

namespace App\RabbitMQ\Conditions;

use Comrades\RabbitMQEvents\Contracts\PublishCondition;
use Illuminate\Database\Eloquent\Model;

/**
 * Публиковать только отзывы от авторизованных пользователей.
 * Внешние отзывы (source != storefront) не публикуем обратно.
 */
final class ReviewHasUserCondition implements PublishCondition
{
    public function __invoke(Model $review): bool
    {
        // Не публиковать внешние отзывы обратно
        if ($review->source && $review->source !== config('rabbitmq-events.source')) {
            return false;
        }

        return $review->user_id !== null;
    }
}
```

---

## 5. Тесты

```php
public function test_store_review_created_event_published(): void
{
    $store = Store::factory()->create();
    $user = User::factory()->create();

    $review = StoreReview::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'rating' => 5,
        'description' => 'Отлично!',
        'author_name' => 'Иван И.',
        'author_phone' => $user->phone,
    ]);

    $this->assertDatabaseHas('event_outbox', [
        'entity_type' => 'store_reviews',
        'entity_id' => $review->id,
        'event_type' => 'created',
    ]);
}

public function test_external_review_not_published(): void
{
    $store = Store::factory()->create();

    // Отзыв из внешней системы (без user_id, с source)
    $review = StoreReview::create([
        'store_id' => $store->id,
        'user_id' => null,
        'source' => 'bagisto',
        'rating' => 4,
        'author_name' => 'Внешний отзыв',
    ]);

    $this->assertDatabaseMissing('event_outbox', [
        'entity_type' => 'store_reviews',
        'entity_id' => $review->id,
    ]);
}

public function test_product_review_includes_external_product_id(): void
{
    $store = Store::factory()->create();
    $product = Product::factory()->for($store)->create();
    $user = User::factory()->create();

    // Создаём metadata для товара
    EventSyncMetadata::create([
        'entity_type' => 'products',
        'entity_id' => $product->id,
        'external_id' => 'ext_prod_123',
        'source' => 'bagisto',
        'shop_code' => $store->code,
        'last_event_id' => Str::uuid()->toString(),
        'last_event_timestamp' => now(),
    ]);

    $review = ProductReview::create([
        'product_id' => $product->id,
        'store_id' => $store->id,
        'user_id' => $user->id,
        'rating' => 5,
        'description' => 'Вкусно!',
        'author_name' => 'Мария М.',
    ]);

    $outbox = EventOutbox::where('entity_id', $review->id)
        ->where('entity_type', 'product_reviews')
        ->first();

    $payload = json_decode($outbox->payload, true);

    $this->assertEquals('ext_prod_123', $payload['product_id']);
    $this->assertEquals('bagisto', $payload['product_source']);
}
```

---

## 6. Ожидаемый результат

### Файлы
- [ ] Дополнить `StoreReviewEventTransformer::toEvent()`
- [ ] Дополнить `ProductReviewEventTransformer::toEvent()`
- [ ] `app/RabbitMQ/Conditions/ReviewHasUserCondition.php`
- [ ] Обновлён `config/rabbitmq-events.php`

### Тесты
- [ ] `ReviewPublisherTest::test_store_review_created_event_published`
- [ ] `ReviewPublisherTest::test_store_review_updated_event_published`
- [ ] `ReviewPublisherTest::test_product_review_created_event_published`
- [ ] `ReviewPublisherTest::test_external_review_not_published`
- [ ] `ReviewPublisherTest::test_includes_external_ids`

### Команды
```bash
php artisan rabbitmq:publish-outbox store_reviews
php artisan rabbitmq:publish-outbox product_reviews
```

---

## 7. Критерии готовности

- [ ] Отзывы от пользователей публикуются
- [ ] Внешние отзывы (source != storefront) не публикуются обратно
- [ ] External ID товаров/магазинов включены в payload
- [ ] Тесты проходят
