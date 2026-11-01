# ТЗ: Авторизация и Профиль

## 1. Цель

OTP-авторизация по телефону (SMS или звонок), управление профилем, смена номера и удаление аккаунта.

## 2. Контекст

- **Модуль:** Auth
- **Контроллер:** `AuthController`
- **Аутентификация:** Laravel Sanctum (Bearer-токен)

## 3. Данные

### 3.1 Модель User

```
users
├── id: bigint PK
├── external_id: string NULL              // ID во внешней системе
├── external_source: string NULL          // источник внешнего ID
├── loyalty_id: string(64) NULL UNIQUE    // (новое) идентификатор лояльности LTY-<ULID>
├── card_number: string(16) NULL UNIQUE   // (новое) номер карты лояльности
├── card_created_at: timestamp NULL       // (новое)
├── card_confirmed_at: timestamp NULL     // (новое)
├── phone: string(20) UNIQUE NOT NULL
├── first_name: string(100) NULL
├── last_name: string(100) NULL
├── patronymic: string(100) NULL
├── email: string(255) NULL
├── birth_date: date NULL
├── gender: enum('male','female') NULL
├── is_b2b: boolean DEFAULT false
├── bonus_balance: unsignedInt DEFAULT 0
├── bonus_reserved: unsignedInt DEFAULT 0          // (новое)
├── orders_enabled: boolean DEFAULT true           // (новое) настройка уведомлений, см. 22_notifications.md
├── promo_enabled: boolean DEFAULT true            // (новое)
├── push_enabled: boolean DEFAULT true             // (новое)
├── ready_time_modal_suppressed: boolean DEFAULT false  // (новое)
├── created_at: timestamp
├── updated_at: timestamp
└── deleted_at: timestamp NULL            // Soft delete
```

**Индексы:** `phone` (unique + index), `external_id + external_source`, `is_b2b`, `loyalty_id` (unique), `card_number` (unique).

### 3.2 Модель OtpCode

```
otp_codes
├── id: bigint PK
├── phone: string(20) NOT NULL
├── code: string(60) NOT NULL       // bcrypt-хеш кода, не сам код
├── attempts: unsignedTinyInt DEFAULT 0
├── expires_at: timestamp NOT NULL
├── verified_at: timestamp NULL
├── created_at: timestamp NULL
└── INDEX (phone, expires_at)
```

Таблица без `updated_at`. Индекс `(phone, code)` удалён: поиск по хешу через индекс невозможен, запись ищется по `phone` и проверяется через `Hash::check`.

## 4. Логика

### 4.1 phoneLogin — Запрос OTP

**Endpoint:** `POST /api/v1/auth/phone-login`

**Входные данные:**
```json
{
  "phone": "+79991234567",
  "method": "sms"
}
```

**Валидация:**
- `phone` — required, string, regex `^\+79\d{9}$` (ровно `+79` и 9 цифр). Сообщение об ошибке: «Неверный формат номера телефона. Используйте формат +79XXXXXXXXX».
- `method` — sometimes, string, `in:sms,call`. При отсутствии используется `sms`.

Регулярное выражение применяется к исходному значению до нормализации, поэтому номер с пробелами, скобками или дефисами отклоняется с 422. Нормализация (удаление `\s()-`) выполняется уже после успешной валидации.

**Поведение сервера:**
1. Проверка лимита: при 5 и более записях в `otp_codes` по этому номеру за последние 10 минут — 429.
2. Для номеров из `AUTH_TEST_PHONES` используется фиксированный код, отправка SMS/звонка не выполняется.
3. Предыдущие активные коды по номеру помечаются `verified_at = now()` (инвалидируются).
4. Генерируется код: при `method=sms` длиной из `AUTH_SMS_CODE_LENGTH` (4–6 цифр, по умолчанию 6; значение вне диапазона прижимается к границе), при `method=call` всегда 4 цифры.
5. Код сохраняется в `otp_codes` в виде bcrypt-хеша, `expires_at = now + 5 минут`, `attempts = 0`.
6. Отправка выполняется драйвером, если драйвер не равен `otp`: `smsaero` (SMS) или `ucaller` (звонок). Драйвер берётся из активной записи `integration_configs` с `integration_type` = `sms` или `call`; при её отсутствии — из `config('services.sms_service.driver')` (`SMS_SERVICE_DRIVER`, значение по умолчанию `otp`). При неизвестном драйвере отправка завершается ошибкой (500).

**Ответ 200:**
```json
{
  "message": "OTP sent",
  "expiresIn": 300,
  "method": "sms",
  "codeLength": 6
}
```

- `expiresIn` — **camelCase**, всегда 300 (5 минут).
- `codeLength` — длина выданного кода: при `method=sms` из `AUTH_SMS_CODE_LENGTH` (по умолчанию 6, на mendev 4), при `method=call` 4. Для номеров из `AUTH_TEST_PHONES` это длина их фиксированного кода. Клиент рисует ячейки ввода по этому полю, а не по константе.
- `method` — эхо переданного значения (или `sms` по умолчанию).

**Режим без реальной отправки** (драйвер равен `otp`): в ответ добавляется поле `code`:
```json
{
  "message": "OTP sent",
  "expiresIn": 300,
  "method": "sms",
  "codeLength": 6,
  "code": "123456"
}
```

**Ошибки:**
- 422 — невалидный формат телефона или `method` вне `sms|call`
- 429 — `{"message": "Превышен лимит запросов OTP. Попробуйте позже."}`

### 4.2 phoneConfirm — Подтверждение OTP

**Endpoint:** `POST /api/v1/auth/phone-confirm`

**Входные данные:**
```json
{
  "phone": "+79991234567",
  "code": "123456"
}
```

**Валидация:**
- `phone` — required, string, regex `^\+79\d{9}$`
- `code` — required, string, regex `^\d{4,6}$` (границы `OtpService::MIN_CODE_LENGTH` / `MAX_CODE_LENGTH`). Сообщение: «Код подтверждения должен содержать от 4 до 6 цифр».

**Поведение сервера** (внутри одной транзакции БД):
1. Ищется активная запись `otp_codes` по номеру: `expires_at > now` и `verified_at IS NULL`.
2. Записи нет, либо `attempts >= 3` — 422 без изменения счётчика.
3. Хеш не совпал — `attempts` увеличивается на 1, 422.
4. При совпадении: `verified_at = now()`.
5. Пользователь ищется по `phone` с учётом soft-deleted. Удалённый — восстанавливается (`restore`). Отсутствующий — создаётся, и ему сразу выпускается карта лояльности: заполняются `loyalty_id` и `card_number`, публикуется событие `loyalty_card.created`. Для существующего пользователя карта повторно не выпускается.
6. Создаётся Sanctum-токен с именем `auth_token`.

**Ответ 200:**
```json
{
  "token": "1|abc123...",
  "user": {
    "id": 1,
    "phone": "+79991234567",
    "firstName": null,
    "lastName": null,
    "patronymic": null,
    "email": null,
    "birthDate": null,
    "gender": null,
    "bonusBalance": 0,
    "readyTimeModalSuppressed": false
  }
}
```

Объект `user` в этом ответе вкладывается напрямую, без обёртки `data`. Поля `loyalty_id` / `card_number` в `UserResource` не отдаются.

**Ошибки:**
- 422 — неверный, истёкший код или превышены попытки:
```json
{
  "message": "Неверный или истёкший код подтверждения.",
  "errors": {"code": ["Неверный или истёкший код подтверждения."]}
}
```

### 4.3 getProfile [AUTH]

**Endpoint:** `GET /api/v1/auth/profile`

**Ответ 200** (ресурс обёрнут в `data`):
```json
{
  "data": {
    "id": 1,
    "phone": "+79991234567",
    "firstName": "Иван",
    "lastName": "Иванов",
    "patronymic": "Иванович",
    "email": "ivan@example.com",
    "birthDate": "1990-01-15T00:00:00+00:00",
    "gender": "male",
    "bonusBalance": 1500,
    "readyTimeModalSuppressed": false
  }
}
```

**Поля ответа (`UserResource`):**

| Поле | Тип | Комментарий |
|---|---|---|
| `id` | int | |
| `phone` | string | |
| `firstName` | string \| null | может быть null |
| `lastName` | string \| null | может быть null |
| `patronymic` | string \| null | может быть null |
| `email` | string \| null | может быть null |
| `birthDate` | string \| null | ISO 8601, может быть null |
| `gender` | string \| null | `male` \| `female`, может быть null |
| `bonusBalance` | int | 0 при отсутствии значения |
| `readyTimeModalSuppressed` | bool | (новое) false при отсутствии значения |

**Ошибки:** 401 без валидного Bearer-токена.

### 4.4 updateProfile [AUTH]

**Endpoint:** `PUT /api/v1/auth/profile`

**Входные данные:**
```json
{
  "firstName": "Иван",
  "lastName": "Иванов",
  "patronymic": "Иванович",
  "email": "ivan@example.com",
  "birthDate": "1990-01-15",
  "gender": "male",
  "readyTimeModalSuppressed": true
}
```

**Валидация:**
- `firstName` — nullable, string, max 100
- `lastName` — nullable, string, max 100
- `patronymic` — nullable, string, max 100
- `email` — nullable, email, max 255, уникальный среди `users.email` с исключением собственной записи (конфликт → 422, «Этот email уже используется»)
- `birthDate` — nullable, date, `before_or_equal:today`
- `gender` — nullable, enum `Gender`: `male` \| `female` (значения `other` нет)
- `readyTimeModalSuppressed` — sometimes, boolean **(новое)**

**Поведение сервера:** обновляются только те поля, ключи которых присутствуют в запросе; отсутствующие ключи сохраняют прежние значения. Явно переданный `null` записывается в БД.

**Ответ 200:** `{"data": {...}}` — обновлённый `UserResource`, состав полей как в `getProfile`.

### 4.5 logout [AUTH]

**Endpoint:** `POST /api/v1/auth/logout`

Удаляется только текущий токен доступа; остальные токены пользователя продолжают действовать.

**Ответ:** 204 No Content.

### 4.6 changePhone [AUTH]

**Endpoint:** `POST /api/v1/auth/change-phone`

Первый шаг смены номера: OTP отправляется на **новый** номер.

**Входные данные:**
```json
{
  "newPhone": "+79991234567",
  "method": "sms"
}
```

> Поле называется `newPhone`, а не `phone`.

**Валидация:**
- `newPhone` — required, string, regex `^\+79\d{9}$`, уникальный среди `users.phone` с исключением собственной записи. Сообщение при конфликте: «Этот номер телефона уже используется».
- `method` — sometimes, string, `in:sms,call`

**Ответ 200:** тот же формат, что и у `phoneLogin`, включая `message: "OTP sent"`:
```json
{"message": "OTP sent", "expiresIn": 300, "method": "sms", "codeLength": 6}
```
В режиме драйвера `otp` добавляется поле `code`.

**Ошибки:**
- 401 — без токена
- 422 — неверный формат или номер занят другим пользователем
- 429 — лимит OTP по новому номеру (5 запросов за 10 минут)

### 4.7 changePhoneConfirm [AUTH]

**Endpoint:** `POST /api/v1/auth/change-phone/confirm`

**Входные данные:**
```json
{
  "newPhone": "+79991234567",
  "code": "123456"
}
```

**Валидация:**
- `newPhone` — required, string, regex `^\+79\d{9}$`
- `code` — required, string, regex `^\d{4,6}$`, сообщение как в 4.2

Проверка занятости номера на этом шаге не выполняется — она есть только в `changePhone`. Колонка `users.phone` уникальна, поэтому запись занятого номера приводит к ошибке уровня БД (500).

**Поведение сервера:** код проверяется тем же механизмом, что и в `phoneConfirm`; при успехе OTP помечается `verified_at = now()`, а `users.phone` текущего пользователя заменяется на новый номер. Токены не отзываются.

**Ответ 200** (без обёртки `data`):
```json
{"user": {"id": 1, "phone": "+79991234567", "...": "..."}}
```

**Ошибки:**
- 422 — неверный или истёкший код:
```json
{
  "message": "Неверный или истёкший код подтверждения.",
  "errors": {"code": ["Неверный или истёкший код подтверждения."]}
}
```

### 4.8 deleteAccount [AUTH]

**Endpoint:** `DELETE /api/v1/auth/account`

**Поведение сервера:**
1. Проверяются заказы пользователя со статусом, отличным от `cancelled` и `delivered` (то есть `pending`, `assembling`, `assembled`, `inDelivery`). При наличии хотя бы одного — 409.
2. Иначе удаляются все Sanctum-токены пользователя, все его записи в `user_fcm_tokens`, а сам пользователь помечается удалённым (soft delete).

Повторная авторизация по тому же номеру через `phone-confirm` восстанавливает запись пользователя вместе с накопленными данными (см. 4.2).

**Ответ:** 204 No Content.

**Ошибки:**
- 409 — `{"message": "Невозможно удалить аккаунт при наличии активных заказов."}`

## 5. Нефункциональные требования

### Rate Limiting
- `phoneLogin` и `changePhone`: не более 5 запросов OTP на номер за 10 минут (считаются записи `otp_codes`), сверх лимита — 429. Middleware `throttle` на этих маршрутах не установлен.
- `phoneConfirm`: не более 3 неверных попыток на один код; далее код считается непригодным до запроса нового.

### Безопасность
- Код хранится в `otp_codes.code` в виде bcrypt-хеша, сравнение через `Hash::check`.
- Plaintext-код попадает в ответ API только при драйвере `otp`.
- Sanctum-токены бессрочные: `config('sanctum.expiration') = null`.
- Все маршруты, кроме `phone-login` и `phone-confirm`, закрыты `auth:sanctum`; без валидного токена — 401.

### Транзакции
- `phoneConfirm`: проверка кода, поиск/создание/восстановление пользователя, выпуск карты лояльности и создание токена выполняются в одной транзакции.

## 6. Реализация

### Код
- Миграции: `create_users_table`, `create_otp_codes_table` (+ миграции добавления полей лояльности, бонусов, настроек уведомлений и `ready_time_modal_suppressed`)
- Модели: `User` (SoftDeletes, HasApiTokens), `OtpCode`
- Контроллер: `AuthController`
- Form Requests: `PhoneLoginRequest`, `PhoneConfirmRequest`, `UpdateProfileRequest`, `ChangePhoneRequest`, `ChangePhoneConfirmRequest`
- Resource: `UserResource`
- Actions: `SendOtpAction`, `VerifyOtpAction`
- Сервисы: `OtpService`, `IntegrationConfigService`, `LoyaltyCardService`
- Исключения: `OtpRateLimitException` (429), `InvalidOtpException` (422)
- Enum: `Gender` (`male`, `female`)

### Тесты
`tests/Feature/Api/V1/Auth/AuthTest.php`: отправка OTP, лимит запросов, тестовые номера, создание и возврат существующего пользователя, неверный/истёкший код, превышение попыток, профиль, обновление профиля (включая уникальность email и дату рождения не в будущем), logout.

### Routes
```php
Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('phone-login', [AuthController::class, 'phoneLogin']);
        Route::post('phone-confirm', [AuthController::class, 'phoneConfirm']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('profile', [AuthController::class, 'getProfile']);
            Route::put('profile', [AuthController::class, 'updateProfile']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('change-phone', [AuthController::class, 'changePhone']);
            Route::post('change-phone/confirm', [AuthController::class, 'changePhoneConfirm']);
            Route::delete('account', [AuthController::class, 'deleteAccount']);
        });
    });
});
```
