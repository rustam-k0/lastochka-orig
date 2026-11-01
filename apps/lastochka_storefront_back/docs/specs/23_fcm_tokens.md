# ТЗ: FCM-токены для push-уведомлений

## 1. Цель

Регистрация, обновление и удаление токенов Firebase Cloud Messaging для доставки push-уведомлений.

## 2. Сущность

```
user_fcm_tokens
├── id: bigint PK
├── user_id: bigint FK users (cascade on delete)
├── token: string UNIQUE
├── device_type: string NULL       // ios | android
├── device_name: string NULL
├── last_sent_at: timestamp NULL
├── created_at / updated_at
├── INDEX (user_id)
└── INDEX (token)
```

Колонки `period` и `sound` удалены миграцией `2026_05_11_131510_drop_period_sound_from_user_fcm_tokens_table`; в запросах и ответах этих полей нет.

> ⚠️ Асимметрия именования: **тело запроса — camelCase** (`deviceType`, `deviceName`), **поля ответа — snake_case** (`device_type`, `device_name`, `last_sent_at`, `created_at`, `updated_at`). Snake_case-ключи в теле запроса (`device_type`, `device_name`) принимаются как deprecated-алиасы: перед валидацией они копируются в camelCase, если camelCase-ключ отсутствует.

Токены участвуют в доставке push: сообщение уходит только на токены пользователей с `push_enabled = true` (см. `22_notifications.md`). После успешной отправки на токене проставляется `last_sent_at`.

## 3. Endpoints

Все маршруты под `auth:sanctum`; без валидного Bearer-токена — 401.

### 3.1 GET /api/v1/fcm-tokens [AUTH]

Список токенов текущего пользователя, сортировка по `created_at` по убыванию.

**Response 200:**
```json
{
  "data": [
    {
      "id": 12,
      "token": "fcm-xxx",
      "device_type": "android",
      "device_name": "Samsung Galaxy S24",
      "last_sent_at": null,
      "created_at": "2026-05-11T13:15:10.000000Z",
      "updated_at": "2026-05-11T13:15:10.000000Z"
    }
  ]
}
```

**Поля (`UserFcmTokenResource`):**

| Поле | Тип | Комментарий |
|---|---|---|
| `id` | int | (новое) |
| `token` | string | |
| `device_type` | string \| null | `ios` \| `android`, может быть null |
| `device_name` | string \| null | может быть null |
| `last_sent_at` | string \| null | ISO 8601 (UTC), может быть null |
| `created_at` | string | ISO 8601 (UTC) |
| `updated_at` | string | (новое) ISO 8601 (UTC) |

Токены других пользователей в выдачу не попадают; при отсутствии записей `data` — пустой массив.

### 3.2 POST /api/v1/fcm-tokens [AUTH]

Сохранение токена. Запись создаётся или обновляется по уникальному `token` (`updateOrCreate`), при этом `user_id` перезаписывается текущим пользователем — токен, ранее зарегистрированный на другого пользователя, переходит к текущему.

**Body:**
```json
{
  "token": "fcm-xxx",
  "deviceType": "ios",
  "deviceName": "iPhone 15"
}
```

**Валидация:**
- `token` — required, string, max 255 («FCM токен обязателен для заполнения.», «FCM токен не должен превышать 255 символов.»)
- `deviceType` — nullable, string, `in:ios,android` («Тип устройства должен быть ios или android.»). Значение `web` не принимается.
- `deviceName` — nullable, string, max 255

Не переданные `deviceType` / `deviceName` записываются как `null` — в том числе затирают ранее сохранённые значения при обновлении существующего токена.

**Ответ:**
- **201 Created** — токен создан этим запросом
- **200 OK** — обновлена существующая запись

Тело в обоих случаях: `{"data": UserFcmTokenResource}`.

**Ошибки:** 422 при отсутствующем/слишком длинном `token` или недопустимом `deviceType`.

### 3.3 DELETE /api/v1/fcm-tokens [AUTH]

Удаление токена текущего пользователя. Значение `token` читается через `$request->input()`, то есть принимается и в query-строке, и в теле запроса.

**Параметр:** `token` — required, string.

**Ответ:**
- **204 No Content** — запись пользователя с таким токеном удалена
- **404** с пустым телом — токена нет или он принадлежит другому пользователю (`response()->noContent(404)`)

**Ошибки:** 422, если `token` не передан.

## 4. Routes

```php
Route::prefix('v1')->group(function () {
    Route::prefix('fcm-tokens')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [FcmTokenController::class, 'index']);
        Route::post('/', [FcmTokenController::class, 'store']);
        Route::delete('/', [FcmTokenController::class, 'destroy']);
    });
});
```

Маршрут `PATCH /api/v1/fcm-tokens/settings` удалён вместе с полями `period` и `sound`; обращение к нему возвращает 404.

См. также: `22_notifications.md` (список уведомлений и настройки каналов).
