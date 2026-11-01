# ТЗ: Категории и Товары

## 1. Цель

Каталог товаров с иерархией категорий, фильтрами и карточкой товара.

## 2. Контекст

- **Модуль:** Catalog
- **Триггер:** Клиент просматривает каталог магазина
- **Источник данных:** Админ-панель (через RabbitMQ), 1С
- **Связи:** Категории привязаны к магазинам через pivot `category_store`, товары — к категориям через pivot `category_product`, к магазину — колонкой `products.store_id`

## 3. Данные

### 3.1 Модель Category

```
categories
├── id: bigint PK
├── parent_id: bigint FK → categories.id NULL      // ON DELETE SET NULL
├── source: string(50) NULL                        // Источник синхронизации
├── name: string(200) NOT NULL
├── slug: string(200) NULL
├── image_path: string(500) NULL
├── background_image_path: string NULL             // Фоновое изображение (новое)
├── position: int DEFAULT 0
├── is_active: boolean DEFAULT true
├── is_perishable: boolean DEFAULT false
├── show_on_main: boolean DEFAULT false            // Показывать на главной (новое)
├── tag_text: string(100) NULL                     // Текст тега категории (новое)
├── tag_color: string(7) NULL                      // HEX-цвет тега (новое)
├── custom_checkboxes: json NULL                   // map field => bool (новое)
├── meta_title: string(200) NULL
├── meta_description: text NULL
├── meta_keywords: text NULL
├── created_at: timestamp
├── updated_at: timestamp
└── deleted_at: timestamp NULL
```

Колонка `store_id` из таблицы удалена: привязка категории к магазинам вынесена в pivot `category_store`.

**Индексы:** `parent_id`, `source`.

### 3.2 Таблица category_store

```
category_store
├── id: bigint PK
├── category_id: bigint FK → categories.id
├── store_id: bigint FK → stores.id
├── position: int DEFAULT 0                        // Порядок категории в этом магазине
├── is_active: boolean DEFAULT true                // Активность категории в этом магазине
├── created_at, updated_at
├── UNIQUE (category_id, store_id)
└── INDEX (store_id, is_active, position)
```

Сортировка категорий в ответах API идёт по `category_store.position`, затем по `categories.name`. Поле `position` в JSON-ответе — это собственная колонка `categories.position`, а не позиция в магазине.

### 3.3 Модель Product

```
products
├── id: bigint PK
├── parent_id: bigint FK → products.id NULL        // Вариация вариативного товара
├── store_id: bigint FK → stores.id
├── source: string(50) NULL
├── brand_id: bigint FK → brands.id NULL
├── video_path: string(500) NULL
├── video_source_url: string(2048) NULL
├── sku: string(100) NOT NULL
├── code: string(100) NULL                         // Код товара из 1С (новое)
├── barcodes: json NULL                            // Список штрихкодов
├── title: string(300) NULL
├── description: text NULL
├── meta_title: string(300) NULL
├── meta_description: text NULL
├── meta_keywords: text NULL
├── price: decimal(12,2) NULL                      // Цена с копейками (новое)
├── price_old: decimal(12,2) NULL                  // Старая цена (зачёркнутая)
├── currency: string(3) DEFAULT 'RUB'
├── quantity: int DEFAULT 0                        // Остаток в базовых единицах
├── unit_type: enum('piece','kg') DEFAULT 'piece'
├── quantity_step: unsigned int DEFAULT 1          // Квант покупки
├── measurement_unit: string(20) DEFAULT 'piece'
├── stock_mode: string(16) DEFAULT 'normal'        // normal | ignore | fixed (новое)
├── fixed_stock: int NULL                          // Остаток для stock_mode=fixed (новое)
├── ready_fixed_time: time NULL                    // Время готовности (модуль product_ready_time)
├── composition: text NULL
├── additional_info: text NULL                     // Доп. информация (новое)
├── proteins: decimal(6,2) NULL
├── fats: decimal(6,2) NULL
├── carbohydrates: decimal(6,2) NULL
├── calories: decimal(8,2) NULL
├── weight: int NULL                               // Вес единицы товара в граммах
├── vat_rate: int NULL
├── is_active: boolean DEFAULT true
├── is_configurable: boolean DEFAULT false
├── is_default_variant: boolean DEFAULT false
├── is_culinary: boolean DEFAULT false
├── sugar_free: boolean DEFAULT false
├── on_search_page: boolean DEFAULT false
├── served_warm: boolean DEFAULT false
├── pickup_only: boolean DEFAULT false             // Только самовывоз (новое)
├── custom_checkboxes: json NULL                   // map field => bool (новое)
├── rating: decimal(2,1) NULL
├── reviews_count: unsigned int DEFAULT 0
├── created_at: timestamp
├── updated_at: timestamp
└── deleted_at: timestamp NULL
```

Колонки `category_id`, `brand`, `manufacturer`, `country` из таблицы удалены. Категории товара — pivot `category_product`, бренд — `brand_id` → таблица `brands`.

Колонки стоп-листов `stop_list_hidden` и `stop_list_pickup_only` удалены миграцией `2026_07_16_100000`: их значения слиты в поля карточки — `stop_list_hidden = true` перенесено в `is_active = false`, `stop_list_pickup_only = true` — в `pickup_only = true`. Стоп-лист «Не отображать» скрывает товар из всех выдач через `is_active`, стоп-лист «Не доставлять» ограничивает способы доставки через `pickup_only`. Товары хранятся отдельной записью на магазин, поэтому оба признака действуют per-shop.

**Единицы остатка:** для весовых товаров (`unit_type = kg`) `quantity` и `quantity_step` хранятся в граммах — входящий остаток в кг умножается на 1000 при приёме события. Для штучных — в штуках.

**Режимы остатка (`stock_mode`):**
- `normal` — остаток учитывается, товар доступен при `quantity >= quantity_step`;
- `ignore` — остаток не учитывается, товар доступен независимо от `quantity`; входящие складские события из 1С игнорируются;
- `fixed` — остаток восстанавливается до `fixed_stock` командой `ResetFixedStockCommand`; входящие складские события из 1С игнорируются; для видимости в каталоге ведёт себя как `normal`.

Тот же эффект даёт настройка магазина `stores.ignore_stock` — она включает режим «не учитывать остатки» для всех товаров магазина.

**Индексы:** `UNIQUE (store_id, sku)`, `(store_id, is_active)` (`products_store_id_is_active_index_v2`), `(store_id, source)`, `(sku, store_id)`, `code`, `parent_id`, `is_configurable`, `(parent_id, is_default_variant)`.

### 3.4 Таблица category_product

```
category_product
├── product_id: bigint FK → products.id
├── category_id: bigint FK → categories.id
├── position: unsigned int DEFAULT 0
├── PRIMARY KEY (product_id, category_id)
└── INDEX (category_id)
```

Товар связан с категориями связью many-to-many. Категории товара отдаются в порядке `category_product.position`; первая из них считается основной (`primary_category`).

### 3.5 Модель ProductImage

```
product_images
├── id: bigint PK
├── product_id: bigint FK → products.id
├── path: string(500) NOT NULL
├── source_url: string(2048) NULL
├── type: string(20) DEFAULT 'image'               // image | video
├── mime_type: string(100) NULL
├── position: int DEFAULT 0
└── created_at: timestamp
```

### 3.6 Модель ProductAttribute

```
product_attributes
├── id: bigint PK
├── product_id: bigint FK → products.id
├── code: string(100) NOT NULL                     // Технический код
├── name: string(200) NOT NULL                     // Отображаемое название
├── value: text NOT NULL
├── attribute_value_id: unsigned bigint NULL       // Значение из справочника (новое)
├── attribute_value_code: string(200) NULL         // Строковый код значения (новое)
├── position: int DEFAULT 0
├── UNIQUE (product_id, code)
└── INDEX (code, attribute_value_code)
```

Атрибут считается select-атрибутом (пригодным для фильтра-чекбокса), если `attribute_value_id IS NOT NULL`. Текстовые/числовые атрибуты справочника значений не имеют и в фильтры не попадают.

### 3.7 Модель ConfigurableAttribute

```
configurable_attributes
├── id: bigint PK
├── product_id: bigint FK → products.id            // Вариативный родитель
├── attribute_code: string(100) NOT NULL
├── attribute_name: string(200) NOT NULL
├── position: int DEFAULT 0
├── timestamps
└── UNIQUE (product_id, attribute_code)
```

### 3.8 Модель ProductDocument

```
product_documents
├── id: bigint PK
├── product_id: bigint FK → products.id
├── title: string(255) NOT NULL
├── file_path: string(500) NOT NULL
├── source_url: string(2048) NULL
├── original_name: string(255) NULL
├── mime_type: string(100) NULL
├── size: string(50) NULL
├── group: string(255) NULL
├── position: int DEFAULT 0
├── timestamps
└── deleted_at: timestamp NULL
```

### 3.9 Модель Brand (новое)

```
brands
├── id: bigint PK
├── source: string NULL
├── external_id: string NULL
├── title: string NOT NULL
├── link: string NULL                               // Ссылка на сайт бренда
├── country_origin: string NULL                     // Страна происхождения
├── country_production: string NULL                 // Страна производства
├── certificate_path: string NULL                   // Файл сертификата дилера
├── certificate_name: string NULL
├── logo_path: string NULL
├── position: int
├── is_active: boolean
├── custom_checkboxes: json NULL
├── timestamps
└── deleted_at: timestamp NULL
```

## 4. Логика

Все маршруты каталога публичные: middleware аутентификации (`auth:sanctum`, `OptionalAuth`) и throttle на них не подключены. Bearer-токен на этих маршрутах не разбирается, поэтому `isFavorite` в ответах равен `false`; состав избранного отдаёт `/v1/favorites`.

Параметры `{store}`, `{category}`, `{product}` разрешаются неявной привязкой модели по первичному ключу. Несуществующая или soft-deleted запись даёт 404. Принадлежность категории магазину и товара магазину при привязке не проверяется.

### 4.0 Базовые условия выборки

**Видимая категория магазина** — `categories.is_active = true` + запись в `category_store` для этого магазина с `is_active = true`.

**Покупаемый товар** (scope `purchasable`) — товар, который попадает в листинги и открывается в карточке без 404:
- `is_active = true`;
- `price > 0`;
- `parent_id IS NULL` (вариации в листингах не участвуют);
- при выключенном модуле `configurable_products` — дополнительно `is_configurable = false`;
- при выключенном модуле `preorders` — дополнительно одно из: `quantity >= quantity_step`, `is_configurable = true`, `stock_mode = 'ignore'`, `stores.ignore_stock = true` у магазина товара.

**Поддерево категории** — сама категория плюс вложенные подкатегории на любую глубину. Товар, привязанный к нескольким категориям поддерева, в выдаче не дублируется.

### 4.1 Корневые категории магазина

**Endpoint:** `GET /api/v1/stores/{store}/categories`

**Query параметры:**
- `page` — int, default 1
- `perPage` — int, default 20, максимум 100
- `includeChildren` — bool, default false

**Логика:**
1. Отбираются видимые категории магазина с `parent_id IS NULL`.
2. Сортировка — `category_store.position`, затем `categories.name`.
3. Для каждой категории считаются `children_count` (только видимые в этом магазине дети) и `products_count` (только покупаемые товары этого магазина).
4. При `includeChildren=true` дополнительно загружаются три уровня потомков; на каждом уровне применяются те же условия видимости, сортировка `categories.position` + `categories.name` и те же счётчики.
5. При `includeChildren=true` для leaf-категорий дерева (категорий с пустым `children`) подгружается до 8 покупаемых товаров магазина, отсортированных по `products.title`, затем `products.id`. Лимит считается оконной функцией на стороне SQL.

**Ответ 200:**
```json
{
  "hasMore": false,
  "data": [
    {
      "id": 1,
      "name": "Молочные продукты",
      "slug": "dairy",
      "position": 0,
      "image": {"id": 1, "path": "https://..."},
      "backgroundImage": null,
      "isLeaf": false,
      "tagText": null,
      "tagColor": null,
      "children": [
        {"id": 2, "name": "Молоко", "slug": "milk", "isLeaf": true, "children": null, "products": []}
      ],
      "customCheckboxes": {}
    }
  ],
  "total": 5
}
```

### 4.2 Категории на главной (новое)

**Endpoint:** `GET /api/v1/stores/{store}/categories/home`

**Query параметры:** нет.

**Логика:** отбираются видимые категории магазина с `show_on_main = true` (любого уровня вложенности, не только корневые), сортировка — `category_store.position`, затем `categories.name`. Считаются `children_count` и `products_count`. Пагинации нет — отдаётся весь список.

**Ответ 200:** `{"data": [ /* CategoryResource[] */ ]}`

Связь `children` не загружается, поэтому в элементах `children` равно `null`, а `products` отсутствует.

### 4.3 Дочерние категории

**Endpoint:** `GET /api/v1/stores/{store}/categories/{category}/children`

**Query параметры:** `page` (default 1), `perPage` (default 20, максимум 100).

**Логика:** отбираются видимые категории магазина с `parent_id = {category}`, сортировка — `category_store.position`, затем `categories.name`; считаются `children_count` и `products_count`.

**Ответ 200:** `{"hasMore": bool, "data": CategoryResource[], "total": int}`. Связь `children` не загружается — поле равно `null`, поле `products` отсутствует.

### 4.4 Товары категории

**Endpoint:** `GET /api/v1/stores/{store}/categories/{category}/products`

**Query параметры:**
- `page` — int, default 1
- `perPage` — int, default 20, максимум 100
- `sort` — `popular` | `price_asc` | `price_desc` | `discount` | `newest`. Значение по умолчанию и любое нераспознанное значение дают сортировку по `products.title` по возрастанию
- `priceMin`, `priceMax` — int, необязательные
- `filter` — массив, фильтры по атрибутам и флагам (новое)
- `countOnly` — bool (новое)
- `cityId` — int, необязательный
- `onlyDiscounted` — bool, необязательный
- `sugarFree` — bool, необязательный

Snake_case-варианты `price_min`, `price_max`, `count_only`, `city_id`, `only_discounted`, `sugar_free` принимаются как алиасы соответствующих camelCase-параметров: если snake_case передан, а camelCase — нет, значение копируется в camelCase.

**Логика:**
1. Берутся покупаемые товары магазина по всему поддереву категории.
2. `onlyDiscounted=true` оставляет товары с заполненным `price_old` либо с активной акцией.
3. `sugarFree=true` оставляет товары с `sugar_free = true`.
4. `priceMin`/`priceMax` сравниваются с `products.price`.
5. Применяются фильтры `filter` (см. ниже).
6. При `countOnly=true` ответ — только количество, пагинация и сортировка не выполняются.
7. Применяется сортировка. `popular` сортирует по числу позиций в заказах (`order_items`) по убыванию, `discount` — по проценту скидки `(price_old - price) / price_old` по убыванию.
8. Выдача пагинируется; для каждого товара загружаются изображения и счётчики добавок (`supplements_count`, `supplements_required_count`).
9. При переданном `cityId` и наличии у города координат к товарам добавляются `deliveryDate` и `pickupDate`.

**Формат `filter`:**
- `filter[code]=value_code1,value_code2` — фильтр по select-атрибуту. Между разными кодами AND (товар подходит под каждый код), внутри одного кода OR (подходит любое из значений). Значения сопоставляются с `product_attributes.attribute_value_code` при `attribute_value_id IS NOT NULL`. Значение принимается и списком (`filter[code][]=...`), и строкой с запятыми. Пустые значения отбрасываются.
- `filter[<флаг>]=1` — фильтр по динамическому чекбоксу товара. Коды флагов берутся из настройки `entity_checkboxes` (элементы `Product` с `custom && enabled && filterable`). Включают фильтр только значения `1` и `true` (регистр не важен), остальные игнорируются. Код, совпадающий одновременно с флагом и с кодом атрибута, обрабатывается флаг-веткой.

**Ответ 200:**
```json
{
  "hasMore": true,
  "data": [ /* ProductResource[] */ ],
  "total": 50
}
```

**Ответ 200 при `countOnly=1`:**
```json
{"total": 50}
```

**Подстановка дат (`cityId`):** город ищется по id; если у него нет `latitude`/`longitude`, даты не подставляются. `deliveryDate` — первая из дат «сегодня»/«завтра», для которой модуль доставки вернул непустой список слотов по коду магазина и координатам города; результат кешируется на 30 минут ключом `catalog_delivery:{cityId}:{storeCode}:{date}`, отсутствие слотов кешируется тем же ключом. Ошибка обращения к модулю доставки даёт `null`. `pickupDate` — первая из ближайших 7 дат, для которой есть слоты самовывоза; при `stores.is_pickup_enabled = false` равен `null`.

### 4.5 Диапазон цен категории

**Endpoint:** `GET /api/v1/stores/{store}/categories/{category}/price-range`

**Query параметры:** нет — диапазон считается по всему поддереву категории без учёта фильтров листинга.

**Логика:** минимум и максимум `products.price` по покупаемым товарам магазина в поддереве категории. Пустая выборка даёт нули.

**Ответ 200:** `{"min": 100, "max": 5000}` (значения приводятся к int).

### 4.6 Фильтры категории (новое)

**Endpoint:** `GET /api/v1/stores/{store}/categories/{category}/filters`

**Query параметры:** нет — набор фильтров считается по всему поддереву категории.

**Логика:**
1. Первой секцией всегда отдаётся диапазон цены по покупаемым товарам поддерева.
2. Далее — по одной секции на select-атрибут товаров этой выборки (`attribute_value_id IS NOT NULL`). Секции отсортированы по минимальному `product_attributes.position` внутри кода. Значения внутри секции уникальны по `attribute_value_id` и отсортированы по имени (натуральная сортировка, без учёта регистра). Имя значения — `product_attributes.value`, при пустом значении — `attribute_value_code`.
3. В конце — флаг-фасеты динамических чекбоксов (`custom && enabled && filterable` из настройки `entity_checkboxes`). Секция флага выводится, только если в выборке есть хотя бы один товар с этим флагом = `true`. Флаг, код которого уже занят атрибутной секцией, не дублируется.

**Ответ 200:**
```json
{
  "data": [
    {"code": "price", "name": "Цена", "type": "range", "min": 100, "max": 5000},
    {"code": "manufacturer", "name": "Производитель", "type": "checkbox", "values": [
      {"id": 12, "code": "abb", "name": "ABB"}
    ]},
    {"code": "is_novelty", "name": "Новинка", "type": "flag"}
  ]
}
```

Поле `code` внутри `values` может быть `null` (значение справочника без строкового кода). Секции типа `flag` поля `values` не содержат.

При пустой выборке товаров отдаётся только секция `price` с нулевыми `min`/`max`.

### 4.7 Детали товара

**Endpoint:** `GET /api/v1/products/{product}`

**Query параметры:** нет. Атрибуты, изображения, добавки и категории загружаются всегда; бренд — при включённом модуле `brands`.

**Логика (три варианта ответа):**

1. **Простой товар** (`parent_id IS NULL`, `is_configurable = false`) — `{"data": {...ProductResource...}}`.

2. **Вариация** (`parent_id IS NOT NULL`) при включённом модуле `configurable_products` — отдаётся активный родитель, у которого дополнительно загружены `configurableAttributes` и активные `children`; в ресурсе `selectedVariantId` равен id запрошенного товара. Если активный родитель не найден, отдаётся сам запрошенный товар. При выключенном модуле вариация отдаётся как обычный товар.

3. **Вариативный родитель** (`is_configurable = true`) при включённом модуле — `{"data": {...}}` с полями `variants`, `configurableAttributes`, `priceRange`, `defaultVariantId`.

**Ошибки:**
- 404 — товар не найден или soft-deleted (привязка модели);
- 404 — `is_active = false`;
- 404 — `is_configurable = true` при выключенном модуле `configurable_products`;
- 404 — `quantity < quantity_step` при выключенном модуле `preorders`, если товар не вариативный родитель и остаток не игнорируется (`stock_mode = 'ignore'` или `stores.ignore_stock = true`).

### 4.8 Похожие товары

**Endpoint:** `GET /api/v1/products/{product}/similar`

**Query параметры:**
- `page` — int, default 1
- `perPage` — int, default 10, максимум 50
- `onlyDiscounted`, `sugarFree` — bool, необязательные (snake_case-алиасы `only_discounted`, `sugar_free` принимаются)

**Логика:** покупаемые товары, связанные хотя бы с одной из категорий исходного товара, исключая сам товар. Фильтрации по магазину нет — в выдачу попадают товары любых магазинов, привязанных к тем же категориям. Сортировка случайная (`inRandomOrder`). Загружаются изображения и счётчики добавок.

**Ответ 200:** `{"hasMore": bool, "data": ProductResource[], "total": int}`.

### 4.9 Сопутствующие товары

**Endpoint:** `GET /api/v1/products/{product}/related`

**Query параметры:** `productsLimit` — int, default 9, максимум 9.

**Логика:** берётся основная категория товара (первая по `category_product.position`). Если её нет или у неё нет родителя — ответ `{"data": []}`. Иначе отбираются до 10 активных sibling-категорий того же родителя (исключая саму категорию), видимых в магазине товара, отсортированных по `categories.position` + `categories.name`. Для каждой берутся покупаемые товары этого магазина, напрямую привязанные к sibling-категории, отсортированные по `products.title`, не более `productsLimit` штук. Категории без товаров в ответ не попадают.

**Ответ 200:**
```json
{"data": [{"id": 1, "name": "Категория", "slug": "cat", "products": [ /* ProductResource[] */ ]}]}
```

### 4.10 Документы товара

**Endpoint:** `GET /api/v1/products/{product}/documents`

**Логика:** при выключенном модуле `product_documents` отдаётся `{"data": []}` со статусом 200. При включённом — документы товара, отсортированные по `position`.

**Ответ 200:**
```json
{"data": [{"id": 1, "title": "Паспорт", "fileUrl": "https://.../storage/docs/passport.pdf", "originalName": "passport.pdf", "mimeType": "application/pdf", "size": 10240, "group": "manuals", "position": 0}]}
```

`fileUrl` может быть `null` (пустой `file_path`), `originalName`, `mimeType`, `size`, `group` — могут быть `null`. `fileUrl` строится как `url('storage/'.$file_path)`.

### 4.11 Поиск товара по штрихкоду

**Endpoint:** `GET /api/v1/products/search/barcode/{barcode}`

**Логика:**
- Если штрихкод весовой — ровно 13 цифр с префиксом 20–29 — из него извлекаются 5 цифр кода номенклатуры после префикса, ведущие нули отбрасываются, и поиск идёт по `products.code`. Для устойчивости к padding перебираются варианты кода с разным числом ведущих нулей до длины 5 (код «296» находит записи `296`, `0296`, `00296`). Код из одних нулей нормализуется в «0» (новое).
- Иначе поиск идёт по JSON-полю `barcodes` (сравнение и строкового, и числового представления).
- Выборка ограничена покупаемыми товарами, отдаётся первое совпадение. Фильтрации по магазину нет.
- У найденного товара загружаются изображения, добавки со значениями, атрибуты, категории; бренд — при включённом модуле `brands`; для вариативного родителя при включённом модуле `configurable_products` — `configurableAttributes` и активные `children`.

**Ответ 200:** `{"data": {...ProductResource...}}`.
**Ответ 404:** `{"message": "Товар с указанным штрихкодом не найден"}`.

### 4.12 Список брендов (новое)

**Endpoint:** `GET /api/v1/brands`

**Query параметры:** нет.

**Логика:** при выключенном модуле `brands` отдаётся `{"data": []}` со статусом 200. При включённом — бренды с `is_active = true`, отсортированные по `position`; дополнительно считается средний `rating` активных товаров бренда.

**Ответ 200:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "ABB",
      "link": "https://abb.example",
      "countryOrigin": "Швейцария",
      "countryProduction": "Китай",
      "rating": 4.7,
      "certificate": "https://.../storage/brands/cert.pdf",
      "certificateName": "Сертификат официального дилера",
      "logoImage": {"id": 1, "path": "https://..."},
      "customCheckboxes": {}
    }
  ]
}
```

Поля `link`, `countryOrigin`, `countryProduction`, `certificate`, `certificateName` могут быть `null`. `rating` — среднее по `products.rating` активных товаров бренда, округлённое до 1 знака, может быть `null` (нет товаров с рейтингом). `logoImage` — объект `{id, path}` или `null`.

### 4.13 CategoryResource

| Поле | Тип | Комментарий |
|---|---|---|
| `id` | int | |
| `name` | string | |
| `slug` | string | |
| `position` | int | Собственная позиция категории (`categories.position`), не позиция в магазине (новое) |
| `image` | `{id, path}`\|null | `path` — полный URL |
| `backgroundImage` | `{id, path}`\|null | Фоновое изображение категории (новое) |
| `isLeaf` | bool\|отсутствует | Присутствует, когда загружен `children_count`; `true` при нуле видимых детей |
| `tagText` | string\|null | Текст тега; не `null` только у категории без видимых детей, с хотя бы одним покупаемым товаром магазина и непустым `tag_text` (новое) |
| `tagColor` | string\|null | HEX-цвет тега; отдаётся по тому же условию, что `tagText` (новое) |
| `parent` | отсутствует | Связь `parent` в этих ручках не загружается, поэтому поле в ответ не попадает |
| `children` | CategoryResource[]\|null | `null`, когда связь не загружена (все ручки, кроме `index` с `includeChildren=true`) |
| `products` | ProductResource[]\|отсутствует | Присутствует только у leaf-категорий при `includeChildren=true` |
| `customCheckboxes` | object | map «код чекбокса → bool»; пустой объект `{}`, если чекбоксов нет (новое) |

### 4.14 ProductResource

| Поле | Тип | Комментарий |
|---|---|---|
| `id` | int | |
| `sku` | string | |
| `title` | string | |
| `description` | string\|null | |
| `price` | number | Итоговая цена с копейками, decimal(12,2) — округление до 2 знаков (новое) |
| `priceOld` | number\|null | Зачёркнутая цена |
| `discountPercent` | int\|null | Процент скидки |
| `currency` | string | `RUB` |
| `images` | array | `{id, path}`; при `type != 'image'` добавляются `type` и `mimeType`. При выключенном модуле `product_video` элементы с `type = 'video'` исключаются. Связь загружается автоматически, поэтому поле присутствует всегда |
| `isFavorite` | bool | На публичных маршрутах каталога — `false` (аутентификация не подключена) |
| `servedWarm` | bool | Подаётся тёплым (новое) |
| `composition` | string\|null | Состав |
| `additionalInfo` | string\|null | Дополнительная информация (новое) |
| `proteins`, `fats`, `carbohydrates`, `calories` | float\|null | КБЖУ |
| `weight` | int\|null | Вес единицы товара в граммах (новое) |
| `attributes` | array | `{id, key, value, sortOrder}`; `[]`, когда связь не загружена (листинги). При включённом модуле `brands` и загруженном бренде в конец добавляется виртуальный атрибут `{id: 0, key: "Бренд", value: <название>, sortOrder: max+1}` (новое) |
| `rating` | float\|null | |
| `reviewsCount` | int | |
| `supplements` | array | SupplementResource[]; `[]`, когда связь не загружена. См. [20_product_supplements.md](./20_product_supplements.md) |
| `hasSupplements` | bool\|отсутствует | Присутствует, когда загружен `supplements_count` |
| `hasRequiredSupplements` | bool\|отсутствует | Присутствует, когда загружен `supplements_required_count`; `true`, если есть хотя бы одна обязательная добавка (новое) |
| `quantityStep` | int | Квант покупки; для весовых — в граммах |
| `measurementUnit` | string | `piece`, `gram`, `kilogram`, `liter`, `milliliter` |
| `measurementUnitLabel` | string | `шт`, `гр`, `кг`, `л`, `мл` |
| `stockQuantity` | int | Остаток; для весовых — в граммах |
| `videoUrl` | string\|отсутствует | Присутствует при включённом модуле `product_video` и заполненном `video_path` |
| `brand` | BrandResource\|null\|отсутствует | Присутствует при включённом модуле `brands` и загруженной связи; `null`, если бренд не задан (новое) |
| `groupQuantity` | int\|отсутствует | Присутствует, когда товар отдан через pivot с `quantity` (контекст `ProductGroup`) |
| `isPreorder` | bool\|отсутствует | Присутствует при включённом модуле `preorders`; `true`, если остаток не игнорируется и `quantity < quantity_step` |
| `availableFrom` | string\|null\|отсутствует | Присутствует при включённом модуле `product_ready_time`; ISO 8601 или `null`, если время готовности не задано или уже наступило (новое) |
| `deliveryDate` | string\|null\|отсутствует | Присутствует только в листинге товаров категории при переданном `cityId` |
| `pickupDate` | string\|null\|отсутствует | То же условие, что у `deliveryDate` |
| `isConfigurable` | bool | |
| `customCheckboxes` | object | map «код чекбокса → bool»; пустой объект `{}`, если чекбоксов нет (новое) |
| `priceRange` | `{min, max}`\|null\|отсутствует | Присутствует у вариативного родителя с загруженными `children`; `null`, если ни один вариант не доступен |
| `configurableAttributes` | array\|отсутствует | Присутствует у вариативного родителя с загруженной связью |
| `variants` | array\|отсутствует | Присутствует у вариативного родителя с загруженными `children` |
| `defaultVariantId` | int\|null\|отсутствует | Присутствует у вариативного родителя: явный `is_default_variant`, иначе первый активный вариант по id |
| `selectedVariantId` | int\|null\|отсутствует | Присутствует у вариативного родителя; заполнен, когда запрошена вариация |
| `category` | `{id, name, slug}`\|null\|отсутствует | Присутствует при загруженной связи `categories` — первая категория по `category_product.position` (новое) |
| `categories` | array\|отсутствует | Присутствует при загруженной связи: `{id, title}` по каждой категории. Поле `title` в модели категории отсутствует (название хранится в `name`), поэтому его значение равно `null` (новое) |
| `primary_category` | `{id, title}`\|null\|отсутствует | Первая категория; `title` равен `null` по той же причине (новое) |

Связь `categories` загружается в деталях товара, в поиске по штрихкоду и у товаров leaf-категорий при `includeChildren=true`; в листингах категории, похожих и сопутствующих товарах она не загружается, поэтому поля `category`, `categories`, `primary_category` там отсутствуют.

**Элемент `variants`:** `{id, sku, title, price, priceOld, stockQuantity, availability, images, attributes, isDefault}`, где `availability` — `available` (остаток покрывает квант либо остаток игнорируется), `preorder` (включён модуль `preorders`) или `out_of_stock`; `images` — собственные изображения варианта, при их отсутствии — изображения родителя; `attributes` — map «код атрибута → `attribute_value_id`».

**Элемент `configurableAttributes`:** `{code, name, position, values}`, где элемент `values` — `{id, code, label, variant_ids, available}`; `available = true`, если хотя бы один вариант с этим значением доступен по остатку.

**Расчёт цены:** база — `price_old`, если он задан, иначе `price`. При активной акции сравниваются ручная скидка (`price_old - price`) и акционная (`база × discount_percent / 100`); применяется большая. При акционной скидке `price` — цена после скидки, `priceOld` — база, `discountPercent` — процент акции. При ручной скидке `priceOld` равен `price_old`, а `discountPercent` считается как `100 - price / price_old × 100` и отдаётся только при положительном значении. Без скидок `priceOld` и `discountPercent` равны `null`.

## 5. Нефункциональные требования

### Кеширование
- Ответы ручек каталога не кешируются.
- Значения модулей и настроек (`ModuleConfigService`) кешируются на 10 минут и мемоизируются в пределах запроса.
- Даты доставки для листинга (`cityId`) кешируются на 30 минут ключом `catalog_delivery:{cityId}:{storeCode}:{date}`; отрицательный результат (нет слотов) кешируется тем же ключом.

### Производительность
- Модель `Product` автоматически подгружает `activePromotions` и `images`.
- Листинги дополнительно грузят `supplements_count` и `supplements_required_count`; детали товара — `supplements.values`, `attributes`, `categories`, при включённом модуле — `brand`.
- Топ-8 товаров leaf-категорий при `includeChildren=true` выбирается оконной функцией `ROW_NUMBER()` в SQL, а не обрезкой коллекции в PHP.
- Индексы: `products (store_id, is_active)`, `products.code`, `product_attributes (code, attribute_value_code)`, `category_store (store_id, is_active, position)`, `category_product (category_id)`.

### Поиск

Полнотекстовый поиск вынесен в отдельный модуль (Meilisearch). См. [search_meilisearch_spec.md](./search_meilisearch_spec.md) и [api_search.md](../api_search.md):

- `GET /api/v1/search/products?query=...` — поиск по всем магазинам с группировкой по магазину
- `GET /api/v1/search/stores/{storeId}/products?query=...` — поиск внутри одного магазина
- `GET /api/v1/search/suggestions?query=...` — автодополнение
- `GET /api/v1/search/recommendations` — рекомендации

Маршруты группы `search` ограничены throttle 100 запросов в минуту. Отдельного эндпоинта `GET /api/v1/stores/{storeId}/products/search` в маршрутах нет.

## 6. Реализация

### Код
- Модели: `Category`, `Product`, `ProductImage`, `ProductAttribute`, `ConfigurableAttribute`, `ProductDocument`, `Brand`
- Контроллеры: `CategoryController`, `ProductController`, `BrandController`
- Ресурсы: `CategoryResource`, `ProductResource`, `ProductAttributeResource`, `ProductDocumentResource`, `BrandResource`, `ImageResource`
- Сервисы: `ModuleConfigService` (модули и флаг-фасеты), `PickupSlotService`, `DeliveryClientInterface`
- Поддержка: `App\Support\WeightedBarcode` (весовой штрихкод)

### Тесты
- `tests/Feature/Api/V1/Catalog/CategoryTest.php`
- `tests/Feature/Api/V1/Catalog/CategoryHomeTest.php`
- `tests/Feature/Api/V1/Catalog/CategoryFiltersTest.php`
- `tests/Feature/Api/V1/Catalog/CategoryFlagFiltersTest.php`
- `tests/Feature/Api/V1/Catalog/CategorySubtreeProductsTest.php`
- `tests/Feature/Api/V1/Catalog/ProductTest.php`
- `tests/Feature/Api/V1/Catalog/ProductByCategoryTest.php`
- `tests/Feature/Api/V1/Catalog/ProductBarcodeSearchTest.php`
- `tests/Feature/Api/V1/Catalog/BrandBlockFieldsTest.php`
- `tests/Feature/Api/V1/Product/*` (добавки, вес и бренд-атрибут, сопутствующие товары, `servedWarm`)

### Routes
```php
Route::prefix('v1')->group(function () {
    // Бренды
    Route::get('brands', [BrandController::class, 'index']);

    // Категории
    Route::get('stores/{store}/categories', [CategoryController::class, 'index']);
    Route::get('stores/{store}/categories/home', [CategoryController::class, 'home']);
    Route::get('stores/{store}/categories/{category}/children', [CategoryController::class, 'children']);
    Route::get('stores/{store}/categories/{category}/products', [ProductController::class, 'byCategory']);
    Route::get('stores/{store}/categories/{category}/price-range', [CategoryController::class, 'priceRange']);
    Route::get('stores/{store}/categories/{category}/filters', [CategoryController::class, 'filters']);

    // Товары
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::get('products/{product}/similar', [ProductController::class, 'similar']);
    Route::get('products/{product}/related', [ProductController::class, 'related']);
    Route::get('products/{product}/documents', [ProductController::class, 'documents']);
    Route::get('products/search/barcode/{barcode}', [ProductController::class, 'searchByBarcode']);
});
```
