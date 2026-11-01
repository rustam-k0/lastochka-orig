# RabbitMQ: Входящие — Маркетинг

**Приоритет:** Средний
**Зависимости:** `04_incoming_products.md`
**Содержит:** Stories, Promotions, ProductGroups, Banners, Promocodes

---

## 1. Цель

Реализовать приём маркетинговых сущностей: истории, акции, товарные группы, баннеры, промокоды.

---

## 2. Сущности

| Entity | Model | Routing Keys |
|--------|-------|--------------|
| `stories` | Story | `story.created`, `story.updated`, `story.deleted` |
| `merchant_sales` | Promotion | `merchant_sale.created`, `merchant_sale.updated`, `merchant_sale.deleted` |
| `merchant_product_groups` | ProductGroup | `merchant_product_group.created`, `merchant_product_group.updated`, `merchant_product_group.deleted` |
| `merchant_banners` | Banner | `merchant_banner.created`, `merchant_banner.updated`, `merchant_banner.deleted` |
| `merchant_promocodes` | Promocode | `merchant_promocode.created`, `merchant_promocode.updated`, `merchant_promocode.deleted` |

---

## 3. Stories (Истории)

### 3.1 Маппинг

```
Event                    → Model (Story)
─────────────────────────────────────────────
id                       → external_id
source                   → source
title                    → title
expires_at               → expires_at
link_type                → link_type (enum: none, product, store, external)
product_id + product_source → link_product_id
shop_id + shop_source    → link_store_id
external_url             → link_external_url
is_active                → is_active
thumbnail_image_url      → thumbnail_path (скачать)
images[]                 → StoryImage[] (скачать каждое)
```

### 3.2 Payload

```json
{
  "event_id": "uuid",
  "event_type": "created",
  "id": "story_1",
  "source": "bagisto",
  "title": "Новинки недели",
  "expires_at": "2024-01-20T23:59:59Z",
  "link_type": "product",
  "product_id": "prod_123",
  "product_source": "bagisto",
  "shop_id": null,
  "shop_source": null,
  "external_url": null,
  "is_active": true,
  "thumbnail_image_url": "https://cdn.example.com/story_thumb.jpg",
  "images": [
    {"id": "img_1", "url": "https://cdn.example.com/story1.jpg"},
    {"id": "img_2", "url": "https://cdn.example.com/story2.jpg"}
  ]
}
```

### 3.3 Transformer

```php
final class StoryEventTransformer extends BaseEventTransformer
{
    public function fromEvent(array $data): array
    {
        return [
            'source' => $data['source'],
            'title' => $data['title'],
            'expires_at' => $data['expires_at'],
            'link_type' => $data['link_type'] ?? 'none',
            'link_product_id' => null, // В afterCreate
            'link_store_id' => null,   // В afterCreate
            'link_external_url' => $data['external_url'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'thumbnail_path' => null,  // В afterCreate
        ];
    }

    public function afterCreate(Model $story, array $eventData): void
    {
        // Скачать thumbnail
        if ($url = $eventData['thumbnail_image_url'] ?? null) {
            $path = $this->downloadImage($url, 'stories/thumbnails');
            $story->update(['thumbnail_path' => $path]);
        }

        // Скачать изображения
        foreach ($eventData['images'] ?? [] as $index => $image) {
            $path = $this->downloadImage($image['url'], 'stories');
            StoryImage::create([
                'story_id' => $story->id,
                'path' => $path,
                'position' => $index,
            ]);
        }

        // Связать с товаром/магазином
        $this->linkReferences($story, $eventData);
    }

    private function linkReferences(Story $story, array $eventData): void
    {
        if ($eventData['link_type'] === 'product' && $eventData['product_id']) {
            $productId = $this->resolveEntityId(
                'products',
                $eventData['product_id'],
                $eventData['product_source']
            );
            $story->update(['link_product_id' => $productId]);
        }

        if ($eventData['link_type'] === 'store' && $eventData['shop_id']) {
            $storeId = $this->resolveEntityId(
                'shops',
                $eventData['shop_id'],
                $eventData['shop_source']
            );
            $story->update(['link_store_id' => $storeId]);
        }
    }
}
```

---

## 4. Promotions (Акции)

### 4.1 Маппинг

```
Event                    → Model (Promotion)
─────────────────────────────────────────────
id                       → external_id
source                   → source
shop_id + shop_source    → store_id
title                    → title
description              → description
image_url                → image_path (скачать)
discount_percent         → discount_percent
starts_at                → starts_at
ends_at                  → ends_at
is_active                → is_active
products[]               → PromotionProduct pivot
```

### 4.2 Payload

```json
{
  "event_id": "uuid",
  "event_type": "created",
  "id": "promo_1",
  "source": "bagisto",
  "shop_id": "shop_1",
  "shop_source": "bagisto",
  "title": "Молочная неделя",
  "description": "Скидки на всю молочную продукцию",
  "image_url": "https://cdn.example.com/promo.jpg",
  "discount_percent": 15,
  "starts_at": "2024-01-15T00:00:00Z",
  "ends_at": "2024-01-22T23:59:59Z",
  "is_active": true,
  "products": [
    {"product_id": "prod_1", "product_source": "bagisto"},
    {"product_id": "prod_2", "product_source": "bagisto"}
  ]
}
```

### 4.3 Transformer

```php
final class PromotionEventTransformer extends BaseEventTransformer
{
    public function fromEvent(array $data): array
    {
        $storeId = $this->resolveEntityId('shops', $data['shop_id'], $data['shop_source']);

        return [
            'store_id' => $storeId,
            'source' => $data['source'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'image_path' => null,
            'discount_percent' => $data['discount_percent'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    public function afterCreate(Model $promotion, array $eventData): void
    {
        $this->processImage($promotion, $eventData);
        $this->syncProducts($promotion, $eventData);
    }

    public function afterUpdate(Model $promotion, array $eventData): void
    {
        $this->processImage($promotion, $eventData);
        $this->syncProducts($promotion, $eventData);
    }

    private function syncProducts(Promotion $promotion, array $eventData): void
    {
        $productIds = [];

        foreach ($eventData['products'] ?? [] as $item) {
            $productId = $this->resolveEntityId(
                'products',
                $item['product_id'],
                $item['product_source']
            );

            if ($productId) {
                $productIds[] = $productId;
            }
        }

        $promotion->products()->sync($productIds);
    }
}
```

---

## 5. ProductGroups (Товарные группы)

### 5.1 Маппинг

```
Event                         → Model (ProductGroup)
─────────────────────────────────────────────────────
id                            → external_id
source                        → source
shop_id + shop_source         → store_id
title                         → title
image_url                     → image_path
show_on_main                  → show_on_home
show_on_merchant_page         → show_on_store_page
show_in_merchant_catalog      → show_in_catalog
is_active                     → is_active
products[]                    → ProductGroupItem pivot
```

### 5.2 Transformer

```php
final class ProductGroupEventTransformer extends BaseEventTransformer
{
    public function fromEvent(array $data): array
    {
        $storeId = $this->resolveEntityId('shops', $data['shop_id'], $data['shop_source']);

        return [
            'store_id' => $storeId,
            'source' => $data['source'],
            'title' => $data['title'],
            'image_path' => null,
            'show_on_home' => $data['show_on_main'] ?? false,
            'show_on_store_page' => $data['show_on_merchant_page'] ?? false,
            'show_in_catalog' => $data['show_in_merchant_catalog'] ?? false,
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    public function afterCreate(Model $group, array $eventData): void
    {
        $this->processImage($group, $eventData);
        $this->syncProducts($group, $eventData);
    }

    private function syncProducts(ProductGroup $group, array $eventData): void
    {
        $group->items()->delete();

        foreach ($eventData['products'] ?? [] as $index => $item) {
            $productId = $this->resolveEntityId(
                'products',
                $item['product_id'],
                $item['product_source']
            );

            if ($productId) {
                ProductGroupItem::create([
                    'product_group_id' => $group->id,
                    'product_id' => $productId,
                    'position' => $index,
                ]);
            }
        }
    }
}
```

---

## 6. Banners (Баннеры)

### 6.1 Маппинг

```
Event                         → Model (Banner)
─────────────────────────────────────────────────────
id                            → external_id
source                        → source
shop_id + shop_source         → store_id
title                         → title
image_url                     → image_path (скачать)
link_type                     → link_type (none, product, category)
product_id + product_source   → link_product_id
category_id + category_source → link_category_id
is_active                     → is_active
```

### 6.2 Transformer

```php
final class BannerEventTransformer extends BaseEventTransformer
{
    public function fromEvent(array $data): array
    {
        $storeId = $this->resolveEntityId('shops', $data['shop_id'], $data['shop_source']);

        return [
            'store_id' => $storeId,
            'source' => $data['source'],
            'title' => $data['title'],
            'image_path' => null,
            'link_type' => $data['link_type'] ?? 'none',
            'link_product_id' => null,
            'link_category_id' => null,
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    public function afterCreate(Model $banner, array $eventData): void
    {
        $this->processImage($banner, $eventData);
        $this->linkReferences($banner, $eventData);
    }

    private function linkReferences(Banner $banner, array $eventData): void
    {
        if ($eventData['link_type'] === 'product' && $eventData['product_id']) {
            $productId = $this->resolveEntityId(
                'products',
                $eventData['product_id'],
                $eventData['product_source']
            );
            $banner->update(['link_product_id' => $productId]);
        }

        if ($eventData['link_type'] === 'category' && $eventData['category_id']) {
            $categoryId = $this->resolveEntityId(
                'categories',
                $eventData['category_id'],
                $eventData['category_source']
            );
            $banner->update(['link_category_id' => $categoryId]);
        }
    }
}
```

---

## 7. Promocodes (Промокоды)

### 7.1 Маппинг

```
Event                    → Model (Promocode)
─────────────────────────────────────────────
id                       → external_id
source                   → source
shop_id + shop_source    → store_id
title                    → title
code                     → code
discount_percent         → value + type='percent'
valid_from               → starts_at
valid_to                 → ends_at
is_active                → is_active
```

### 7.2 Transformer

```php
final class PromocodeEventTransformer extends BaseEventTransformer
{
    public function fromEvent(array $data): array
    {
        $storeId = $this->resolveEntityId('shops', $data['shop_id'], $data['shop_source']);

        return [
            'store_id' => $storeId,
            'source' => $data['source'],
            'title' => $data['title'],
            'code' => $data['code'],
            'type' => 'percent',
            'value' => $data['discount_percent'],
            'starts_at' => $data['valid_from'],
            'ends_at' => $data['valid_to'],
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        // Поиск по code в том же магазине
        if (!$transformedData['store_id']) {
            return null;
        }

        return Promocode::where('store_id', $transformedData['store_id'])
            ->where('code', $eventData['code'])
            ->first();
    }
}
```

---

## 8. Конфигурация

Добавить в `config/rabbitmq-events.php`:

```php
'stories' => [
    'enabled' => false,
    'model' => \App\Models\Story::class,
    'transformer' => \App\RabbitMQ\Transformers\StoryEventTransformer::class,
    'routing_key_patterns' => ['story.created', 'story.updated', 'story.deleted'],
],

'merchant_sales' => [
    'enabled' => false,
    'model' => \App\Models\Promotion::class,
    'transformer' => \App\RabbitMQ\Transformers\PromotionEventTransformer::class,
    'routing_key_patterns' => ['merchant_sale.created', 'merchant_sale.updated', 'merchant_sale.deleted'],
],

'merchant_product_groups' => [
    'enabled' => false,
    'model' => \App\Models\ProductGroup::class,
    'transformer' => \App\RabbitMQ\Transformers\ProductGroupEventTransformer::class,
    'routing_key_patterns' => ['merchant_product_group.created', 'merchant_product_group.updated', 'merchant_product_group.deleted'],
],

'merchant_banners' => [
    'enabled' => false,
    'model' => \App\Models\Banner::class,
    'transformer' => \App\RabbitMQ\Transformers\BannerEventTransformer::class,
    'routing_key_patterns' => ['merchant_banner.created', 'merchant_banner.updated', 'merchant_banner.deleted'],
],

'merchant_promocodes' => [
    'enabled' => false,
    'model' => \App\Models\Promocode::class,
    'transformer' => \App\RabbitMQ\Transformers\PromocodeEventTransformer::class,
    'routing_key_patterns' => ['merchant_promocode.created', 'merchant_promocode.updated', 'merchant_promocode.deleted'],
],
```

---

## 9. Миграции

```php
// add_source_to_stories_table
Schema::table('stories', function (Blueprint $table) {
    $table->string('source')->nullable()->after('id');
});

// add_source_to_promotions_table
Schema::table('promotions', function (Blueprint $table) {
    $table->string('source')->nullable()->after('store_id');
});

// add_source_to_product_groups_table
Schema::table('product_groups', function (Blueprint $table) {
    $table->string('source')->nullable()->after('store_id');
});

// add_source_to_banners_table
Schema::table('banners', function (Blueprint $table) {
    $table->string('source')->nullable()->after('store_id');
});

// add_source_to_promocodes_table
Schema::table('promocodes', function (Blueprint $table) {
    $table->string('source')->nullable()->after('store_id');
});
```

---

## 10. Ожидаемый результат

### Файлы
- [ ] `app/RabbitMQ/Transformers/StoryEventTransformer.php`
- [ ] `app/RabbitMQ/Transformers/PromotionEventTransformer.php`
- [ ] `app/RabbitMQ/Transformers/ProductGroupEventTransformer.php`
- [ ] `app/RabbitMQ/Transformers/BannerEventTransformer.php`
- [ ] `app/RabbitMQ/Transformers/PromocodeEventTransformer.php`
- [ ] Миграции для добавления `source`

### Тесты
- [ ] `StoryEventHandlerTest`
- [ ] `PromotionEventHandlerTest`
- [ ] `ProductGroupEventHandlerTest`
- [ ] `BannerEventHandlerTest`
- [ ] `PromocodeEventHandlerTest`

### Команды
```bash
php artisan rabbitmq:consume stories
php artisan rabbitmq:consume merchant_sales
php artisan rabbitmq:consume merchant_product_groups
php artisan rabbitmq:consume merchant_banners
php artisan rabbitmq:consume merchant_promocodes
```

---

## 11. Критерии готовности

- [ ] Все 5 consumers работают
- [ ] Изображения скачиваются для stories, promotions, product_groups, banners
- [ ] Pivot-связи с товарами синхронизируются (promotions, product_groups)
- [ ] Ссылки на товары/категории/магазины резолвятся
- [ ] Тесты проходят
