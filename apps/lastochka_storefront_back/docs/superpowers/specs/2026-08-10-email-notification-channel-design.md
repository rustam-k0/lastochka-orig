# Канал уведомлений email (`email_enabled`)

Дата: 2026-08-10
Модуль: `mendev_storefront_back`

## Задача

Добавить пользователям витрины четвёртый флаг уведомлений — `email_enabled` — по аналогии с существующим `push_enabled`, и довести канал до реальной доставки писем.

## Текущее состояние

В `users` есть три флага, добавленные миграцией `2026_05_11_131058_add_notification_settings_to_users_table`:

| Флаг | Смысл | Дефолт |
|---|---|---|
| `orders_enabled` | тип: уведомления о заказах | `true` |
| `promo_enabled` | тип: акции и анонсы | `true` |
| `push_enabled` | канал: push | `true` |

Разделение двухуровневое: тип-флаги гейтят создание in-app `UserNotification`, канальный `push_enabled` — только отправку push поверх типа. Логика в `User::isNotificationTypeEnabled()` и `User::canReceivePush()`.

Канал используется в `FirebaseNotificationService` (5 мест) и в трёх джобах: `SendOrderStatusNotification`, `SendReceiptReadyNotification`, `SendSurchargeRequiredNotification`. Промо-анонсы рассылает `SaveAnnouncementNotifications` (bulk insert по `promo_enabled`).

Настройки отдаёт и принимает REST `GET|PUT /v1/notification-settings` через `NotificationSettingsResource` и `UpdateNotificationSettingsRequest`.

Почтовая инфраструктура в модуле уже есть: `Mail::to(...)->send(...)` в `SendVacancyResponseAction`, `VacancyResponseMail`, шаблон `resources/views/emails/vacancy-response.blade.php` (обычный blade с inline-стилями). Поле `users.email` — `nullable`, верификации адреса нет, авторизация в системе по телефону.

## Принятые решения

1. **Полный канал end-to-end**: флаг, API настроек, гейт-метод и реальная отправка писем.
2. **Адрес — `users.email` без верификации.** Пусто или не проходит `FILTER_VALIDATE_EMAIL` — письмо тихо пропускается с записью в лог, остальные каналы работают.
3. **Дефолт `email_enabled = false`** — канал opt-in. Отсюда fail-safe семантика: неизвестное значение трактуется как «нельзя», в отличие от push (`?? true`).
4. **Архитектура — зеркало push**: отдельный `EmailNotificationService` с сигнатурой `FirebaseNotificationService`, ветвление каналов в джобах. Отвергнуты: единый `NotificationDispatcher` (рефактор трёх работающих джоб ради ещё не существующего канала) и штатные Laravel Notifications (in-app пишется прямо в `UserNotification`, push идёт мимо `Notifiable` — переезд затрагивает формат данных).

## Реализация

### Данные и модель

Миграция `add_email_enabled_to_users_table` с guard'ом, как в существующей:

```php
$table->boolean('email_enabled')->default(false)->after('push_enabled');
```

В `User`: `email_enabled` в `$fillable` и в `casts()` как `boolean`.

Новые методы рядом с `canReceivePush()`:

```php
/**
 * Можно ли слать email конкретного типа пользователю.
 * Email требует И общий тоггл email_enabled, И тип-тоггл, И заполненный адрес.
 */
public function canReceiveEmail(NotificationType $type): bool
{
    if (! ($this->email_enabled ?? false)) {
        return false;
    }

    if (! $this->hasDeliverableEmail()) {
        return false;
    }

    return $this->isNotificationTypeEnabled($type);
}

/**
 * Адрес пригоден для отправки: заполнен и проходит валидацию формата.
 */
public function hasDeliverableEmail(): bool
{
    return $this->email !== null
        && filter_var($this->email, FILTER_VALIDATE_EMAIL) !== false;
}
```

### API настроек

`NotificationSettingsResource` — четвёртое поле:

```php
'emailEnabled' => (bool) ($this->email_enabled ?? false),
```

`UpdateNotificationSettingsRequest`:

- правило `'emailEnabled' => ['sometimes', 'boolean']`
- маппинг `'emailEnabled' => 'email_enabled'`
- сообщение `'emailEnabled.boolean' => 'Поле «Email-уведомления» должно быть true или false.'`

Механика partial-update в `settings()` общая, новое поле подхватывается ею без правок.

`NotificationSettingsController` — обновить PHPDoc в `show` и `update` (из них Scramble генерирует схему для `/docs/api`).

Включить `emailEnabled` при пустом `users.email` API разрешает: настройки и профиль — разные эндпоинты, блокировка тумблера дала бы мобилке неочевидную ошибку. Письмо в этом случае не уходит, факт скипа пишется в лог.

### Отправка

**`app/Mail/UserNotificationMail.php`** — один generic Mailable на все типы:

- конструктор: `string $title`, `string $body`, `?string $orderUrl`
- `envelope()`: `subject: $title` (заголовки в джобах уже человекочитаемы — «Заказ собран», «Чек готов»)
- `content()`: вид `emails.user-notification`

**`resources/views/emails/user-notification.blade.php`** — заголовок, текст, кнопка «Перейти к заказу» только при непустом `$orderUrl`. Стиль — как у `vacancy-response.blade.php`: обычный blade, inline-стили, без markdown-mail.

**`app/Services/EmailNotificationService.php`**:

```php
public function sendToUser(User $user, array $notification, array $data = []): array
```

Внутри — повторная проверка `email_enabled` и `hasDeliverableEmail()` (`FirebaseNotificationService::sendToUser()` так же дублирует проверку `push_enabled`: сервис нельзя использовать в обход настроек), затем `Mail::to($user->email)->queue(new UserNotificationMail(...))`.

Ссылку на заказ строит сам сервис — приватным методом из `$data['order_id']` и шаблона конфига (подстановка `{id}`). Джобы про URL ничего не знают и передают тот же `$data`, что уже уходит в push. Нет `order_id` в `$data` или не задан шаблон — `$orderUrl = null`.

Возврат: `['sent' => 1, 'skipped' => null]` либо `['sent' => 0, 'skipped' => 'email_disabled'|'no_email']` — чтобы вызывающий код логировал причину.

`queue()`, а не `send()`: пользователь результата не ждёт, а джоба не должна блокироваться на SMTP заказчика. `QUEUE_CONNECTION=database`, письмо уезжает отдельной задачей; падение почты не ретраит джобу и не задваивает push.

**`config/notifications.php`** — ключ `email.order_url_template` (env `NOTIFICATION_EMAIL_ORDER_URL`, например `https://raz-dev.mendev.ru/orders/{id}`). У `storefront_back` в конфиге есть только `APP_URL` — адрес самого API, домена витрины модуль не знает; push-овый `deep_link` вида `order/17` мобильный и в письме бесполезен. Шаблон не задан — письмо без кнопки, только с номером заказа в тексте.

### Подключение в джобах

`SendOrderStatusNotification`, `SendReceiptReadyNotification`, `SendSurchargeRequiredNotification` — вторым аргументом `handle()` инжектится `EmailNotificationService`, следом за push-блоком добавляется:

```php
if ($order->user->canReceiveEmail(NotificationType::Order)) {
    try {
        $emailNotificationService->sendToUser($order->user, $notificationText, $dataPayload);
    } catch (\Throwable $e) {
        Log::warning('order-status email failed', [
            'order_id' => $order->id,
            'error' => $e->getMessage(),
        ]);
    }
}
```

Пример выше — из `SendOrderStatusNotification`, где текст лежит в `$notificationText`. В двух других джобах он собран в локальные `$title`/`$body`, поэтому первым аргументом идёт `['title' => $title, 'body' => $body]` — ровно то же, что уже передаётся в push по месту.

`try/catch` обязателен во всех трёх: `$tries = 3`, непойманное исключение почты повторило бы джобу целиком и задвоило уже созданное in-app уведомление и уже отправленный push.

В `SendReceiptReadyNotification` email-блок встаёт рядом с push (тот уже обёрнут в `try/catch`) и строго до `$receipt->update(['notified_at' => now()])` — идемпотентность чека не меняется.

Тип-гейт `orders_enabled` отрабатывает выше по коду и режет все каналы разом; `canReceiveEmail()` содержит его повторно.

### Промо-рассылка

Новая джоба `SendAnnouncementEmails`, которую `SaveAnnouncementNotifications` диспатчит после успешного сохранения in-app уведомлений. Внутри — свой `chunkById(500)` по выборке `promo_enabled = true AND email_enabled = true AND email IS NOT NULL` и вызов `EmailNotificationService::sendToUser()` на каждого пользователя с `NotificationType::Promo`. Отправка идёт через тот же сервис, а не через `Mail::to()` напрямую, чтобы проверка канала и адреса оставалась в одной точке.

Встроить отправку в `SaveAnnouncementNotifications` нельзя: она пишет `UserNotification::insert()` пачками без загрузки моделей, и при её трёх попытках падение почты задваивало бы in-app уведомления по всей базе.

Кнопки в промо-письме нет: `deep_link` там вида `announcement/42` — мобильный маршрут.

## Тестирование

Все тесты через `Mail::fake()`, локально `php artisan test` (SQLite in-memory).

- `NotificationSettingsTest` (расширить): `GET` отдаёт `emailEnabled: false` по умолчанию; `PUT {emailEnabled: true}` сохраняет и не трогает остальные три флага; невалидное значение → 422.
- `NotificationGateTest` (расширить): `email_enabled = false` → писем нет; `true` + заполненный `email` → `Mail::assertQueued(UserNotificationMail::class)`; `true` + `email = null` → писем нет, push уходит; `orders_enabled = false` → не уходит ничего.
- `AnnouncementEmailTest` (новый): письма только тем, у кого одновременно `promo_enabled`, `email_enabled` и непустой `email`.
- Unit на `User::canReceiveEmail()`: комбинации флага, типа и наличия адреса, включая `null` в колонке (fail-safe в `false`).

## Пререквизиты деплоя

Без них фича молча не работает:

1. Миграция на dev.
2. Реальные `MAIL_*` в `.env` витрины — при `MAIL_MAILER=log` (значение из `.env.example`) письма уходят в лог. Фактическое значение проверить на сервере перед верификацией.
3. `NOTIFICATION_EMAIL_ORDER_URL` — опционально, без него письмо без кнопки.
4. `queue:work` — уже в supervisor; письма ставятся в очередь `database`.
5. Контракт OpenAPI: Scramble отдаёт документацию на лету по `/docs/api`, файл `api.json` лежит в `.gitignore` и не коммитится — после деплоя кода поле `emailEnabled` появляется в схеме само.

## Вне объёма

- Верификация email (`email_verified_at` и флоу подтверждения) — отклонена явно.
- UI мобильного приложения — отдельная команда, здесь только контракт API.
- Раскатка фичи в Aladdin/Lastochka/Electrohub через sync-core. Фича создаёт временное расхождение с правилом «код форков 1:1»; перенос делается отдельной командой.
