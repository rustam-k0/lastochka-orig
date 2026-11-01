# ТЗ: Истории (Stories)

## 1. Цель

Истории (stories) с переходом на товар, магазин или внешнюю ссылку. Отдельный подтип историй: быстрые категории (флаг `isQuickCategory`).

## 2. Контекст

- **Модуль:** Story
- **Источник данных:** админ-панель курьерской системы, входящая синхронизация через RabbitMQ (routing key `stories`)
- **Авторизация:** обе ручки публичные; Bearer-токен не требуется и на состав ответа не влияет
- **Пагинация:** отсутствует, список отдаётся целиком

## 3. Данные

### 3.1 Таблица stories

```
stories
├── id: bigint PK
├── source: string NULL                        // источник записи (внешняя система)
├── external_id: string NULL                    // id записи во внешней системе
├── title: string(200) NOT NULL
├── thumbnail_path: string(500) NOT NULL        // превью до нажатия
├── link_type: string(20) DEFAULT 'none'
├── link_product_id: bigint FK → products.id NULL (ON DELETE SET NULL)
├── link_store_id: bigint FK → stores.id NULL (ON DELETE SET NULL)
├── link_external_url: string(500) NULL
├── expires_at: datetime NOT NULL               // дата окончания показа
├── is_active: boolean DEFAULT true
├── is_quick_category: boolean DEFAULT false
├── position: int DEFAULT 0
├── custom_checkboxes: json NULL                // динамические чекбоксы (новое)
├── created_at: timestamp
├── updated_at: timestamp
└── deleted_at: timestamp NULL
```

**Индексы:**
- `is_active, expires_at`

Колонка `position` в выдаче не используется: сортировка идёт по `created_at`.

### 3.2 Таблица story_images

```
story_images
├── id: bigint PK
├── story_id: bigint FK → stories.id (ON DELETE CASCADE)
├── path: string(500) NOT NULL
├── position: int DEFAULT 0
└── created_at: timestamp NULL
```

Изображения отдаются отсортированными по `position` по возрастанию.

### 3.3 Ответ API (StoryResource)

```json
{
  "id": 1,
  "title": "Новинки недели",
  "thumbnailImage": {"id": 1, "path": "https://cdn.example.com/story_thumb.jpg"},
  "images": [
    {"id": 1, "path": "https://cdn.example.com/story1.jpg"},
    {"id": 2, "path": "https://cdn.example.com/story2.jpg"}
  ],
  "linkType": "product",
  "linkProductId": 10,
  "linkStoreId": null,
  "linkExternalUrl": null,
  "isQuickCategory": false,
  "expiresAt": "2026-01-20T23:59:59+03:00",
  "customCheckboxes": {}
}
```

| Поле | Тип | Комментарий |
|---|---|---|
| `id` | int | |
| `title` | string | |
| `thumbnailImage` | object | `{id, path}`, где `id` совпадает с id истории, `path` содержит полный URL. Колонка `thumbnail_path` не допускает NULL, поэтому объект присутствует всегда |
| `images` | array | Присутствует в обеих ручках, изображения загружаются всегда. Элемент: `{id, path}` |
| `linkType` | string | Значение общего enum `App\Enums\LinkType`: `none`, `product`, `store`, `shop`, `category`, `external` |
| `linkProductId` | int\|null | Может быть null |
| `linkStoreId` | int\|null | Может быть null |
| `linkExternalUrl` | string\|null | Может быть null |
| `isQuickCategory` | bool | Признак сториз-категории для быстрого доступа |
| `expiresAt` | string | ISO 8601 со смещением таймзоны приложения (`Europe/Moscow`, `+03:00`) |
| `customCheckboxes` | object | Динамические чекбоксы, map `код флага` → bool (новое). При отсутствии значения отдаётся `{}` |

Путь изображения приводится к полному URL: значение, начинающееся с `http://` или `https://`, отдаётся как есть, относительный путь разворачивается через публичный диск Storage.

## 4. Логика

### 4.1 Список историй

**Endpoint:** `GET /api/v1/stories`

**Query:**
- `isQuickCategory`: bool, optional. При `true` отдаются только быстрые категории, при `false` только обычные истории, без параметра отдаются все.
- `is_quick_category`: deprecated snake_case алиас `isQuickCategory` (новое: раньше поддерживалось только это написание). Если переданы оба, приоритет у `isQuickCategory`.

Значение параметра разбирается как boolean (`1`, `true`, `on`, `yes` трактуются как `true`, остальное как `false`). Валидации нет, некорректное значение приводит к фильтру по `false`.

**Логика:**
1. Отбираются истории с `is_active = true` и `expires_at > now()`.
2. При переданном фильтре добавляется условие по `is_quick_category`.
3. Сортировка: `created_at DESC`.
4. Подгружаются изображения (отсортированы по `position`).

**Response 200:** `{"data": [StoryResource, ...]}`.

### 4.2 Детали истории

**Endpoint:** `GET /api/v1/stories/{story}`

Параметр пути: id истории. Мягко удалённые (`deleted_at`) записи не резолвятся.

**Логика:**
1. История ищется по id.
2. Проверяются `is_active = true` и `expires_at` в будущем.
3. Подгружаются все изображения.

**Response 200:** `{"data": {...StoryResource...}}`.

**Ошибки:**
- 404: история не найдена, мягко удалена, неактивна или истекла.

## 5. Реализация

### Код
- [x] Миграция `create_stories_table` (+ `add_source_to_stories_table`, `add_is_quick_category_to_stories_table`, `add_custom_checkboxes_to_content_entities`)
- [x] Миграция `create_story_images_table`
- [x] Модели `Story`, `StoryImage` с factories, scope `Story::scopeAvailable()`
- [x] `StoryController`
- [x] `StoryResource`, изображения через универсальный `ImageResource`

### Тесты (`tests/Feature/Api/V1/Story/StoryTest.php`)
- [x] `test_get_stories`
- [x] `test_get_stories_excludes_expired`
- [x] `test_get_story`
- [x] `test_get_story_returns_404_for_expired`
- [x] `test_get_story_returns_404_for_inactive`
- [x] `test_get_story_returns_404_for_nonexistent`

### Routes
```php
Route::prefix('v1')->group(function () {
    Route::prefix('stories')->group(function () {
        Route::get('/', [StoryController::class, 'index']);
        Route::get('/{story}', [StoryController::class, 'show']);
    });
});
```
