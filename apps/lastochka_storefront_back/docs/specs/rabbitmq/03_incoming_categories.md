# RabbitMQ: Входящие — Категории (categories)

**Приоритет:** Критический
**Зависимости:** `02_incoming_shops.md`
**Блокирует:** `04_incoming_products.md`

---

## 1. Цель

Реализовать приём и обработку событий категорий с поддержкой иерархии (parent → child).

---

## 2. События

| Routing Key | Действие |
|-------------|----------|
| `category.created` | Создать категорию |
| `category.updated` | Обновить категорию |
| `category.deleted` | Soft delete категории |

---

## 3. Маппинг полей

```
Event                    → Model (Category)
─────────────────────────────────────────────
id                       → external_id (в metadata)
shop_code                → store_id (через Store::where('code'))
source                   → source
parent_id + parent_source → parent_id (через metadata)
title                    → title
image_url                → image_path (скачать)
active                   → is_active
meta_title               → meta_title
meta_description         → meta_description
meta_keywords            → meta_keywords
created_at               → created_at
updated_at               → updated_at
deleted_at               → deleted_at
```

---

## 4. Входящий payload

```json
{
  "event_id": "uuid",
  "event_type": "created",
  "event_timestamp": "2024-01-15T10:30:00Z",
  "id": "cat_123",
  "shop_code": "MSK_001",
  "source": "bagisto",
  "parent_id": "cat_100",
  "parent_source": "bagisto",
  "title": "Молочные продукты",
  "image_url": "https://cdn.example.com/categories/milk.jpg",
  "active": true,
  "meta_title": "Молочные продукты - купить",
  "meta_description": "Свежие молочные продукты с доставкой",
  "meta_keywords": "молоко, сыр, творог",
  "created_at": "2024-01-15T10:30:00Z"
}
```

---

## 5. Особенности

### 5.1 Иерархия категорий

Категория может иметь `parent_id` + `parent_source`. Родительская категория может:
- Уже существовать → найти через `event_sync_metadata`
- Ещё не создана → использовать `EventPendingReference`

### 5.2 Привязка к магазину

Категория привязывается к магазину по `shop_code`. Если магазин не найден — событие откладывается через `EventPendingReference`.

### 5.3 Изображения

`image_url` — URL внешнего изображения. Нужно:
1. Скачать изображение
2. Сохранить локально в `storage/app/public/categories/`
3. Записать относительный путь в `image_path`

---

## 6. Реализация

### 6.1 Миграция

```php
Schema::table('categories', function (Blueprint $table) {
    $table->string('source')->nullable()->after('store_id');
    $table->index(['store_id', 'source']);
});
```

### 6.2 Конфигурация entity

```php
'categories' => [
    'enabled' => false, // Только consume
    'model' => \App\Models\Category::class,
    'transformer' => \App\RabbitMQ\Transformers\CategoryEventTransformer::class,
    'relations' => ['parent', 'store'],
    'routing_key_patterns' => [
        'category.created',
        'category.updated',
        'category.deleted',
    ],
],
```

### 6.3 Transformer

```php
<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Category;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventPendingReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class CategoryEventTransformer extends BaseEventTransformer
{
    public function toEvent(Model $category, ?Model $shop = null, ?string $eventId = null): array
    {
        return []; // Только consume
    }

    public function fromEvent(array $data): array
    {
        $storeId = $this->resolveStoreId($data['shop_code']);

        return [
            'store_id' => $storeId,
            'source' => $data['source'],
            'parent_id' => null, // Устанавливается в afterCreate/afterUpdate
            'title' => $data['title'],
            'image_path' => null, // Устанавливается в afterCreate
            'is_active' => $data['active'] ?? true,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'meta_keywords' => $data['meta_keywords'] ?? null,
        ];
    }

    public function validate(array $data): bool
    {
        return isset($data['event_id'], $data['id'], $data['shop_code'], $data['source'], $data['title']);
    }

    /**
     * Перед созданием — проверить что магазин существует.
     */
    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        if (!$transformedData['store_id']) {
            Log::warning('Category event: store not found, creating pending reference', [
                'shop_code' => $eventData['shop_code'],
                'category_external_id' => $eventData['id'],
            ]);

            // Магазин ещё не создан — отложить
            return null;
        }

        return $transformedData;
    }

    /**
     * После создания — скачать изображение и связать с родителем.
     */
    public function afterCreate(Model $category, array $eventData): void
    {
        $this->processImage($category, $eventData);
        $this->linkParent($category, $eventData);
    }

    /**
     * После обновления — обновить изображение и родителя.
     */
    public function afterUpdate(Model $category, array $eventData): void
    {
        $this->processImage($category, $eventData);
        $this->linkParent($category, $eventData);
    }

    /**
     * Скачать изображение категории.
     */
    private function processImage(Category $category, array $eventData): void
    {
        $imageUrl = $eventData['image_url'] ?? null;

        if (!$imageUrl) {
            return;
        }

        try {
            $localPath = $this->downloadImage($imageUrl, 'categories');
            $category->update(['image_path' => $localPath]);
        } catch (\Exception $e) {
            Log::warning('Failed to download category image', [
                'category_id' => $category->id,
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Связать с родительской категорией.
     */
    private function linkParent(Category $category, array $eventData): void
    {
        $parentId = $eventData['parent_id'] ?? null;
        $parentSource = $eventData['parent_source'] ?? null;

        if (!$parentId || !$parentSource) {
            return;
        }

        // Найти родителя через metadata
        $parentEntityId = $this->resolveEntityId(
            'categories',
            $parentId,
            $parentSource,
            $eventData['shop_code']
        );

        if ($parentEntityId) {
            $category->update(['parent_id' => $parentEntityId]);
            return;
        }

        // Родитель ещё не создан — создать pending reference
        EventPendingReference::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'reference_type' => 'categories',
            'reference_source' => $parentSource,
            'reference_external_id' => $parentId,
            'shop_code' => $eventData['shop_code'],
            'field_name' => 'parent_id',
            'description' => "Category {$category->id} waiting for parent category",
        ]);

        Log::info('Created pending reference for parent category', [
            'category_id' => $category->id,
            'parent_external_id' => $parentId,
            'parent_source' => $parentSource,
        ]);
    }

    /**
     * Найти магазин по code.
     */
    private function resolveStoreId(string $shopCode): ?int
    {
        return Store::where('code', $shopCode)->value('id');
    }

    /**
     * Поиск существующей категории.
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $storeId = $this->resolveStoreId($eventData['shop_code']);

        if (!$storeId) {
            return null;
        }

        // Поиск по title в том же магазине
        return Category::where('store_id', $storeId)
            ->where('title', $eventData['title'])
            ->first();
    }
}
```

---

## 7. Тесты

```php
public function test_category_created_event_creates_category(): void
{
    $store = Store::factory()->create(['code' => 'MSK_001']);

    $eventData = [
        'event_id' => Str::uuid()->toString(),
        'event_type' => 'created',
        'event_timestamp' => now()->toIso8601String(),
        'id' => 'cat_123',
        'shop_code' => 'MSK_001',
        'source' => 'bagisto',
        'title' => 'Молочные продукты',
        'active' => true,
    ];

    $consumer = new EventConsumer('categories', config('rabbitmq-events.entities.categories'));
    $result = $consumer->processEvent($eventData);

    $this->assertEquals('ack', $result);
    $this->assertDatabaseHas('categories', [
        'store_id' => $store->id,
        'title' => 'Молочные продукты',
    ]);
}

public function test_category_with_parent_creates_pending_reference(): void
{
    $store = Store::factory()->create(['code' => 'MSK_001']);

    // Дочерняя категория приходит раньше родительской
    $childEventData = [
        'event_id' => Str::uuid()->toString(),
        'event_type' => 'created',
        'event_timestamp' => now()->toIso8601String(),
        'id' => 'cat_child',
        'shop_code' => 'MSK_001',
        'source' => 'bagisto',
        'parent_id' => 'cat_parent',
        'parent_source' => 'bagisto',
        'title' => 'Сыры',
    ];

    $consumer = new EventConsumer('categories', config('rabbitmq-events.entities.categories'));
    $consumer->processEvent($childEventData);

    $this->assertDatabaseHas('event_pending_references', [
        'entity_type' => 'categories',
        'reference_external_id' => 'cat_parent',
    ]);
}

public function test_pending_reference_resolved_when_parent_created(): void
{
    $store = Store::factory()->create(['code' => 'MSK_001']);

    // 1. Создаём дочернюю категорию (родитель ещё не существует)
    $childEventData = [
        'event_id' => Str::uuid()->toString(),
        'event_type' => 'created',
        'event_timestamp' => now()->toIso8601String(),
        'id' => 'cat_child',
        'shop_code' => 'MSK_001',
        'source' => 'bagisto',
        'parent_id' => 'cat_parent',
        'parent_source' => 'bagisto',
        'title' => 'Сыры',
    ];

    $consumer = new EventConsumer('categories', config('rabbitmq-events.entities.categories'));
    $consumer->processEvent($childEventData);

    // 2. Создаём родительскую категорию
    $parentEventData = [
        'event_id' => Str::uuid()->toString(),
        'event_type' => 'created',
        'event_timestamp' => now()->toIso8601String(),
        'id' => 'cat_parent',
        'shop_code' => 'MSK_001',
        'source' => 'bagisto',
        'title' => 'Молочные продукты',
    ];

    $consumer->processEvent($parentEventData);

    // 3. Запускаем резолвер
    Artisan::call('rabbitmq:resolve-pending-references');

    // 4. Проверяем что связь установлена
    $child = Category::where('title', 'Сыры')->first();
    $parent = Category::where('title', 'Молочные продукты')->first();

    $this->assertEquals($parent->id, $child->parent_id);
}
```

---

## 8. Ожидаемый результат

### Файлы
- [ ] `app/RabbitMQ/Transformers/CategoryEventTransformer.php`
- [ ] Миграция `add_source_to_categories_table`
- [ ] Обновлён `config/rabbitmq-events.php`

### Тесты
- [ ] `CategoryEventHandlerTest::test_category_created`
- [ ] `CategoryEventHandlerTest::test_category_with_parent`
- [ ] `CategoryEventHandlerTest::test_pending_reference_resolved`
- [ ] `CategoryEventHandlerTest::test_image_downloaded`
- [ ] `CategoryEventHandlerTest::test_store_not_found_skips`

### Команды
```bash
php artisan rabbitmq:consume categories
php artisan rabbitmq:resolve-pending-references
```

---

## 9. Критерии готовности

- [ ] Consumer обрабатывает `category.*` события
- [ ] Иерархия категорий через pending references работает
- [ ] Изображения скачиваются
- [ ] Привязка к магазину работает
- [ ] Тесты проходят
