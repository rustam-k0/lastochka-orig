# Справочник 1С-категорий + экран привязки 1С↔витринные категории — Design Spec

| Метаданные | |
|---|---|
| Задача Platrum | [LASTOCHKI-JAMI-10114](https://comrades.platrum.ru/tasks/task/LASTOCHKI-DZHAMI-%7C-LASTOCHKI-JAMI-10114) |
| Дата | 2026-05-05 |
| Origin форк | Lastochka |
| Распространение | Sync-core broadcast → Aladdin / Electrohub / Foodlink (dormant до явного включения через `projectModules`) |
| Затронутые сервисы | `1c_parser`, `admin_back`, `admin_front`, `multi_vendor_delivery_rabbitmq_package` |

---

## 1. Контекст и цель

### Что есть сейчас

Из 1С через CommerceML 2.x в платформу приезжают и товары, и категории товаров. Сегодня это работает так:

- `1c_parser` публикует в RabbitMQ два потока: `category.{created,updated,deleted}` и `product.{created,updated,deleted}`.
- `storefront_back` напрямую подписан на оба потока. 1С-категории сохраняются как `categories` с `source='1c'`, продукты — линкуются к ним через резолюцию `event_sync_metadata (source='1c', external_id)`.
- `admin_back` подписан на `product.*` (но не на `category.*`), пишет продукты в свою БД (`withoutEvents()`), также имеет собственную «витринную» модель `Category` (с CRUD из админки).

### Проблема

Категории 1С — это внутренний справочник учётной системы (товаровед/бухгалтер). Их структура отражает удобство учёта: избыточные, плохо названы, дубли, технические подкатегории. Прямой проброс в мобильное приложение даёт плохой UX витрины. Покупатель видит «Молоко 1.5%», «Молоко 3.2%», «Молоко безлактозное» вместо одной понятной «Молоко».

Полностью отказаться от выгрузки 1С-категорий нельзя — это связь, которая позволяет автоматически распределять товары по разделам без ручного труда товароведа.

### Решение

Разделить две сущности:
- **1С-категория** — read-only справочник в admin_back, наполняется из 1С через RabbitMQ. Админ её не редактирует.
- **Витринная категория** = существующая `Category` в admin_back (без переименования). Это то, что увидит покупатель в мобилке. Админ создаёт вручную, лаконичные группы.

Между ними — связь M:1 (одна 1С → ровно одна витринная; к одной витринной можно привязать N 1С). Управляется через новый экран курьерки с двумя колонками и drag-and-drop, по нажатию кнопки «Синхронизировать» массово применяется к товарам.

### Не-цели

- **НЕ меняем модель Product → Category** на M:N (ТЗ-пункт «один товар может быть в нескольких витринных» признан ошибкой при уточнении). Один товар = одна витринная категория.
- **НЕ автоматически переносим новые товары из 1С** в витринную после привязки — нужно явное действие админа «Синхронизировать». Это сознательное снижение автоматизации ради контроля.
- **НЕ чистим уже накопленные `source='1c'` Categories на storefront** — отдельной задачей очистки каталога позже.
- **НЕ переписываем существующий экран** «Категории товаров» в admin_front — это отдельный пункт меню, под `projectModules` toggle.

---

## 2. Архитектурный обзор

### Затронутые сервисы

| Сервис | Изменения |
|---|---|
| **1c_parser** | Публикация: новые routing keys `category_1c.{created,updated,deleted}` (вместо старых `category.*`). В payload product events удаляются `category_id` / `category_source`, добавляется `category_1c_external_id`. |
| **admin_back** | Новая таблица `categories_1c`, новая модель `Category1c`, новый `Category1cEventTransformer` (consumer), GraphQL types/queries/mutations, async-job `SyncCategoryMappingsJob`, новая таблица `category_1c_sync_batches`, новый FK `products.category_1c_id`, дополнения в `ProductEventTransformer` (резолв 1С-категории), observer на `Category::deleted` (cascade). |
| **admin_front** | Новый пункт меню «Категории 1С» (под `projectModules.category_1c_integration`), новый экран — двухколоночный с drag-and-drop, набор GraphQL клиентов, кнопка «Синхронизировать» с polling прогресса. Существующий экран «Категории товаров» не меняется. |
| **storefront_back** | **Code-changes отсутствуют.** `ProductEventTransformer::linkCategory()` уже conditional — при отсутствии `category_id` / `category_source` в payload не трогает `products.category_id`. После rollout 1c_parser перестаёт слать эти поля → существующие продукты застывают в текущих категориях, новые приходят без category. Только админский sync через admin_back переведёт их в витринные. |
| **multi_vendor_delivery_rabbitmq_package** | Регистрация event_types `category_1c.{created,updated,deleted}`, новая inbound queue `categories_1c` для admin_back. |

### High-level data flow

```
[1С/CommerceML]
   → [1c_parser] (синхронизирует свою БД)
   → publishes category_1c.* events → [RabbitMQ] → consumed by [admin_back]
                                                       └→ создаёт/обновляет categories_1c
   
   → publishes product.* events с category_1c_external_id (без category_id/category_source)
   → [admin_back] consumes:
        - резолвит external → categories_1c.id
        - пишет products.category_1c_id
        - products.category_id ОСТАЁТСЯ как есть (NULL у новых)
   → [storefront_back] consumes:
        - в payload нет category_id/category_source → products.category_id не трогается
        - существующие продукты остаются в старых категориях, новые — без category_id

[Админ в курьерке]
   → drag-and-drop / multi-select в admin_front
   → mutation link_categories_1c_to_showcase(ids, showcase_id)
   → UPDATE categories_1c SET showcase_category_id = ?, linked_at = now()

   → клик «Синхронизировать»
   → mutation sync_categories_1c(shop_id) → enqueue SyncCategoryMappingsJob
   → polling category_1c_sync_batch(shop_id)

[SyncCategoryMappingsJob]
   → mass UPDATE products SET category_id = c1c.showcase_category_id
   → каждому затронутому event(new ProductUpdated($p)) → outbox
   → outbox publisher → RabbitMQ → [storefront_back]
        - products.category_id обновляется на витринную
        - товары появляются в правильной витринной в мобилке
```

### Feature toggle

`projectModules.category_1c_integration` (стандартный паттерн платформы — JSON-ключ в `admin_settings.value` где `key='project_modules'`).

- Дефолт: `false` на всех форках кроме Lastochka.
- При выключенном: миграции применены, RabbitMQ очередь `categories_1c` слушает и пишет в БД, GraphQL типы существуют — но пункт меню в admin_front скрыт через `moduleStore.isModuleEnabled()`. Фактически dormant.
- Включается явно: `UPDATE admin_settings SET value = JSON_SET(value, '$.category_1c_integration', true) WHERE key='project_modules'` + `UPDATE menu_items SET is_visible=1 WHERE key='category_1c'`.

---

## 3. Data Model

### 3.1. Таблица `categories_1c`

```sql
CREATE TABLE categories_1c (
    id                       BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    shop_id                  BIGINT UNSIGNED NOT NULL,
    external_id              VARCHAR(64) NOT NULL,                 -- GUID из 1С
    parent_id                BIGINT UNSIGNED NULL,                 -- self-FK
    name                     VARCHAR(255) NOT NULL,
    position                 INT NOT NULL DEFAULT 0,
    is_active                BOOLEAN NOT NULL DEFAULT 1,           -- из 1С
    showcase_category_id     BIGINT UNSIGNED NULL,                 -- привязка к витринной
    linked_at                TIMESTAMP NULL,                       -- момент установки showcase_category_id
    last_synced_at           TIMESTAMP NULL,                       -- успешный sync action
    synced_from_1c_at        TIMESTAMP NULL,                       -- последний приход из 1С
    created_at               TIMESTAMP NULL,
    updated_at               TIMESTAMP NULL,
    deleted_at               TIMESTAMP NULL,                       -- soft delete

    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES categories_1c(id) ON DELETE SET NULL,
    FOREIGN KEY (showcase_category_id) REFERENCES categories(id) ON DELETE SET NULL,

    UNIQUE INDEX uniq_shop_external_deleted (shop_id, external_id, deleted_at),
    INDEX idx_shop_showcase (shop_id, showcase_category_id),
    INDEX idx_shop_deleted_name (shop_id, deleted_at, name),
    INDEX idx_parent (parent_id)
);
```

**Обоснования:**
- `parent_id` (FK self), не `parent_external_id` (string) — стандартный паттерн платформы (`storefront_back/CategoryEventTransformer` так и делает); резолвится через `event_sync_metadata` в `afterCreate`/`afterUpdate`.
- `UNIQUE(shop_id, external_id, deleted_at)` — без `deleted_at` в индексе MySQL не поддерживает partial unique (см. memory `laravel-pitfalls.md`); при re-create soft-deleted записи получили бы `1062 Duplicate entry`.
- `showcase_category_id ON DELETE SET NULL` — при удалении витринной (через CRUD admin_front) FK сам обнулит привязки. Также Eloquent Observer `Category::deleted` дополнительно ставит `linked_at = NULL` (явная семантика).
- `is_active` приходит из 1С (там есть аналог в учётной системе) — независим от `deleted_at`.

### 3.2. Изменения в `products`

**Важно:** в admin_back таблица `products` **не имеет** колонки `category_id` (как на storefront) — связь Product↔Category реализована **M:N через pivot `category_product`**. Существующий `ProductEventTransformer::toEvent` берёт `categories()->first()?->id` для payload в RabbitMQ.

Чтобы не вторгаться в существующий pivot (там могут быть legacy-привязки 1С-resolved категорий, бренд-категорий и т.п.) — вводим **новое явное single-FK поле `products.showcase_category_id`** для нашей витринной категории. Pivot не трогаем.

```sql
ALTER TABLE products
    ADD COLUMN category_1c_id BIGINT UNSIGNED NULL,
    ADD COLUMN showcase_category_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT fk_products_category_1c
        FOREIGN KEY (category_1c_id) REFERENCES categories_1c(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_products_showcase_category
        FOREIGN KEY (showcase_category_id) REFERENCES categories(id) ON DELETE SET NULL,
    ADD INDEX idx_category_1c (category_1c_id),
    ADD INDEX idx_showcase_category (showcase_category_id);
```

Обоснования:
- `category_1c_id` — FK на 1С-категорию из нового справочника. Заполняется в `ProductEventTransformer::fromEvent` admin_back при приходе product event с `category_1c_external_id`. Никогда не выставляется в pivot.
- `showcase_category_id` — FK на витринную, выставляется **только** `SyncCategoryMappingsJob`'ом (никогда из RabbitMQ event). При publish из admin_back — это поле проксируется как `category_id` в payload.
- Pivot `category_product` остаётся как есть, ни одна миграция не трогает его записи.

На MySQL 8.0 — `INSTANT` ALTER, без блокировки таблицы.

### 3.2.1. Изменение в существующем `ProductEventTransformer::toEvent` admin_back

```php
// Было (lastochka_admin_back/app/Transformers/ProductEventTransformer.php:243):
$categoryId = $product->categories()->first()?->id;

// Становится:
$categoryId = $product->showcase_category_id ?? $product->categories()->first()?->id;
```

Backwards-compatible: для товаров с `showcase_category_id IS NULL` (старые до первого sync) — поведение прежнее (берётся первая из pivot, обычно это 1С-resolved). Для товаров после sync — отправляется витринная (которую установил job).

### 3.3. Таблица `category_1c_sync_batches`

```sql
CREATE TABLE category_1c_sync_batches (
    id                BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    shop_id           BIGINT UNSIGNED NOT NULL,
    status            ENUM('PENDING','IN_PROGRESS','COMPLETED','FAILED') NOT NULL DEFAULT 'PENDING',
    progress_total    INT NOT NULL DEFAULT 0,
    progress_done     INT NOT NULL DEFAULT 0,
    started_at        TIMESTAMP NOT NULL,
    completed_at      TIMESTAMP NULL,
    error             TEXT NULL,
    metadata          JSON NULL,                          -- например, touched_products counter
    created_at        TIMESTAMP NULL,
    updated_at        TIMESTAMP NULL,

    FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
    INDEX idx_shop_status (shop_id, status, created_at)
);
```

### 3.4. Eloquent модели

```php
class Category1c extends Model {
    use SoftDeletes;
    protected $table = 'categories_1c';
    protected $fillable = [
        'shop_id', 'external_id', 'parent_id', 'name', 'position',
        'is_active', 'showcase_category_id', 'linked_at',
        'last_synced_at', 'synced_from_1c_at',
    ];
    protected $casts = [
        'is_active' => 'boolean',
        'linked_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'synced_from_1c_at' => 'datetime',
    ];

    public function shop()              { return $this->belongsTo(Shop::class); }
    public function parent()            { return $this->belongsTo(self::class, 'parent_id'); }
    public function children()          { return $this->hasMany(self::class, 'parent_id'); }
    public function showcaseCategory()  { return $this->belongsTo(Category::class, 'showcase_category_id'); }
    public function products()          { return $this->hasMany(Product::class, 'category_1c_id'); }

    public function getIsLeafAttribute(): bool       { return !$this->children()->exists(); }
    public function getIsLinkedAttribute(): bool     { return $this->showcase_category_id !== null; }
    public function getIsSourceDeletedAttribute(): bool { return $this->trashed(); }
    public function getIsNewAttribute(): bool {
        return $this->created_at?->gt(now()->subDays(30))
            && $this->showcase_category_id === null;
    }
}

// Дополнения в существующую Product
class Product extends Model {
    // существующий $fillable дополняется
    protected $fillable = [
        // ...existing
        'category_1c_id',
        'showcase_category_id',
    ];

    public function category1c()        { return $this->belongsTo(Category1c::class, 'category_1c_id'); }
    public function showcaseCategory()  { return $this->belongsTo(Category::class, 'showcase_category_id'); }
}

// Дополнения в существующую Category (витринная)
class Category extends Model {
    public function category1cMappings() { return $this->hasMany(Category1c::class, 'showcase_category_id'); }
}
```

### 3.5. Observer на витринную Category

```php
class CategoryObserver {
    public function deleted(Category $category): void {
        // FK ON DELETE SET NULL уже обнулит showcase_category_id, но
        // мы дополнительно сбрасываем linked_at, чтобы UI badge "✓ привязана"
        // сошёл с этих 1С-категорий.
        Category1c::where('showcase_category_id', $category->id)
            ->update(['linked_at' => null]);
    }
}
```

Регистрация в `AppServiceProvider::boot()`.

### 3.6. event_sync_metadata

Переиспользуем существующую таблицу с новым `entity_type='category_1c'`, `source='1c'`. Без отдельной таблицы, без миграций — это шаблонный механизм платформы.

### 3.7. UI-бейджи (логика)

| Бейдж | Условие |
|---|---|
| Левая колонка: «новая» | `is_new = (created_at > now()-30d AND showcase_category_id IS NULL)` |
| Левая колонка: «✓ привязана» | `is_linked = (showcase_category_id IS NOT NULL AND linked_at IS NOT NULL)` |
| Левая колонка: «удалена в 1С» | `is_source_deleted = (deleted_at IS NOT NULL)` |
| Правая колонка: «N источников 1С» | `category_1c_mappings_count >= 1` |
| Правая колонка: «требует внимания · Источник 1С удалён» | `has_orphan_1c_sources = EXISTS(SELECT 1 FROM categories_1c WHERE showcase_category_id=? AND deleted_at IS NOT NULL)` |
| Левая колонка: «привязка не применена» | `linked_at IS NOT NULL AND last_synced_at IS NULL` |

---

## 4. RabbitMQ Flow

### 4.1. Publisher 1c_parser

#### 4.1.1. Категории — новый routing key

В `app/RabbitMQ/Transformers/CategoryEventTransformer.php` (1c_parser) меняется `routing_key_prefix`:

```php
// Было: 'routing_key_prefix' => 'category'
// Стало: 'routing_key_prefix' => 'category_1c'
```

В `config/rabbitmq-events.php` обновляется регистрация публикуемых event_types:

```
category.created   →  category_1c.created
category.updated   →  category_1c.updated
category.deleted   →  category_1c.deleted
```

Payload остаётся прежним:

```json
{
  "id": "<GUID из 1С>",
  "source": "1c",
  "shop_code": "lastochka_1",
  "parent_id": "<GUID parent>" | null,
  "name": "Молоко 1.5%",
  "position": 100,
  "active": true,
  "event_timestamp": "2026-05-05T12:00:00Z"
}
```

#### 4.1.2. Товары — изменение payload

В `app/RabbitMQ/Transformers/ProductEventTransformer.php` (1c_parser):

```php
// Было:
'category_id'     => $product->category?->external_id,
'category_source' => $product->category ? '1c' : null,

// Стало:
'category_1c_external_id' => $product->category?->external_id,
// поля category_id и category_source НЕ публикуются
```

#### 4.1.3. Эффект на storefront

Storefront `ProductEventTransformer::linkCategory()` уже умеет работать с отсутствующим `category_id`/`category_source` (в коде: `if (!$categoryId || !$categorySource) return;`). Поэтому никаких code-changes на storefront не требуется.

**Race condition «1С обновил цену → category_id затёрся обратно на 1С-категорию» исключён** — потому что 1c_parser больше не шлёт `category_id` в product events.

### 4.2. Consumer admin_back

#### 4.2.1. Регистрация очереди

В `multi_vendor_delivery_rabbitmq_package/config/rabbitmq-events.php`:

```php
'inbound_queues' => [
    // ...existing
    'categories_1c' => [
        'event_types' => [
            'category_1c.created',
            'category_1c.updated',
            'category_1c.deleted',
        ],
        'transformer' => Category1cEventTransformer::class,
    ],
]
```

И в config admin_back:

```php
'queues_in' => [
    // ...existing
    'categories_1c' => env('RABBITMQ_QUEUE_CATEGORIES_1C', 'admin_categories_1c_in'),
]
```

#### 4.2.2. `Category1cEventTransformer`

```php
namespace App\Transformers;

class Category1cEventTransformer extends BaseEventTransformer {
    protected $modelClass = Category1c::class;
    protected $sourceType = '1c';
    protected $entityType = 'category_1c';

    public function fromEvent(array $data, ?Model $existingEntity, ?Model $relatedModel = null): array {
        $attrs = [];
        if (array_key_exists('shop_code', $data)) {
            $attrs['shop_id'] = $this->resolveShopId($data['shop_code']);
        }
        if (array_key_exists('id', $data)) {
            $attrs['external_id'] = $data['id'];
        }
        if (array_key_exists('name', $data)) {
            $attrs['name'] = $data['name'];
        }
        if (array_key_exists('position', $data)) {
            $attrs['position'] = $data['position'];
        }
        if (array_key_exists('active', $data)) {
            $attrs['is_active'] = $data['active'];
        }
        $attrs['synced_from_1c_at'] = now();

        // showcase_category_id и linked_at — управляются ТОЛЬКО админом из UI,
        // никогда не пишутся из event.
        return $attrs;
    }

    public function afterCreate(Model $entity, array $data): void {
        $this->resolveParent($entity, $data);
    }

    public function afterDelete(Model $entity, array $data): void {
        // soft delete; showcase_category_id остаётся для UI badge "источник 1С удалён"
    }

    public function findExistingEntity(array $data, ?object $relatedModel = null): ?Model {
        return Category1c::withTrashed()
            ->where('shop_id', $this->resolveShopId($data['shop_code']))
            ->where('external_id', $data['id'])
            ->first();
        // НЕТ fallback по name — иначе склеит дубли-тёзки (memory: rabbitmq-pitfalls.md)
    }

    public function afterUpdate(Model $entity, array $data): void {
        // Если запись была soft-deleted (например после category_1c.deleted),
        // и теперь снова приехала из 1С (восстановили) — restore.
        if ($entity->trashed()) {
            $entity->restore();
        }
        $this->resolveParent($entity, $data);
    }

    private function resolveParent(Model $entity, array $data): void {
        if (!array_key_exists('parent_id', $data)) {
            return;
        }
        if ($data['parent_id'] === null) {
            $entity->update(['parent_id' => null]);
            return;
        }
        $parent = Category1c::where('shop_id', $entity->shop_id)
            ->where('external_id', $data['parent_id'])
            ->first();
        if ($parent) {
            $entity->update(['parent_id' => $parent->id]);
        }
        // если parent ещё не приехал — оставляем NULL, при следующем .updated резолвится
    }
}
```

#### 4.2.3. Дополнения в `ProductEventTransformer` admin_back

```php
public function fromEvent(array $data, ?Model $existingEntity, ?Model $relatedModel = null): array {
    $attrs = [/* ...existing logic */];

    if (array_key_exists('category_1c_external_id', $data)) {
        if ($data['category_1c_external_id'] === null) {
            $attrs['category_1c_id'] = null;
        } else {
            $cat1c = Category1c::where('shop_id', $this->resolveShopId($data['shop_code']))
                ->where('external_id', $data['category_1c_external_id'])
                ->first();
            $attrs['category_1c_id'] = $cat1c?->id;
            // если 1С-категория ещё не приехала — null, при следующем product.updated резолвится
        }
    }

    // products.category_id (витринная) — НЕ устанавливается из event,
    // только через SyncCategoryMappingsJob
    return $attrs;
}
```

### 4.3. Outbox publisher admin_back

После `SyncCategoryMappingsJob` массово вызывает `event(new ProductUpdated($p))` для каждого затронутого продукта (см. §6). Стандартный механизм платформы:

1. Listener пишет в `event_outbox`.
2. Worker `php artisan rabbitmq:publish-outbox` (под supervisor) разгребает в RabbitMQ.
3. Routing key `product.updated`, payload — стандартный admin_back ProductEventTransformer::toEvent (включая `category_id` витринной + `category_source='app'`).

### 4.4. Edge cases

| Случай | Поведение |
|---|---|
| `category_1c.created` приходит до родителя | `parent_id=NULL`. Следующий `.updated` ребёнка резолвит. Если родитель так и не пришёл — категория считается root. |
| Дубликат сообщения (re-delivery) | UNIQUE (shop_id, external_id, deleted_at) защищает; `findExistingEntity` находит и UPDATE'ит. |
| `category_1c.deleted` для категории с привязкой | soft delete; `showcase_category_id` сохраняется для UI badge. |
| Product event с `category_1c_external_id`, но 1С-категория ещё не приехала | `category_1c_id=NULL`. При следующем апдейте этого продукта (или после `1c:resync-products`) — заполнится. |
| `event_timestamp` старше `synced_from_1c_at` | Игнорируем (стандартный outdated-protection платформы). |

---

## 5. GraphQL API в admin_back

Новый модуль `graphql/Category1c/*.graphql`, импортируется в `schema.graphql` через `#import`.

### 5.1. Типы

```graphql
type Category1c {
  id: ID!
  shop: Shop! @belongsTo
  shop_id: ID!
  external_id: String!
  parent: Category1c @belongsTo
  parent_id: ID
  children: [Category1c!]! @hasMany
  name: String!
  position: Int!
  is_active: Boolean!
  is_leaf: Boolean!
  showcase_category: Category @belongsTo
  showcase_category_id: ID
  linked_at: DateTime
  last_synced_at: DateTime
  synced_from_1c_at: DateTime
  products_count: Int!
  is_new: Boolean!
  is_linked: Boolean!
  is_source_deleted: Boolean!
  created_at: DateTime!
  updated_at: DateTime!
  deleted_at: DateTime
}

extend type Category {
  category_1c_mappings(shop_id: ID!): [Category1c!]!
    @field(resolver: "Category1cQueries\\GetMappings")
  category_1c_mappings_count(shop_id: ID!): Int!
    @field(resolver: "Category1cQueries\\GetMappingsCount")
  has_orphan_1c_sources(shop_id: ID!): Boolean!
    @field(resolver: "Category1cQueries\\HasOrphanSources")
}

extend type Product {
  category_1c: Category1c @belongsTo
  category_1c_id: ID
}

type Category1cSyncBatch {
  id: ID!
  shop: Shop! @belongsTo
  status: Category1cSyncStatus!
  progress_total: Int!
  progress_done: Int!
  started_at: DateTime!
  completed_at: DateTime
  error: String
}

enum Category1cSyncStatus { PENDING IN_PROGRESS COMPLETED FAILED }
enum Category1cLinkedFilter { LINKED UNLINKED ALL }
```

### 5.2. Queries

```graphql
extend type Query {
  categories_1c(
    shop_id: ID!
    linked_status: Category1cLinkedFilter = ALL
    search: String
    parent_id: ID
    only_leaves: Boolean = false
    include_deleted: Boolean = false
    is_active: Boolean
  ): [Category1c!]! @paginate(defaultCount: 50, maxCount: 200)

  categories_1c_new_count(shop_id: ID!): Int!
    @field(resolver: "Category1cQueries\\NewCount")

  category_1c_sync_batch(shop_id: ID!): Category1cSyncBatch
    @field(resolver: "Category1cQueries\\GetCurrentBatch")
}
```

### 5.3. Mutations

```graphql
extend type Mutation {
  link_categories_1c_to_showcase(
    category_1c_ids: [ID!]!
    showcase_category_id: ID!
  ): [Category1c!]! @field(resolver: "Category1cMutations\\LinkToShowcase")

  unlink_categories_1c(
    category_1c_ids: [ID!]!
  ): [Category1c!]! @field(resolver: "Category1cMutations\\UnlinkFromShowcase")

  sync_categories_1c(shop_id: ID!): Category1cSyncBatch!
    @field(resolver: "Category1cMutations\\StartSync")
}
```

### 5.4. Поведение mutations

#### `link_categories_1c_to_showcase`

Транзакция; для каждого `category_1c_id`:

```php
$category1c->update([
    'showcase_category_id' => $showcaseId,
    'linked_at' => now(),
]);
```

Валидации (throw `ValidationException` с кодом):
- `SHOP_MISMATCH` — 1С-категория и витринная разных магазинов (через `shops_categories` pivot).
- `SHOWCASE_NOT_LEAF` — у витринной есть children (по ТЗ привязка только к листу).
- `CATEGORY_1C_NOT_LEAF` — у 1С-категории есть children (то же).
- `CATEGORY_1C_DELETED` — `deleted_at IS NOT NULL`.

Returns: обновлённые `Category1c[]` (фронт оптимистично обновляет обе колонки).

#### `unlink_categories_1c`

```php
$category1c->update([
    'showcase_category_id' => null,
    'linked_at' => null,
]);
// last_synced_at — оставляем как есть (история прошлой синхры)
// products.category_id — НЕ меняется (товары застывают в последней витринной)
```

#### `sync_categories_1c`

```php
$existing = Category1cSyncBatch::where('shop_id', $shopId)
    ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
    ->first();
if ($existing) return $existing;  // idempotent на повторный клик

$batch = Category1cSyncBatch::create([
    'shop_id' => $shopId,
    'status' => 'PENDING',
    'started_at' => now(),
]);
SyncCategoryMappingsJob::dispatch($batch->id, $shopId);
return $batch;
```

### 5.5. Авторизация

Через существующие platform-policy на `Category` (одна и та же роль admin). `@can(ability: "manage", model: "Category1c")` или `@guard`.

### 5.6. Lighthouse cache

После каждого деплоя admin_back **обязательно**:

```bash
sudo -u www-data php artisan lighthouse:clear-cache
```

(см. memory `laravel-pitfalls.md`: новые types/queries невидимы без этого).

---

## 6. Admin Frontend UI

### 6.1. Module toggle (полный чеклист)

```js
// 1. src/stores/modules.js
export const MODULE_DEFAULTS = {
  // ...existing
  category_1c_integration: false,
}

// 2. SQL миграция в admin_back
INSERT INTO menu_items (key, title, route, icon, position, is_visible)
VALUES ('category_1c', 'Категории 1С', '/categories-1c', 'icon-1c', 75, 0);

// 3. src/components/AsideComponent.vue — getModuleConfig()
const moduleConfig = {
  category_1c: { moduleKey: 'category_1c_integration', requiresAdmin: true },
}

// 4. src/router/index.js
{
  path: '/categories-1c',
  name: 'categories-1c',
  component: () => import('@/views/category-1c-management/index.vue'),
  meta: {
    authRequired: true, header: true, aside: true,
    moduleKey: 'category_1c_integration',
  },
  beforeEnter: (to, from, next) => {
    moduleStore.isModuleEnabled('category_1c_integration') ? next() : next('/');
  }
}
```

### 6.2. Структура views

```
src/views/category-1c-management/
  index.vue                     # screen-level: header + 2 колонки + footer
  components/
    OneCColumn.vue              # левая
    OneCCategoryRow.vue
    ShowcaseColumn.vue          # правая
    ShowcaseCategoryRow.vue
    SyncBatchButton.vue
    NewOneCBanner.vue
    BulkLinkBar.vue
  graphql/
    categories1c.graphql
    categoriesShowcase.graphql
    linkCategories1c.graphql
    unlinkCategories1c.graphql
    syncCategories1c.graphql
    syncBatch.graphql
```

### 6.3. State (Composition API)

`index.vue`:

```vue
<script setup>
import { ref } from 'vue'
import { useShopStore } from '@/stores/shop'

const shopStore = useShopStore()
const selectedShopId = ref(shopStore.currentShopId)

// Левая колонка
const linkedFilter = ref('UNLINKED')   // UNLINKED | LINKED | ALL
const searchOneC = ref('')
const selectedOneCIds = ref([])
const showOnlyNew = ref(false)

// Правая колонка
const searchShowcase = ref('')
const draggingOver = ref(null)

// Sync state
const syncBatch = ref(null)
</script>
```

### 6.4. Drag-and-drop

Используется **vuedraggable** (sortablejs обёртка, проектный стандарт).

- Левая: `draggable group="oneC"` с `:disabled="!row.is_leaf || row.is_source_deleted"`.
- Правая: каждая строка — `<droppable accept="oneC">`. На drop:
  ```js
  await mutate('linkCategories1c', {
    category_1c_ids: selectedOneCIds.value.length
      ? selectedOneCIds.value
      : [draggedRow.id],
    showcase_category_id: dropTarget.id,
  })
  ```
- Multi-select: чекбоксы слева, плашка `BulkLinkBar` снизу с кнопкой «Привязать к витрине».
- Запрет drop на не-листья: `:droppable="false"` для узлов с children, на попытку — toast «Нельзя привязать 1С-категорию к разделу».

### 6.5. SyncBatchButton

```vue
<script setup>
const { result: batch } = useQuery(SYNC_BATCH_QUERY, () => ({ shop_id: selectedShopId.value }), {
  pollInterval: computed(() => batch.value?.status === 'IN_PROGRESS' ? 2000 : 0),
})

const onClick = async () => {
  await mutate('syncCategories1c', { shop_id: selectedShopId.value })
  // refetch SYNC_BATCH_QUERY → обновит batch.value
}
</script>

<template>
  <button :disabled="batch?.status === 'IN_PROGRESS'" @click="onClick">
    Синхронизировать
  </button>
  <progress v-if="batch?.status === 'IN_PROGRESS'"
            :value="batch.progress_done" :max="batch.progress_total" />
  <toast v-if="batch?.status === 'COMPLETED'">
    Синхронизация завершена · обновлено {{ batch.metadata.touched_products }} товаров
  </toast>
</template>
```

### 6.6. Шапка / селектор магазина

- `<ShopSelectorComponent>` (переиспользование), при смене — обе колонки рефетчатся, multi-select сбрасывается.
- `<NewOneCBanner :count="newCount">` — клик переключает `linkedFilter` на UNLINKED + `showOnlyNew=true`.
- `<SyncBatchButton>`.

### 6.7. CRUD витринных в правой колонке

- «+ Создать», edit (через `...` контекстное меню), delete — переиспользуем существующие модалки/формы из старого экрана «Категории товаров». Если они не вынесены в shared-компоненты — выносим в рамках этой задачи (точечный рефактор).
- При delete витринной → backend FK + Observer обнулит привязки → фронт получит обновлённые `category_1c_mappings` через Apollo cache invalidation.

### 6.8. Системный паттерн валидации форм

Форма «создать витринную» в правой колонке использует существующий паттерн `{value, errors}` + `parseGqlErrors` / `resetErrors` из `@/utils.js` (см. memory правил по admin_front). Если переиспользуется существующая модалка — она уже на этом паттерне.

---

## 7. SyncCategoryMappingsJob

### 7.1. Регистрация

```php
namespace App\Jobs\Category1c;

class SyncCategoryMappingsJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;     // 30 минут на крупные магазины
    public int $tries = 1;          // не ретраим автоматически — пользователь сам перезапустит
    public int $backoff = 60;

    public function __construct(
        public int $batchId,
        public int $shopId,
    ) {}
}
```

Очередь: дефолтная `default`, под supervisor `queue:work`.

### 7.2. Алгоритм

```php
public function handle(): void {
    $batch = Category1cSyncBatch::findOrFail($this->batchId);
    $lock = Cache::lock("category_1c_sync:{$this->shopId}", 1800);

    if (!$lock->get()) {
        $batch->update(['status' => 'FAILED', 'error' => 'Another sync is in progress']);
        return;
    }

    try {
        $batch->update(['status' => 'IN_PROGRESS']);

        $total = Product::query()
            ->where('shop_id', $this->shopId)
            ->whereHas('category1c', fn ($q) =>
                $q->where('shop_id', $this->shopId)
                  ->whereNotNull('showcase_category_id'))
            ->count();
        $batch->update(['progress_total' => $total]);

        $touchedCount = 0;
        Product::query()
            ->where('shop_id', $this->shopId)
            ->whereHas('category1c', fn ($q) =>
                $q->where('shop_id', $this->shopId)
                  ->whereNotNull('showcase_category_id'))
            ->with('category1c:id,showcase_category_id')
            ->lazyById(500)
            ->each(function (Product $product) use ($batch, &$touchedCount) {
                $newShowcaseId = $product->category1c->showcase_category_id;
                if ($product->showcase_category_id !== $newShowcaseId) {
                    $product->update(['showcase_category_id' => $newShowcaseId]);
                    event(new ProductUpdated($product)); // outbox требует явного event(...)
                    $touchedCount++;
                }
                $batch->increment('progress_done');
            });

        Category1c::where('shop_id', $this->shopId)
            ->whereNotNull('showcase_category_id')
            ->update(['last_synced_at' => now()]);

        $batch->update([
            'status' => 'COMPLETED',
            'completed_at' => now(),
            'metadata' => ['touched_products' => $touchedCount],
        ]);
    } catch (\Throwable $e) {
        $batch->update([
            'status' => 'FAILED',
            'error' => $e->getMessage(),
            'completed_at' => now(),
        ]);
        throw $e;
    } finally {
        $lock->release();
    }
}

public function failed(\Throwable $e): void {
    Category1cSyncBatch::where('id', $this->batchId)->update([
        'status' => 'FAILED',
        'error' => $e->getMessage(),
        'completed_at' => now(),
    ]);
}
```

### 7.3. Идемпотентность

- Повторный запуск без изменений в привязках → `progress_done` инкрементируется, но `event(new ProductUpdated)` НЕ вызывается (потому что `category_id` уже совпадает с `showcase_category_id`). Outbox не флудится.
- На mutation level: `sync_categories_1c` возвращает существующий IN_PROGRESS batch без создания нового.
- Lock per-shop: один job на магазин одновременно.

### 7.4. Edge cases

| Случай | Поведение |
|---|---|
| 1С-категория удалена в 1С (`deleted_at IS NOT NULL`), но `showcase_category_id` стоит | `whereHas` фильтрует через soft delete scope → товары этой 1С НЕ обновляются. |
| Витринная удалена админом во время выполнения | FK + Observer уже обнулил `showcase_category_id`. Job увидит NULL → пропустит. |
| Параллельный 1c_parser product.updated | Inbound consumer admin_back пишет с `withoutEvents()` → outbox не триггерит. Не конфликтует. Кроме того, в новой модели payload не содержит `category_id` → consumer не трогает `showcase_category_id` (он его и так никогда не пишет — это поле для job'а). |
| Job упал на полпути | Status `FAILED`, частичный progress. Повторный запуск идемпотентен — докатит остальное. |
| 100k+ товаров | `lazyById(500)` обеспечивает bounded memory. Outbox publisher разгребёт за минуты после job. На storefront лаг — норма. |

### 7.5. Outbox propagation

После `event(new ProductUpdated($p))`:
1. Listener пишет в `event_outbox`.
2. `php artisan rabbitmq:publish-outbox` (supervisor) → RabbitMQ.
3. Routing key `product.updated`, payload включает `category_id` (внутренний admin_back ID витринной) + `category_source='app'`.
4. На storefront `ProductEventTransformer::linkCategory()` резолвит через `event_sync_metadata (source='app', external_id=<admin_back ID>)` → линкует к локальной витринной Category. Готово.

---

## 8. Rollout

### 8.1. Миграции

**admin_back** (`lastochka_docker/lastochka_admin_back/database/migrations/`):

```
2026_05_06_000001_create_categories_1c_table.php
2026_05_06_000002_add_category_1c_id_to_products_table.php
2026_05_06_000003_create_category_1c_sync_batches_table.php
2026_05_06_000004_add_category_1c_menu_item.php   -- INSERT в menu_items, idempotent
```

Все через `DB::table()` без Eloquent (memory: `migration-pitfalls.md`).

**1c_parser, multi_vendor_delivery_rabbitmq_package, storefront_back, admin_front:** миграций БД нет — только code/config changes.

### 8.2. Последовательность деплоя

В одной feature-ветке, по одному PR на репо. Pre-deploy bekup БД (см. §8.4).

1. **multi_vendor_delivery_rabbitmq_package** → bump version, push.
2. **1c_parser** → composer.json bump, deploy.
3. **admin_back** → миграции + код, lighthouse:clear-cache, supervisorctl restart all.
4. **admin_front** → новый экран, scoped under module flag.

**Промежуточные состояния безопасны:**
- Между 1-2: ничего не меняется (publisher ещё не задеплоен).
- Между 2-3: 1c_parser шлёт `category_1c.*` в очередь — admin_back ещё не подписан, сообщения копятся в durable queue, разгребутся за <1 мин после шага 3. Storefront перестаёт получать `category_id` в product events → conditional check пропускает блок, `products.category_id` не трогается.
- Между 3-4: admin_back обрабатывает 1С-категории, GraphQL queries доступны, но UI ещё нет — никто не видит.

**Никаких downtime окон не требуется.**

### 8.3. Включение на Lastochka (после полного rollout)

```sql
-- 1. projectModules toggle
UPDATE admin_settings
SET value = JSON_SET(value, '$.category_1c_integration', true)
WHERE key = 'project_modules';

-- 2. Видимость пункта меню
UPDATE menu_items SET is_visible = 1 WHERE key = 'category_1c';
```

Verification:
- Открыть `/categories-1c` в admin_front — экран загружается, левая колонка заполнена 1С-категориями.
- `tail` логов supervisor `cron_admin_consumer.log` — события `category_1c.*` обрабатываются.
- Smoke первой привязки + sync на тестовом магазине (shop id=1, см. memory `shops-mapping.md`).

### 8.4. Pre-deploy backup

Перед шагом 3 (admin_back с миграциями):

```bash
mkdir -p /home/admin/backups/category-1c-pre-deploy-$(date +%Y%m%d-%H%M%S)
mysqldump --single-transaction --quick --no-tablespaces \
  cron_admin products menu_items admin_settings \
  | gzip > /home/admin/backups/category-1c-pre-deploy-$(date +%Y%m%d-%H%M%S)/dump.sql.gz
```

(хотя миграция не делает mass UPDATE — только CREATE/ALTER, по правилу безопасности дамп всё равно делаем.)

### 8.5. Sync-core distribution на форки

После приёмки заказчиком на Lastochka — broadcast через sync-core на остальные форки:

| Форк | Действие | `projectModules.category_1c_integration` |
|---|---|---|
| Lastochka | Origin фичи | `true` |
| Aladdin | broadcast код, миграции применяются | `false` (dormant) |
| Electrohub | broadcast код, миграции применяются | `false` |
| Foodlink | broadcast admin_back/1c_parser/admin_front (storefront у Foodlink — Bagisto, наша фича storefront-агностична) | `false` |

**Sync-core verify** (см. memory правил): после broadcast обязательно `git log --grep='ALADDIN-9999\|JAMI-10114'` на всех форках и явный отчёт «4/4 есть» — sync-core может молча пропустить форк (прецедент ALADDIN-9822).

### 8.6. CI verify после автодеплоя

```bash
python3 ~/.claude/gitlab_cli.py last_pipeline lastochka/lastochka_admin_back --ref <branch>
# при failed → job_log упавшей джобы
```

После CI green:

```bash
ssh admin@155.212.175.116 "supervisorctl status | grep rabbitmq"
ssh admin@155.212.175.116 "cd /home/admin/apps/cron_admin_back \
  && sudo -u www-data php artisan lighthouse:clear-cache"
```

### 8.7. Rollback

- **Quick disable**: `UPDATE admin_settings SET value=JSON_SET(value, '$.category_1c_integration', false)` + `UPDATE menu_items SET is_visible=0 WHERE key='category_1c'`. Экран исчезает, остальное dormant.
- **Code revert**: revert PR на 4 репо. Миграции — оставить (новые таблицы пустые), FK на products — пустое поле. Storefront ничего не заметил.

---

## 9. Тесты

### 9.1. Unit (`tests/Unit/`)

| Тест | Покрытие |
|---|---|
| `Category1cTest` | Accessors `is_leaf`/`is_linked`/`is_source_deleted`/`is_new`. Soft delete. Relations. |
| `Category1cEventTransformerTest` | `fromEvent` mapping; `afterCreate` parent resolution; `findExistingEntity` (no name fallback); `afterDelete` soft delete. |
| `ProductEventTransformerTest` | При наличии `category_1c_external_id` — резолв в `products.category_1c_id`. При отсутствии — поле не трогается. `products.category_id` (витринная) никогда не из event. |
| `SyncCategoryMappingsJobTest` | Идемпотентность; lock per-shop (вторая job FAILED); partial failure; `last_synced_at` обновляется только на 1С с привязкой. |

### 9.2. Feature (`tests/Feature/`)

| Тест | Покрытие |
|---|---|
| `GetCategoriesOneCQueryTest` | `linked_status` UNLINKED/LINKED/ALL, `search`, `parent_id`, `only_leaves`, пагинация, per-shop scope. |
| `LinkCategories1cMutationTest` | Успех (single + multi). Валидации `SHOWCASE_NOT_LEAF`, `CATEGORY_1C_NOT_LEAF`, `CATEGORY_1C_DELETED`, `SHOP_MISMATCH`. |
| `UnlinkCategories1cMutationTest` | Отвязка, products НЕ меняются. |
| `SyncCategoriesOneCMutationTest` | Создание batch; повторный клик с активным IN_PROGRESS — возвращает существующий. |
| `Category1cInboundEventTest` | Mock RabbitMQ → `category_1c.created/updated/deleted` → проверка БД и `event_sync_metadata`. |
| `Category1cParentResolutionTest` | Out-of-order events; родитель не найден → `parent_id=NULL`; поздняя резолюция при `.updated`. |

### 9.3. Integration

`Category1cFullSyncFlowTest`:

```php
test('full sync action propagates products to showcase categories via outbox', function () {
    // setup shop, 1С-категория с привязкой, 3 товара
    // act: dispatch SyncCategoryMappingsJob
    // assert: products.category_id обновлены, event_outbox содержит 3 product.updated,
    //         payload включает category_id (витринная) + category_source='app',
    //         batch COMPLETED, last_synced_at != null
});

test('sync is idempotent — second run does not flood outbox', function () {
    // первый запуск
    // второй запуск без изменений
    // assert: outbox не получил дубликатов
});
```

### 9.4. 1c_parser tests

| Тест | Покрытие |
|---|---|
| `CategoryEventTransformerTest` | `toEvent` payload (id, parent_id, name, position, active, shop_code, source='1c'); routing key `category_1c.{action}`. |
| `ProductEventTransformerTest` | Payload **не содержит** `category_id`/`category_source`. Содержит `category_1c_external_id` если есть category, иначе null. |

### 9.5. admin_front tests (vitest)

| Тест | Покрытие |
|---|---|
| `OneCColumn.spec.js` | Фильтры linked_status, multi-select sync с BulkLinkBar, поиск дебаунсится. |
| `ShowcaseColumn.spec.js` | Drop-target подсвечивается, drop вызывает mutation, не-листья отказывают. |
| `SyncBatchButton.spec.js` | Polling on/off, прогресс рендерится. |
| `Category1cMappingsBadge.spec.js` | Бейджи рендерятся согласно состоянию. |

### 9.6. Локальные ограничения

admin_back tests — PHP 8.4 + SQLite in-memory. Pre-existing MySQL-only миграции (например `add_shop_to_integration_configs`) ломают часть тестов фоном — это НЕ регрессия фичи (см. memory `1c-integration.md`).

Стратегия:
- Pest без `RefreshDatabase` через `Mockery::alias()` где можно.
- При необходимости БД — SQLite + `--filter=Category1c` локально.
- На CI — full suite, фоновые failures игнорируются (если они были до фичи).

### 9.7. Manual verification после деплоя

1. **Smoke**: `categories_1c(shop_id: 1)` через GraphQL admin — возвращает данные.
2. **End-to-end**:
   - В админке курьерки `/categories-1c` → выбрать тестовый магазин.
   - Перетащить 1С-категорию на витринную → бейдж «✓ привязана».
   - «Синхронизировать» → `progress_done == progress_total` → COMPLETED.
   - В мобилке (claude-in-chrome на `store.lastochka.comrades.dev`) — товары в новой витринной.
   - SSH `tail` логов admin_back consumer — `product.updated` опубликованы.
3. **Cascade в 1С**: удалить 1С-категорию в 1С, прогнать обмен, проверить badge «требует внимания» в курьерке.

---

## 10. Open Questions / Future Work

1. **Имя пункта меню в admin_front** — «Категории 1С» / «Маппинг 1С» / «Связи с 1С». Решим по факту локализации.
2. **Очистка `source='1c'` Categories** на storefront — отдельная задача, не входит в эту фичу. Когда полный переход на admin_back-managed витринные завершится — отдельная команда `php artisan categories:cleanup-source-1c` чистит осиротевших.
3. **Re-sync ТОЛЬКО изменившихся привязок** — текущая реализация sync проходит по всем 1С-категориям магазина. Для масштаба 50k+ товаров — приемлемо. Если станет долго — добавить в `category_1c_sync_batches` поле `target_category_1c_ids JSON` и узкий sync.
4. **Аудит-лог изменений привязок** — пока не делаем. Если понадобится для compliance — добавить таблицу `category_1c_audit_log`.
5. **Иерархия 1С-категорий в UI** — мокап показывает плоский список слева, но в БД мы храним дерево. Если нужно дерево в UI — задействовать `parent_id` фильтр в query.
6. ~~Verify admin_back ProductEventTransformer conditional~~ — **проверено в коде**: `ProductEventTransformer::fromEvent()` admin_back использует `if (!empty($eventData['category_id']) && !empty($eventData['category_source']))` — это implicit conditional на отсутствие/null значения. Между шагами 2-3 деплоя ничего не ломается, pivot `category_product` не очищается, существующие связи остаются.

---

## 11. References

### Memory entries

- `memory/lastochki-jami-10114-scope.md` — все scope-уточнения по этой задаче.
- `memory/admin-settings.md` — паттерн `projectModules` toggle, `menu_items` БД, `MODULE_DEFAULTS`.
- `memory/rabbitmq-pitfalls.md` — `findExistingEntity` без fallback по name, `withoutEvents` paradigm, `array_key_exists` conditional set, observer на updated.
- `memory/laravel-pitfalls.md` — Lighthouse cache, MySQL UNIQUE+soft-deletes, `lighthouse:clear-cache`.
- `memory/migration-pitfalls.md` — `DB::table()` в миграциях admin_back (не Eloquent).
- `memory/sync-core-quirks.md`, `memory/sync-core-feature-flag-pattern.md` — broadcast форков.
- `memory/storefront-models.md` — Product → Category BelongsTo (одна), не M:N.
- `memory/shops-mapping.md` — тестовые shop id'ы.
- `memory/1c-integration.md` — UI диагностики, локальные тесты.

### Существующий код

- `lastochka_storefront_back/app/RabbitMQ/Transformers/CategoryEventTransformer.php` — образец для нового `Category1cEventTransformer`.
- `lastochka_storefront_back/app/RabbitMQ/Transformers/ProductEventTransformer.php:485-535` — conditional `linkCategory()`.
- `lastochka_admin_back/app/Models/Category.php` — расширение existing.
- `lastochka_admin_back/app/Models/ShopCategory.php`, `app/Services/ShopService.php` — pivot `shops_categories`.
- `lastochka_1c_parser/app/RabbitMQ/Transformers/CategoryEventTransformer.php`, `ProductEventTransformer.php` — изменения publisher.

### Конфиг

- `multi_vendor_delivery_rabbitmq_package/config/rabbitmq-events.php` — регистрация event_types.
- `lastochka_docker/lastochka_admin_back/config/rabbitmq-events.php`, `lastochka_storefront_back/config/rabbitmq-events.php`, `lastochka_1c_parser/config/rabbitmq-events.php`.
