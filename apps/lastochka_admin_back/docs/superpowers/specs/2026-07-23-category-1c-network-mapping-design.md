# Сетевой маппинг категорий 1С (один каталог на всю сеть) — Design Spec

| Метаданные | |
|---|---|
| Заказчик | «Русский Разгуляйка» (mendev), сеть 800+ магазинов, центральная 1С |
| Дата | 2026-07-23 |
| Origin форк | mendev |
| Распространение | Sync-core broadcast → Aladdin / Lastochka / Electrohub (dormant, флаг default=false) |
| Затронутые репо | `mendev_admin_back`, `mendev_admin_front`, контракт-док `docs/specs/2026-06-24-1c-rabbitmq-direct-contract.md` (корень mendev) |
| Базовая спека | `2026-05-05-1c-categories-mapping-design.md` (пер-магазинный маппинг, origin Lastochka) |

---

## 1. Контекст и цель

### Проблема

Существующий маппинг «Категории 1С → витринные» пер-магазинный: `categories_1c` имеет `shop_id` (UNIQUE `shop_id+external_id`), pivot `category_1c_category` висит на пер-магазинной строке, `SyncCategoryMappingsJob` работает по одному магазину. При сети из 800+ магазинов это означает 800 клонов одного дерева (~6 200 × 800 ≈ 5 млн строк) и 800× ручной работы товароведа.

У заказчика **одна центральная 1С**, в которой все магазины: классификатор номенклатуры общий, GUID групп одинаковые для всех магазинов. Обмен идёт по прямому контракту 1С↔RabbitMQ (без 1c_parser): `category_1c.*` одиночными событиями, товары одиночными и батчами (`product.batch_updated` и др.), карточки товаров публикуются пер-магазинно со своим `shop_code`.

### Решение

Режим «сетевой маппинг» за флагом: справочник 1С-категорий один на весь проект (все события категорий нормализуются к **якорному магазину**), маппинг настраивается один раз, «Применить» раскладывает товары **всех** магазинов по единым витринным категориям, витрины автоматически становятся видимы во всех магазинах.

Ключевой приём: якорный `shop_id` вместо nullable-схемы. Схема БД **не меняется вообще** — существующий UNIQUE `(shop_id, external_id, deleted_at)` при константном shop_id сам обеспечивает дедуп (включая race параллельных консьюмеров), а все существующие запросы/мутации/джобы продолжают работать, получая якорный shop_id.

### Не-цели

- НЕ трогаем 1c_parser (выведен из архитектуры Разгуляйки; на других форках работает по-старому).
- НЕ меняем пер-магазинность товаров/цен/остатков — карточка привязана к магазину по контракту, это корректно.
- НЕ делаем пер-магазинный override маппинга (подтверждено: «заказчику нужен один каталог»). Скрытие товара в конкретном магазине решается остатками/стоп-листами.
- НЕ делаем батч-версию `category_1c.*` (справочник ~6 200 узлов уезжает одиночными событиями за минуты).
- НЕ делаем обратную миграцию «сетевой → пер-магазинный» (выключение флага после нормализации оставит записи под якорем; задокументировано как one-way).

---

## 2. Флаг и якорь

### 2.1. Модуль `category_1c_network_mapping`

Стандартный `projectModules`-паттерн (JSON в `admin_settings.key='project_modules'`), boolean-модуль с дополнительным ключом:

```json
"category_1c_network_mapping": {
  "label": "Сетевой маппинг категорий 1С",
  "enabled": true,
  "anchor_shop_id": 1,
  "description": "Единый справочник 1С-категорий и единый маппинг на витринные категории для всей сети магазинов"
}
```

`SaveProjectModules::validateBooleanModule` требует только `label`+`enabled`, лишние ключи не отбрасывает — `anchor_shop_id` валиден без правок мутации.

### 2.2. `ModuleConfigService` (admin_back)

По образцу существующих геттеров (`isHeavyOrdersEnabled` и т.д.):

```php
public const CATEGORY_1C_NETWORK_KEY = 'category_1c_network_mapping';
public const CACHE_KEY_CATEGORY_1C_NETWORK = 'module_config:admin:category_1c_network_mapping';

public static function isCategory1cNetworkMappingEnabled(): bool;   // fail-safe false, cache 5 мин
public static function getCategory1cNetworkAnchorShopId(): ?int;    // null если модуль выключен/якорь некорректен
```

Правила `getCategory1cNetworkAnchorShopId()`:
- модуль выключен → `null`;
- `anchor_shop_id` отсутствует / не положительное число → warning-лог + `null`;
- **`null` якорь при включённом enabled трактуется всеми потребителями как «режим выключен»** (fail-safe: не угадываем магазин).
- существование Shop не проверяется на каждый вызов (кеш 5 мин, якорь задаётся при включении осознанно); нормализационная команда (§6) проверяет явно.

Оба ключа добавить в `invalidateCache()`.

### 2.3. `global_scope` пакета

Пакет `comrades/laravel-rabbitmq-events` уже поддерживает `entities.<x>.global_scope=true`: метадата-идентичность `(entity_type, source, external_id)` ищется без `shop_code` — одна сущность на все магазины (используется у `onec_exchange_statuses`).

Включаем динамически, чтобы файл конфига остался идентичным между форками:

```php
// AppServiceProvider::boot()
if (ModuleConfigService::getCategory1cNetworkAnchorShopId() !== null) {
    config(['rabbitmq-events.entities.categories_1c.global_scope' => true]);
}
```

Консьюмеры — долгоживущие воркеры: **после переключения флага обязателен рестарт supervisor-группы `mendev_rabbitmq`** (задокументировано в rollout §8).

---

## 3. Изменения admin_back (все — за флагом; флаг выключен → поведение бит-в-бит текущее)

Единая точка ветвления: приватный хелпер в каждом классе не плодим — читаем `ModuleConfigService::getCategory1cNetworkAnchorShopId()`; `!== null` означает «сетевой режим», значение — якорь.

### 3.1. `Category1cEventTransformer`

- `fromEvent()`: при сетевом режиме `$attrs['shop_id'] = $anchorShopId` независимо от `shop_code` события (валидация конверта не меняется: `shop_code` остаётся обязательным полем контракта). Любой источник, приславший категорию с любым `shop_code`, попадает в единый справочник — устойчивость к тому, что 1С всё-таки зашлёт справочник 800 раз.
- `findExistingEntity()`: при сетевом режиме lookup `(shop_id = $anchorShopId, external_id)` вместо резолва по `shop_code`.
- `resolveParent()` / `adoptWaitingChildren()`: без изменений — работают от `$entity->shop_id`, который в сетевом режиме всегда якорь.

### 3.2. `ProductEventTransformer::fromEvent` (резолв `category_1c_external_id`, ~строка 485)

При сетевом режиме — lookup `Category1c::where('shop_id', $anchorShopId)` вместо `resolveShopId($shopCode)` товара. Товар **любого** магазина цепляется к сетевой записи.

Покрывает оба потока: одиночные `product.*` и батчи — `ProductBatchHandler` переиспользует этот же трансформер.

### 3.3. `Category1cObserver::resolvePending`

При сетевом режиме убрать фильтр `where('shop_id', $shopId)` по товарам: pending-товары **всех** магазинов дозаполняются, когда приезжает их Category1c. Остальная логика (bulk update, `ProductShowcaseLinker`, `ProductUpdated`) не меняется.

### 3.4. `SyncCategoryMappingsJob`

При сетевом режиме убрать `Product::where('shop_id', $this->shopId)` из count- и обход-запросов (товары всех магазинов), `whereHas('category1c', ...)` оставить со скоупом по shop_id джоба (= якорь — фронт его и передаёт). Фильтр `last_synced_at`-апдейта категорий уже по shop_id джоба — корректен.

Лок `category_1c_sync:{shopId}` с якорем = «один сетевой sync одновременно» — семантика сохраняется. `Category1cSyncBatch.shop_id` = якорь, схема батчей не меняется.

`StartSync` / `GetCurrentBatch` — без изменений (фронт передаёт якорный shop_id).

### 3.5. `LinkToShowcase` + распространение витрин на магазины

Сейчас мутация аттачит витрины (с предками) к магазину 1С-категории через `ShopService::attachCategoriesWithEvents` (touch новых → `category.updated` с relatedModel=Shop → storefront привязывает к store).

При сетевом режиме витрина должна стать видимой **во всех** магазинах. 800 синхронных attach в мутации — недопустимо, поэтому:

- Новый queued-job **`PropagateShowcaseCategoriesToShopsJob(array $showcaseCategoryIds)`**: итерирует все магазины (`Shop::query()->lazyById()`), для каждого `attachCategoriesWithEvents($shop, $showcaseCategoryIds)`. Идемпотентен (`syncWithoutDetaching` + touch только новых). Очередь default.
- `LinkToShowcase` при сетевом режиме вместо цикла по `$cats1c->pluck('shop_id')` диспатчит этот job с `$showcaseIds`.
- Объём событий: N новых витрин × ancestors × 800 магазинов `category.updated` в outbox — сопоставимо с батч-обменами, приемлемо.

`UnlinkFromShowcase` / `UnlinkOneFromShowcase` — без изменений (shop-скоупа не имеют; товары по существующей семантике «застывают» до следующего Apply).

### 3.6. Новый магазин в сети

Хук на `Shop::created` (+ `restored`, если применимо): при сетевом режиме диспатчит `PropagateShowcaseCategoriesToShopsJob` с ограничением на этот магазин (перегрузка параметром `?int $onlyShopId`) и списком витрин, участвующих в сетевом маппинге:

```php
$showcaseIds = Category::whereHas('category1cMappings')->pluck('id'); // через pivot category_1c_category
```

Реализация: новый `ShopObserver` (в admin_back его сейчас нет) с единственным поведением за флагом, регистрация в `AppServiceProvider::boot()`.

Ограничение: магазины, созданные в обход Eloquent-событий (shops-консьюмер пишет `withoutEvents`, bulk insert), observer не увидит — они подхватятся ближайшим Link (Propagate по всем магазинам). Доминирующий путь онбординга магазина — авто-создание из product-событий через `ResolvesShopCode::firstOrCreate` — события Eloquent триггерит, observer отрабатывает.

Товары нового магазина приезжают из 1С со своими `category_1c_external_id` → резолвятся на сетевые записи (§3.2) → `ProductShowcaseLinker` в `processRelatedData` сразу раскладывает их по витринам. Ручной Apply для нового магазина не нужен.

### 3.7. Artisan-команда нормализации `category-1c:normalize-network-scope`

Однократная команда при включении режима на проекте с уже накопленными данными:

1. Проверяет: режим включён, якорный Shop существует — иначе abort с ошибкой.
2. Все `categories_1c` с `shop_id != anchor` (включая soft-deleted):
   - если под якорем записи с тем же `external_id` нет → `UPDATE shop_id = anchor`;
   - если есть (дубликат) → merge: выживает якорная строка; pivot `category_1c_category` дубликата переносится `syncWithoutDetaching`; `products.category_1c_id` перевешивается bulk-UPDATE; `parent_id` детей дубликата перевешивается на якорную строку; `linked_at`/`quantum_*` якорной строки не затираются (приоритет якоря); дубликат удаляется физически (`forceDelete`, метадата-строки дубликата удаляются).
3. Пересчёт `parent_id` по `parent_external_id` внутри якорного набора (повторное использование логики late-binding).
4. `--dry-run` обязателен в реализации: отчёт «переносов/merge/конфликтов» без записи.

На mendev dev команда фактически no-op: все 6 206 строк уже под `shop_id=1`, магазины `zia`(2) и `mendev_storefront`(3) строк не имеют. Команда нужна как страховка и для будущих включений на форках.

### 3.8. Что НЕ меняется

- Схема БД: ни одной миграции структуры. Единственная миграция — INSERT-обновление дефолтов `project_modules` не требуется (модуль добавляется через UI/SQL при включении).
- GraphQL-схема и все query (`TreeList`, `PaginatedList`, `NewCount`, `GetMappings`, `GetMappingsCount`, `HasOrphanSources`): аргумент `shop_id` остаётся, фронт передаёт якорь.
- `ProductShowcaseLinker`, pivot `category_1c_category`, `category_product`, бейджи `is_linked`/`is_new`, `RebuildShowcaseFromCategory1cCommand` (запускается с `--shop=<anchor>`; после него при сетевом режиме запустить `PropagateShowcaseCategoriesToShopsJob` — отметить в help-тексте команды).
- storefront_back, delivery_back, purchase_back — без изменений (получают стандартные `product.updated`/`category.updated`).

---

## 4. Изменения admin_front

1. `src/stores/moduleConfig.js` → `MODULE_DEFAULTS`:
   ```js
   category_1c_network_mapping: {
     label: "Сетевой маппинг категорий 1С",
     enabled: false,
     description: "Единый справочник и маппинг категорий 1С для всей сети магазинов",
   },
   ```
2. `views/category-1c-management/index.vue`: если модуль включён —
   - селектор магазина скрыт, вместо него статичная подпись «Вся сеть»;
   - `selectedShopId` = `anchor_shop_id` из конфига модуля (`moduleStore.config.category_1c_network_mapping.anchor_shop_id`); если ключа нет — фолбэк на текущее поведение (селектор виден) + console.warn;
   - всё остальное (обе колонки, drag-and-drop, Apply, polling) работает без изменений — компоненты получают якорный `shop-id`.
3. Экран настроек модулей показывает новый модуль автоматически (рендер из MODULE_DEFAULTS); поле `anchor_shop_id` редактируется через существующий JSON-механизм SaveProjectModules (отдельный UI-инпут не делаем — включение разовое, админское).

---

## 5. Изменения контракта 1С

`docs/specs/2026-06-24-1c-rabbitmq-direct-contract.md`, раздел 1 «Категории» — дополнить:

- Справочник категорий публикуется **один раз для всей сети**, НЕ по разу на магазин.
- `shop_code` в конверте категорий: фиксированное значение = код якорного магазина (на dev — `mendev`). Поле остаётся обязательным.
- Если справочник всё же будет опубликован с разными `shop_code` — система склеит их в один (сетевой режим), но это лишний трафик; так делать не нужно.
- Товары (`product.*`, батчи) — без изменений: публикуются пер-магазинно, каждая позиция несёт `category_1c_external_id` из единого справочника.

---

## 6. Порядок и гарантии (edge cases)

| Случай | Поведение |
|---|---|
| Категории приезжают с разными `shop_code` (1С шлёт per-store) | Все нормализуются к якорю; `global_scope=true` метадаты + UNIQUE `(anchor, external_id)` дают одну запись. Race двух консьюмеров ловится Duplicate entry → повторный find (стандартный паттерн пакета). |
| Товар магазина X приехал раньше своей категории | `pending_category_1c_external_id` (существующий механизм); `resolvePending` при сетевом режиме дозаполняет товары всех магазинов. |
| Флаг включили при живых пер-магазинных данных | Обязательный порядок: **stop consumers → включение флага + `cache:clear` → `category-1c:normalize-network-scope` → start consumers**. При включении на живых консьюмерах есть окно: global_scope-метадата может указать на ещё не нормализованную неякорную строку → `forceFill(shop_id=якорь)` ловит Duplicate entry по UNIQUE → 3 retry → DLQ. На mendev окно пустое (все строки уже под якорем), на форках с данными порядок обязателен. |
| Флаг выключили обратно | Записи остаются под якорем; экран снова с селектором, якорный магазин показывает весь справочник. В момент выключения привязки не рвутся, но **эрозия со временем**: каждое последующее `product.*` с `category_1c_external_id` для неякорного магазина не найдёт категорию под своим shop_id → занулит `category_1c_id` и уведёт товар в pending. Обратной миграции нет (one-way); выключение после нормализации — только с пониманием эрозии. |
| Новый магазин | ShopObserver → propagate витрин; товары резолвятся на сетевые категории автоматически (§3.6). |
| Apply при сетевом режиме | Один batch (shop_id=якорь), товары всех магазинов, progress_total = сумма по сети. Лок один на сеть. |
| `event_timestamp` устаревший | Стандартный outdated-guard пакета, не меняется. |
| Магазин-якорь удалили | `getCategory1cNetworkAnchorShopId()` продолжает отдавать id (магазин soft-deleted); consumer пишет в существующие строки. Операционное правило: якорь не удалять; при смене якоря — повторный normalize с новым id. |

---

## 7. Тесты (admin_back, mendev_test, запуск только `--filter`)

| Тест | Покрытие |
|---|---|
| `ModuleConfigServiceCategory1cNetworkTest` | enabled/disabled, отсутствующий/битый anchor_shop_id → null + не падает. |
| `Category1cNetworkTransformerTest` | При флаге: два события с разными shop_code → одна запись под якорем; findExistingEntity по якорю; при выключенном флаге — пер-магазинное поведение (регрессия). |
| `ProductEventTransformerNetworkCategoryTest` | Товар магазина X резолвит category_1c якоря; pending при отсутствии; explicit null отвязывает. |
| `Category1cObserverNetworkTest` | resolvePending дозаполняет pending-товары нескольких магазинов. |
| `SyncCategoryMappingsJobNetworkTest` | Товары двух магазинов обновляются одним batch; идемпотентность; progress_total. |
| `LinkToShowcaseNetworkTest` | Диспатч PropagateShowcaseCategoriesToShopsJob (Queue::fake), пер-магазинный attach не вызывается. |
| `PropagateShowcaseCategoriesToShopsJobTest` | Attach ко всем магазинам, идемпотентность, режим onlyShopId. |
| `NormalizeNetworkScopeCommandTest` | Перенос без дубликата; merge с дубликатом (pivot, products FK, parent_id детей); dry-run без записи. |
| admin_front | Тестовой инфраструктуры нет (vitest не заведён) — `npm run build` + ручная проверка экрана на dev: селектор скрыт, «Вся сеть», обе колонки работают от якоря. |

---

## 8. Rollout

1. **Код**: ветка `feat/category-1c-network-mapping` в admin_back + admin_front; контракт-док правится в корневом репо mendev (git там отсутствует — просто файл).
2. **Деплой**: push (по команде) → CI (`last_pipeline --wait`) → автодеплой. Миграций нет. `lighthouse:clear-cache` не нужен (схема GraphQL не меняется), но по чек-листу деплоя admin_back выполнить безвредно.
3. **Деплой-заметка про очередь:** `PropagateShowcaseCategoriesToShopsJob` и `SyncCategoryMappingsJob` — queued. При `QUEUE_CONNECTION=sync` dispatch выполняется инлайн (мутация Link заблокируется на проходе по всем магазинам) — для сети 800+ магазинов на проде обязателен реальный queue-драйвер с воркером. На dev (3 магазина) sync терпим.
4. **Включение на mendev**:
   ```sql
   UPDATE admin_settings SET value = JSON_SET(value,
     '$.category_1c_network_mapping',
     JSON_OBJECT('label','Сетевой маппинг категорий 1С','enabled',true,'anchor_shop_id',1,
                 'description','Единый справочник и маппинг категорий 1С для всей сети'))
   WHERE `key`='project_modules';
   ```
   затем `php artisan cache:clear` (или дождаться 5-мин TTL) и **`sudo supervisorctl restart mendev_rabbitmq:*`** (global_scope читается при старте воркера).
5. `php artisan category-1c:normalize-network-scope --dry-run` → verify no-op → без dry-run.
6. **Верификация** (двусторонняя): опубликовать в `app_events` тестовое `category_1c.updated` с `shop_code='zia'` по существующему GUID → убедиться, что запись осталась одна и под shop_id=1 (предусловие: событие обработано — лог consumer; результат: `SELECT COUNT(*) ... WHERE external_id=...` = 1). Привязать 1С-категорию к витрине в UI (селектор магазина отсутствует) → Apply → проверить товары обоих магазинов в `category_product` + `category.updated`/`product.updated` в outbox → витрина видна в `shops_categories` всех магазинов.
7. **Форки**: broadcast sync-core (dormant, флаг false). Verify по `git log --grep` на всех форках. Включение на форке с живыми пер-магазинными данными — строго по порядку из §6: stop consumers → флаг + cache:clear → normalize → start consumers.

---

## 9. References

- Базовая спека: `2026-05-05-1c-categories-mapping-design.md`
- Контракт 1С: `<mendev root>/docs/specs/2026-06-24-1c-rabbitmq-direct-contract.md`
- Батч-события товаров: `<storefront>/docs/specs/2026-07-10-product-batch-events-design.md` (ProductBatchHandler переиспользует ProductEventTransformer)
- Пакет: `comrades/laravel-rabbitmq-events` — `EventConsumer` (global_scope, INSERT-first metadata), `Config/rabbitmq-events.php:96`
- Memory: `sync-core-feature-flag-pattern`, `rabbitmq-pitfalls`, `mendev-local-testing` (тесты только --filter), `admin-settings` (projectModules)
