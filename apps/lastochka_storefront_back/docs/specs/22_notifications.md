# ТЗ: Уведомления

## 1. Цель

Отдать клиенту список in-app уведомлений пользователя (заказы, акции), счётчик непрочитанных и настройки каналов уведомлений.

## 2. Сущности

### 2.1 UserNotification

```
user_notifications
├── id: bigint PK
├── user_id: bigint FK users (cascade on delete)
├── type: string                  // order | promo
├── title: string
├── short_description: string NULL
├── body: text NULL
├── image_url: string NULL
├── promo_code: string NULL
├── deep_link: string NULL
├── announcement_id: unsignedBigInt NULL
├── is_read: boolean DEFAULT false
├── created_at / updated_at
├── INDEX (user_id, type, created_at)
├── INDEX (user_id, is_read)
└── INDEX (announcement_id)
```

**NotificationType** enum: `order`, `promo`.

Колонки `order_id` в таблице нет: связь с заказом передаётся через `deep_link` вида `order/{id}`. Для акции `deep_link` имеет вид `announcement/{announcementId}`.

### 2.2 Настройки уведомлений на пользователе

Три булевых флага в `users`, все со значением по умолчанию `true`: `orders_enabled`, `promo_enabled`, `push_enabled`.

Флаги влияют на то, какие записи вообще создаются и рассылаются:
- при `orders_enabled = false` уведомления о смене статуса заказа не сохраняются в `user_notifications` и push не отправляется;
- при `promo_enabled = false` пользователь исключается из рассылки уведомлений об акциях;
- при `push_enabled = false` запись в `user_notifications` создаётся, но push через FCM не отправляется.

## 3. Endpoints

Все маршруты под `auth:sanctum`; без валидного Bearer-токена — 401.

### 3.1 GET /api/v1/notifications [AUTH]

Список уведомлений текущего пользователя, сортировка по `created_at` по убыванию.

**Query:**
- `type` — optional, `order|promo`. Нераспознанное значение фильтр не применяет (отдаётся полный список), 422 не возвращается.
- `page` — int, default 1
- `perPage` — int, default 20, значения больше 50 усекаются до 50

**Response 200:**
```json
{
  "hasMore": true,
  "data": [
    {
      "id": 10,
      "type": "order",
      "title": "Заказ №1234 собран",
      "shortDescription": "Курьер заберёт посылку в 12:00",
      "body": "Курьер заберёт посылку в 12:00",
      "imageUrl": null,
      "promoCode": null,
      "deepLink": "order/1234",
      "announcementId": null,
      "isRead": false,
      "createdAt": "2026-04-15T10:30:00+00:00"
    }
  ],
  "total": 10
}
```

**Поля элемента (`UserNotificationResource`):**

| Поле | Тип | Комментарий |
|---|---|---|
| `id` | int | |
| `type` | string | `order` \| `promo` |
| `title` | string | |
| `shortDescription` | string \| null | (новое) может быть null |
| `body` | string \| null | может быть null |
| `imageUrl` | string \| null | (новое) абсолютный URL; относительный путь разворачивается через публичный диск; может быть null |
| `promoCode` | string \| null | (новое) может быть null |
| `deepLink` | string \| null | (новое) `order/{orderId}` или `announcement/{announcementId}`; может быть null |
| `announcementId` | int \| null | может быть null |
| `isRead` | bool | |
| `createdAt` | string | ISO 8601 |

Поля `orderId` в ответе нет.

### 3.2 GET /api/v1/notifications/unread-count [AUTH]

Количество непрочитанных уведомлений.

**Query:** `type` — optional, `order|promo`; нераспознанное значение фильтр не применяет.

**Response 200:**
```json
{"count": 5}
```

### 3.3 PATCH /api/v1/notifications/read-all [AUTH] (новое)

Помечает прочитанными все непрочитанные уведомления текущего пользователя. При переданном `type` затрагиваются только уведомления этого типа.

**Query:** `type` — optional, `order|promo`.

**Response 200:**
```json
{"updated": 2}
```

`updated` — количество изменённых записей, 0 если непрочитанных не было.

### 3.4 GET /api/v1/notifications/by-announcement/{announcementId} [AUTH]

Уведомление пользователя по ID акции. `announcementId` — целое число. Отдаётся первая найденная запись пользователя с таким `announcement_id`.

**Response 200:** `{"data": UserNotificationResource}`

**Ошибка 404** — `{"message": "Уведомление не найдено"}`, если у пользователя нет записи по этой акции.

### 3.5 GET /api/v1/notifications/{notification} [AUTH]

Детальное уведомление по ID.

**Response 200:** `{"data": UserNotificationResource}`

**Ошибки:**
- 404 — записи с таким ID не существует (route model binding)
- 403 — запись принадлежит другому пользователю

### 3.6 PATCH /api/v1/notifications/{notification}/read [AUTH]

Проставляет `is_read = true`.

**Response 200:** `{"data": UserNotificationResource}` с уже обновлённым `isRead: true`.

**Ошибки:**
- 404 — записи с таким ID не существует
- 403 — запись принадлежит другому пользователю

### 3.7 GET /api/v1/users/me/notification-settings [AUTH] (новое)

Текущие настройки каналов уведомлений.

**Response 200:**
```json
{
  "data": {
    "ordersEnabled": true,
    "promoEnabled": true,
    "pushEnabled": true
  }
}
```

Все три поля — bool, при отсутствии значения в БД отдаётся `true`.

### 3.8 PATCH /api/v1/users/me/notification-settings [AUTH] (новое)

Частичное обновление настроек.

**Body:**
```json
{
  "ordersEnabled": true,
  "promoEnabled": false,
  "pushEnabled": true
}
```

**Валидация:** `ordersEnabled`, `promoEnabled`, `pushEnabled` — каждое sometimes, boolean. Поля, отсутствующие в запросе, не изменяются. Пустое тело допустимо и оставляет настройки без изменений.

**Response 200:** `{"data": {...}}` — актуальные настройки после обновления.

**Ошибки:** 422 при небулевом значении, например `{"errors": {"ordersEnabled": ["Поле «Уведомления о заказах» должно быть true или false."]}}`.

## 4. Источники записей

- `SendOrderStatusNotification` — смена статуса заказа: `type=order`, `deep_link = order/{orderId}`, `title`/`body` по статусу.
- `SendSurchargeRequiredNotification`, `SendReceiptReadyNotification` — служебные уведомления по заказу, `type=order`.
- `SaveAnnouncementNotifications` — массовая запись уведомлений об акции всем пользователям с `promo_enabled = true`: `type=promo`, заполняются `short_description`, `body`, `image_url`, `promo_code`, `announcement_id`, `deep_link = announcement/{id}`.

## 5. Routes

```php
Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/read-all', [NotificationController::class, 'readAll']);
        Route::get('/by-announcement/{announcementId}', [NotificationController::class, 'showByAnnouncement']);
        Route::get('/{notification}', [NotificationController::class, 'show']);
        Route::patch('/{notification}/read', [NotificationController::class, 'markAsRead']);
    });

    Route::middleware('auth:sanctum')->prefix('users/me')->group(function () {
        Route::get('notification-settings', [NotificationSettingsController::class, 'show']);
        Route::patch('notification-settings', [NotificationSettingsController::class, 'update']);
    });
});
```

См. также: `23_fcm_tokens.md` (push-доставка).
