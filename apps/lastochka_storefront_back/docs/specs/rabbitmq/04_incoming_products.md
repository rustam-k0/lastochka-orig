# RabbitMQ: Входящие — Товары (products)

**Приоритет:** Критический
**Зависимости:** `03_incoming_categories.md`
**Блокирует:** `05_incoming_marketing.md`

---

## 1. Цель

Реализовать приём и обработку событий товаров, включая изображения, атрибуты и изменения остатков.

---

## 2. События

| Routing Key | Действие |
|-------------|----------|
| `product.created` | Создать товар |
| `product.updated` | Обновить товар |
| `product.deleted` | Soft delete товара |
| `product.stock.changed` | Обновить только quantity |

---

## 3. Маппинг полей

```
Event                    → Model (Product)
─────────────────────────────────────────────────
id                       → external_id (в metadata)
shop_code                → store_id (через Store::where('code'))
source                   → source
sku                      → sku
barcodes                 → barcodes (JSON)
title                    → title
price                    → price (decimal 10,2)
discount_price           → price_old (ИНВЕРСИЯ!)
image_url                → ProductImage (primary)
quantity                 → quantity
unit_type                → unit_type (enum: kg, piece)
description              → description
meta_title               → meta_title
meta_description         → meta_description
meta_keywords            → meta_keywords
manufacturer             → manufacturer
country                  → country
brand                    → brand
weight                   → weight_grams
nds                      → vat_rate
active                   → is_active
category_id + category_source → category_id (через metadata)
attributes               → ProductAttribute[]
is_culinary              → is_culinary
```

**ВАЖНО: Инверсия цен!**
- В событии `price` — это базовая цена
- В событии `discount_price` — это цена со скидкой (новая цена)
- В модели `price` — текущая цена (что платит покупатель)
- В модели `price_old` — старая цена (перечёркнутая)

```php
// Логика маппинга
if ($eventData['discount_price']) {
    $modelData['price'] = $eventData['discount_price'];
    $modelData['price_old'] = $eventData['price'];
} else {
    $modelData['price'] = $eventData['price'];
    $modelData['price_old'] = null;
}
```

---

## 4. Входящий payload

```json
{
  "event_id": "uuid",
  "event_type": "created",
  "event_timestamp": "2024-01-15T10:30:00Z",
  "id": "prod_123",
  "shop_code": "MSK_001",
  "source": "bagisto",
  "sku": "MILK-32",
  "barcodes": ["4607001234567"],
  "title": "Молоко 3.2%",
  "price": 89.00,
  "discount_price": 76.00,
  "image_url": "https://cdn.example.com/products/milk.jpg",
  "quantity": 50,
  "unit_type": "piece",
  "description": "Свежее молоко 3.2% жирности",
  "meta_title": "Молоко 3.2% - купить",
  "manufacturer": "Простоквашино",
  "country": "Россия",
  "brand": "Простоквашино",
  "weight": 1000,
  "nds": 10,
  "active": true,
  "category_id": "cat_123",
  "category_source": "bagisto",
  "attributes": [
    {"code": "calories", "name": "Калории", "value": 60},
    {"code": "proteins", "name": "Белки", "value": 3.2},
    {"code": "fats", "name": "Жиры", "value": 3.2},
    {"code": "carbs", "name": "Углеводы", "value": 4.7},
    {"code": "shelf_life", "name": "Срок хранения", "value": "14 дней"}
  ],
  "is_culinary": false,
  "created_at": "2024-01-15T10:30:00Z"
}
```

---

## 5. Custom Event: product.stock.changed

Событие для обновления только остатков (без полного payload):

```json
{
  "event_id": "uuid",
  "event_type": "stock_changed",
  "event_timestamp": "2024-01-15T10:30:00Z",
  "id": "prod_123",
  "shop_code": "MSK_001",
  "source": "bagisto",
  "quantity": 45
}
```

---

## 6. Реализация

### 6.1 Миграция

```php
Schema::table('products', function (Blueprint $table) {
    $table->string('source')->nullable()->after('store_id');
    $table->index(['store_id', 'source']);
    $table->index(['sku', 'store_id']);
});
```

### 6.2 Конфигурация entity

```php
'products' => [
    'enabled' => false, // Только consume
    'model' => \App\Models\Product::class,
    'transformer' => \App\RabbitMQ\Transformers\ProductEventTransformer::class,
    'relations' => ['store', 'category', 'images', 'attributes'],
    'routing_key_patterns' => [
        'product.created',
        'product.updated',
        'product.deleted',
        'product.stock.changed',
    ],
],
```

### 6.3 Transformer

```php
<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Enums\UnitType;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductImage;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventPendingReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class ProductEventTransformer extends BaseEventTransformer
{
    // КБЖУ атрибуты
    private const NUTRITION_CODES = ['calories', 'proteins', 'fats', 'carbs'];

    public function toEvent(Model $product, ?Model $shop = null, ?string $eventId = null): array
    {
        return []; // Только consume
    }

    public function fromEvent(array $data): array
    {
        $storeId = $this->resolveStoreId($data['shop_code']);

        // Инверсия цен
        $price = (float) $data['price'];
        $discountPrice = isset($data['discount_price']) ? (float) $data['discount_price'] : null;

        return [
            'store_id' => $storeId,
            'source' => $data['source'],
            'category_id' => null, // Устанавливается в afterCreate
            'sku' => $data['sku'] ?? null,
            'barcodes' => $data['barcodes'] ?? null,
            'title' => $data['title'],
            'price' => $discountPrice ?? $price,
            'price_old' => $discountPrice ? $price : null,
            'quantity' => $data['quantity'] ?? 0,
            'unit_type' => $this->mapUnitType($data['unit_type'] ?? 'piece'),
            'description' => $data['description'] ?? null,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'meta_keywords' => $data['meta_keywords'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'country' => $data['country'] ?? null,
            'brand' => $data['brand'] ?? null,
            'weight_grams' => $data['weight'] ?? null,
            'vat_rate' => $data['nds'] ?? null,
            'is_active' => $data['active'] ?? true,
            'is_culinary' => $data['is_culinary'] ?? false,
        ];
    }

    public function validate(array $data): bool
    {
        return isset(
            $data['event_id'],
            $data['id'],
            $data['shop_code'],
            $data['source'],
            $data['title'],
            $data['price']
        );
    }

    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        if (!$transformedData['store_id']) {
            Log::warning('Product event: store not found', [
                'shop_code' => $eventData['shop_code'],
            ]);
            return null;
        }

        return $transformedData;
    }

    public function afterCreate(Model $product, array $eventData): void
    {
        $this->linkCategory($product, $eventData);
        $this->processImage($product, $eventData);
        $this->processAttributes($product, $eventData);
    }

    public function afterUpdate(Model $product, array $eventData): void
    {
        $this->linkCategory($product, $eventData);
        $this->processImage($product, $eventData);
        $this->processAttributes($product, $eventData);
    }

    /**
     * Custom event handler: product.stock.changed
     */
    public function processStockChangedEvent(Model $product, array $eventData): void
    {
        $product->update([
            'quantity' => (int) ($eventData['quantity'] ?? 0),
        ]);

        Log::info('Product stock updated', [
            'product_id' => $product->id,
            'quantity' => $eventData['quantity'],
        ]);
    }

    /**
     * Связать с категорией.
     */
    private function linkCategory(Product $product, array $eventData): void
    {
        $categoryId = $eventData['category_id'] ?? null;
        $categorySource = $eventData['category_source'] ?? null;

        if (!$categoryId || !$categorySource) {
            return;
        }

        $categoryEntityId = $this->resolveEntityId(
            'categories',
            $categoryId,
            $categorySource,
            $eventData['shop_code']
        );

        if ($categoryEntityId) {
            $product->update(['category_id' => $categoryEntityId]);
            return;
        }

        // Категория ещё не создана
        EventPendingReference::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'reference_type' => 'categories',
            'reference_source' => $categorySource,
            'reference_external_id' => $categoryId,
            'shop_code' => $eventData['shop_code'],
            'field_name' => 'category_id',
            'description' => "Product {$product->id} waiting for category",
        ]);
    }

    /**
     * Скачать и сохранить основное изображение.
     */
    private function processImage(Product $product, array $eventData): void
    {
        $imageUrl = $eventData['image_url'] ?? null;

        if (!$imageUrl) {
            return;
        }

        try {
            $localPath = $this->downloadImage($imageUrl, 'products');

            // Удаляем старое primary изображение
            $product->images()->where('is_primary', true)->delete();

            // Создаём новое
            ProductImage::create([
                'product_id' => $product->id,
                'path' => $localPath,
                'is_primary' => true,
                'position' => 0,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to download product image', [
                'product_id' => $product->id,
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Синхронизировать атрибуты товара.
     */
    private function processAttributes(Product $product, array $eventData): void
    {
        $attributes = $eventData['attributes'] ?? [];

        if (empty($attributes)) {
            return;
        }

        // Удаляем старые атрибуты
        $product->attributes()->delete();

        // Создаём новые
        foreach ($attributes as $attr) {
            ProductAttribute::create([
                'product_id' => $product->id,
                'code' => $attr['code'],
                'name' => $attr['name'],
                'value' => (string) $attr['value'],
            ]);
        }

        // Извлекаем КБЖУ в основную модель
        $this->extractNutrition($product, $attributes);
    }

    /**
     * Извлечь КБЖУ из атрибутов в поля модели.
     */
    private function extractNutrition(Product $product, array $attributes): void
    {
        $nutrition = [];

        foreach ($attributes as $attr) {
            if (in_array($attr['code'], self::NUTRITION_CODES, true)) {
                $nutrition[$attr['code']] = $attr['value'];
            }
        }

        if (!empty($nutrition)) {
            $product->update([
                'calories' => $nutrition['calories'] ?? null,
                'proteins' => $nutrition['proteins'] ?? null,
                'fats' => $nutrition['fats'] ?? null,
                'carbs' => $nutrition['carbs'] ?? null,
            ]);
        }
    }

    private function resolveStoreId(string $shopCode): ?int
    {
        return Store::where('code', $shopCode)->value('id');
    }

    private function mapUnitType(string $type): string
    {
        return match ($type) {
            'kg' => UnitType::Kg->value,
            default => UnitType::Piece->value,
        };
    }

    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $storeId = $this->resolveStoreId($eventData['shop_code']);

        if (!$storeId) {
            return null;
        }

        // Поиск по SKU в том же магазине
        if ($sku = $eventData['sku'] ?? null) {
            return Product::where('store_id', $storeId)
                ->where('sku', $sku)
                ->first();
        }

        return null;
    }
}
```

---

## 7. Тесты

```php
public function test_product_created_with_discount_price(): void
{
    $store = Store::factory()->create(['code' => 'MSK_001']);
    $category = Category::factory()->for($store)->create();

    // Создаём metadata для категории
    EventSyncMetadata::create([
        'entity_type' => 'categories',
        'entity_id' => $category->id,
        'external_id' => 'cat_123',
        'source' => 'bagisto',
        'shop_code' => 'MSK_001',
        'last_event_id' => Str::uuid()->toString(),
        'last_event_timestamp' => now(),
    ]);

    $eventData = [
        'event_id' => Str::uuid()->toString(),
        'event_type' => 'created',
        'event_timestamp' => now()->toIso8601String(),
        'id' => 'prod_123',
        'shop_code' => 'MSK_001',
        'source' => 'bagisto',
        'sku' => 'MILK-32',
        'title' => 'Молоко 3.2%',
        'price' => 89.00,
        'discount_price' => 76.00,
        'category_id' => 'cat_123',
        'category_source' => 'bagisto',
    ];

    $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
    $consumer->processEvent($eventData);

    $product = Product::where('sku', 'MILK-32')->first();

    // Проверяем инверсию цен
    $this->assertEquals(76.00, $product->price);
    $this->assertEquals(89.00, $product->price_old);
    $this->assertEquals($category->id, $product->category_id);
}

public function test_stock_changed_event_updates_quantity(): void
{
    $store = Store::factory()->create(['code' => 'MSK_001']);
    $product = Product::factory()->for($store)->create([
        'sku' => 'MILK-32',
        'quantity' => 100,
    ]);

    // Создаём metadata
    EventSyncMetadata::create([
        'entity_type' => 'products',
        'entity_id' => $product->id,
        'external_id' => 'prod_123',
        'source' => 'bagisto',
        'shop_code' => 'MSK_001',
        'last_event_id' => Str::uuid()->toString(),
        'last_event_timestamp' => now()->subMinute(),
    ]);

    $eventData = [
        'event_id' => Str::uuid()->toString(),
        'event_type' => 'stock_changed',
        'event_timestamp' => now()->toIso8601String(),
        'id' => 'prod_123',
        'shop_code' => 'MSK_001',
        'source' => 'bagisto',
        'quantity' => 45,
    ];

    $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
    $consumer->processEvent($eventData);

    $this->assertEquals(45, $product->fresh()->quantity);
}
```

---

## 8. Ожидаемый результат

### Файлы
- [ ] `app/RabbitMQ/Transformers/ProductEventTransformer.php`
- [ ] Миграция `add_source_to_products_table`

### Тесты
- [ ] `ProductEventHandlerTest::test_product_created`
- [ ] `ProductEventHandlerTest::test_price_inversion`
- [ ] `ProductEventHandlerTest::test_stock_changed`
- [ ] `ProductEventHandlerTest::test_attributes_synced`
- [ ] `ProductEventHandlerTest::test_image_downloaded`
- [ ] `ProductEventHandlerTest::test_category_pending_reference`

### Команды
```bash
php artisan rabbitmq:consume products
```

---

## 9. Критерии готовности

- [ ] Consumer обрабатывает `product.*` события
- [ ] Инверсия цен работает корректно
- [ ] `product.stock.changed` обновляет только quantity
- [ ] Изображения скачиваются
- [ ] Атрибуты и КБЖУ синхронизируются
- [ ] Тесты проходят
