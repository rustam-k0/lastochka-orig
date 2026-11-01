# RabbitMQ: Входящие — Отзывы (reviews)

**Приоритет:** Низкий
**Зависимости:** `04_incoming_products.md`
**Содержит:** StoreReview, ProductReview

---

## 1. Цель

Реализовать приём отзывов на мерчантов и товары из внешней админ-панели (модерация).

---

## 2. События

| Entity | Model | Routing Keys |
|--------|-------|--------------|
| `merchant_reviews` | StoreReview | `merchant_review.created`, `merchant_review.updated`, `merchant_review.deleted` |
| `product_reviews` | ProductReview | `product_review.created`, `product_review.updated`, `product_review.deleted` |

---

## 3. Merchant Reviews (Отзывы на мерчантов)

### 3.1 Маппинг

```
Event                    → Model (StoreReview)
─────────────────────────────────────────────────
id                       → external_id
source                   → source
shop_id + shop_source    → store_id
rating                   → rating (1-5)
description              → description
is_published             → is_published
author_name              → author_name
author_phone             → author_phone
created_at               → created_at
```

### 3.2 Payload

```json
{
  "event_id": "uuid",
  "event_type": "created",
  "id": "review_1",
  "source": "bagisto",
  "shop_id": "shop_1",
  "shop_source": "bagisto",
  "rating": 5,
  "description": "Отличный магазин, быстрая доставка!",
  "is_published": true,
  "author_name": "Иван И.",
  "author_phone": "+79991234567",
  "created_at": "2024-01-15T10:30:00Z"
}
```

### 3.3 Transformer

```php
<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Store;
use App\Models\StoreReview;
use Illuminate\Database\Eloquent\Model;

final class StoreReviewEventTransformer extends BaseEventTransformer
{
    public function toEvent(Model $review, ?Model $shop = null, ?string $eventId = null): array
    {
        return []; // Реализуется в 08_outgoing_reviews.md
    }

    public function fromEvent(array $data): array
    {
        $storeId = $this->resolveEntityId('shops', $data['shop_id'], $data['shop_source']);

        return [
            'store_id' => $storeId,
            'source' => $data['source'],
            'user_id' => null, // Внешние отзывы без привязки к user
            'rating' => (int) $data['rating'],
            'description' => $data['description'] ?? null,
            'author_name' => $data['author_name'] ?? null,
            'author_phone' => $data['author_phone'] ?? null,
            'is_published' => $data['is_published'] ?? false,
            'published_at' => ($data['is_published'] ?? false) ? now() : null,
        ];
    }

    public function validate(array $data): bool
    {
        return isset(
            $data['event_id'],
            $data['id'],
            $data['source'],
            $data['shop_id'],
            $data['shop_source'],
            $data['rating']
        );
    }

    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        if (!$transformedData['store_id']) {
            return null; // Магазин не найден
        }

        return $transformedData;
    }

    /**
     * После создания/обновления — пересчитать рейтинг магазина.
     */
    public function afterCreate(Model $review, array $eventData): void
    {
        if ($review->is_published) {
            $this->recalculateStoreRating($review->store_id);
        }
    }

    public function afterUpdate(Model $review, array $eventData): void
    {
        $this->recalculateStoreRating($review->store_id);
    }

    /**
     * Пересчитать средний рейтинг магазина.
     */
    private function recalculateStoreRating(int $storeId): void
    {
        $stats = StoreReview::where('store_id', $storeId)
            ->where('is_published', true)
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as count')
            ->first();

        Store::where('id', $storeId)->update([
            'rating' => round($stats->avg_rating, 1),
            'reviews_count' => $stats->count,
        ]);
    }
}
```

---

## 4. Product Reviews (Отзывы на товары)

### 4.1 Маппинг

```
Event                       → Model (ProductReview)
─────────────────────────────────────────────────────
id                          → external_id
source                      → source
product_id + product_source → product_id
shop_id + shop_source       → store_id (денормализация)
rating                      → rating (1-5)
description                 → description
is_published                → is_published
author_name                 → author_name
author_phone                → author_phone
created_at                  → created_at
```

### 4.2 Payload

```json
{
  "event_id": "uuid",
  "event_type": "created",
  "id": "review_1",
  "source": "bagisto",
  "product_id": "prod_123",
  "product_source": "bagisto",
  "shop_id": "shop_1",
  "shop_source": "bagisto",
  "rating": 5,
  "description": "Вкусное молоко!",
  "is_published": true,
  "author_name": "Мария М.",
  "author_phone": "+79997654321",
  "created_at": "2024-01-15T10:30:00Z"
}
```

### 4.3 Transformer

```php
<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Model;

final class ProductReviewEventTransformer extends BaseEventTransformer
{
    public function toEvent(Model $review, ?Model $shop = null, ?string $eventId = null): array
    {
        return []; // Реализуется в 08_outgoing_reviews.md
    }

    public function fromEvent(array $data): array
    {
        $productId = $this->resolveEntityId('products', $data['product_id'], $data['product_source']);
        $storeId = $this->resolveEntityId('shops', $data['shop_id'], $data['shop_source']);

        return [
            'product_id' => $productId,
            'store_id' => $storeId,
            'source' => $data['source'],
            'user_id' => null, // Внешние отзывы без привязки к user
            'rating' => (int) $data['rating'],
            'description' => $data['description'] ?? null,
            'author_name' => $data['author_name'] ?? null,
            'author_phone' => $data['author_phone'] ?? null,
            'is_published' => $data['is_published'] ?? false,
            'published_at' => ($data['is_published'] ?? false) ? now() : null,
        ];
    }

    public function validate(array $data): bool
    {
        return isset(
            $data['event_id'],
            $data['id'],
            $data['source'],
            $data['product_id'],
            $data['product_source'],
            $data['shop_id'],
            $data['shop_source'],
            $data['rating']
        );
    }

    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        if (!$transformedData['product_id'] || !$transformedData['store_id']) {
            return null;
        }

        return $transformedData;
    }

    public function afterCreate(Model $review, array $eventData): void
    {
        if ($review->is_published) {
            $this->recalculateProductRating($review->product_id);
        }
    }

    public function afterUpdate(Model $review, array $eventData): void
    {
        $this->recalculateProductRating($review->product_id);
    }

    private function recalculateProductRating(int $productId): void
    {
        $stats = ProductReview::where('product_id', $productId)
            ->where('is_published', true)
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as count')
            ->first();

        Product::where('id', $productId)->update([
            'rating' => round($stats->avg_rating, 1),
            'reviews_count' => $stats->count,
        ]);
    }
}
```

---

## 5. Конфигурация

```php
'merchant_reviews' => [
    'enabled' => false, // Только consume
    'model' => \App\Models\StoreReview::class,
    'transformer' => \App\RabbitMQ\Transformers\StoreReviewEventTransformer::class,
    'routing_key_patterns' => [
        'merchant_review.created',
        'merchant_review.updated',
        'merchant_review.deleted',
    ],
],

'product_reviews' => [
    'enabled' => false, // Только consume
    'model' => \App\Models\ProductReview::class,
    'transformer' => \App\RabbitMQ\Transformers\ProductReviewEventTransformer::class,
    'routing_key_patterns' => [
        'product_review.created',
        'product_review.updated',
        'product_review.deleted',
    ],
],
```

---

## 6. Миграции

```php
// add_source_to_store_reviews_table
Schema::table('store_reviews', function (Blueprint $table) {
    $table->string('source')->nullable()->after('store_id');
});

// add_source_to_product_reviews_table
Schema::table('product_reviews', function (Blueprint $table) {
    $table->string('source')->nullable()->after('product_id');
});

// add_rating_fields_to_stores (если ещё нет)
Schema::table('stores', function (Blueprint $table) {
    $table->decimal('rating', 2, 1)->nullable();
    $table->integer('reviews_count')->default(0);
});

// add_rating_fields_to_products (если ещё нет)
Schema::table('products', function (Blueprint $table) {
    $table->decimal('rating', 2, 1)->nullable();
    $table->integer('reviews_count')->default(0);
});
```

---

## 7. Тесты

```php
public function test_store_review_created_recalculates_rating(): void
{
    $store = Store::factory()->create(['code' => 'MSK_001', 'rating' => null]);

    // Создаём metadata для магазина
    EventSyncMetadata::create([
        'entity_type' => 'shops',
        'entity_id' => $store->id,
        'external_id' => 'shop_1',
        'source' => 'bagisto',
        'shop_code' => 'MSK_001',
        'last_event_id' => Str::uuid()->toString(),
        'last_event_timestamp' => now(),
    ]);

    $eventData = [
        'event_id' => Str::uuid()->toString(),
        'event_type' => 'created',
        'id' => 'review_1',
        'source' => 'bagisto',
        'shop_id' => 'shop_1',
        'shop_source' => 'bagisto',
        'rating' => 5,
        'description' => 'Отлично!',
        'is_published' => true,
        'author_name' => 'Иван',
    ];

    $consumer = new EventConsumer('merchant_reviews', config('rabbitmq-events.entities.merchant_reviews'));
    $consumer->processEvent($eventData);

    $store->refresh();
    $this->assertEquals(5.0, $store->rating);
    $this->assertEquals(1, $store->reviews_count);
}
```

---

## 8. Ожидаемый результат

### Файлы
- [ ] `app/RabbitMQ/Transformers/StoreReviewEventTransformer.php`
- [ ] `app/RabbitMQ/Transformers/ProductReviewEventTransformer.php`
- [ ] Миграции для `source` и `rating` полей

### Тесты
- [ ] `StoreReviewEventHandlerTest::test_review_created`
- [ ] `StoreReviewEventHandlerTest::test_rating_recalculated`
- [ ] `ProductReviewEventHandlerTest::test_review_created`
- [ ] `ProductReviewEventHandlerTest::test_rating_recalculated`

### Команды
```bash
php artisan rabbitmq:consume merchant_reviews
php artisan rabbitmq:consume product_reviews
```

---

## 9. Критерии готовности

- [ ] Consumers обрабатывают `merchant_review.*` и `product_review.*`
- [ ] Рейтинг магазина/товара пересчитывается при публикации
- [ ] Только опубликованные отзывы влияют на рейтинг
- [ ] Тесты проходят
