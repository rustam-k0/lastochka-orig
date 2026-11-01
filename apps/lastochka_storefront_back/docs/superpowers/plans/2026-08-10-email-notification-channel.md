# Канал уведомлений email (`email_enabled`) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить пользователям витрины канальный флаг `email_enabled` по аналогии с `push_enabled` и довести канал до реальной отправки писем по заказам и промо-анонсам.

**Architecture:** Зеркало существующего push-канала. Новый `EmailNotificationService` повторяет сигнатуру `FirebaseNotificationService::sendToUser()`, гейт-метод `User::canReceiveEmail()` встаёт рядом с `canReceivePush()`, ветвление каналов добавляется в три существующие джобы уведомлений. Промо-рассылка вынесена в отдельную джобу, чтобы падение почты не ретраило bulk insert in-app уведомлений.

**Tech Stack:** PHP 8.4, Laravel 12, Sanctum, Scramble (OpenAPI), PHPUnit (SQLite in-memory), очередь `database`.

**Спека:** `docs/superpowers/specs/2026-08-10-email-notification-channel-design.md`

## Global Constraints

- Модуль: `mendev_storefront_back`. Другие модули не трогаем.
- Дефолт `email_enabled` — `false` (канал opt-in). В коде читать как `?? false` — fail-safe, в отличие от `push_enabled ?? true`.
- Письмо уходит только при выполнении трёх условий: `email_enabled = true`, тип-флаг (`orders_enabled` / `promo_enabled`) включён, `users.email` заполнен и проходит `FILTER_VALIDATE_EMAIL`.
- Верификацию email не добавляем — колонки `email_verified_at` в проекте нет и не появится.
- Существующие каналы (in-app, push) не менять: правки только добавляют email-ветку.
- Отправка через `Mail::to(...)->queue(...)`, не `send()`.
- Все обращения к почте в джобах обёрнуты в `try/catch \Throwable` — у джоб `$tries = 3`, непойманное исключение задвоило бы in-app и push.
- Тесты запускать локально: `/opt/homebrew/opt/php@8.4/bin/php artisan test`. `phpunit.xml` уже даёт `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync` — удалённо тесты не гонять.
- Эндпоинт настроек — `PATCH /api/v1/users/me/notification-settings` (не PUT).
- Работа в ветке `feature/email-notification-channel` от `main`. Ветку не удалять после мержа.
- Коммиты в конце каждой задачи выполнять **только после явной команды пользователя** — шаги «Commit» подготовлены, но не выполняются автоматически.

---

## Файловая структура

**Создаются:**

| Файл | Ответственность |
|---|---|
| `database/migrations/2026_08_10_120000_add_email_enabled_to_users_table.php` | колонка `users.email_enabled` |
| `config/notifications.php` | шаблон ссылки на заказ для письма |
| `app/Mail/UserNotificationMail.php` | generic Mailable для всех типов уведомлений |
| `resources/views/emails/user-notification.blade.php` | вёрстка письма |
| `app/Services/EmailNotificationService.php` | отправка письма пользователю + проверка канала |
| `app/Jobs/SendAnnouncementEmails.php` | рассылка промо-анонса по email |
| `tests/Unit/UserCanReceiveEmailTest.php` | матрица гейта `canReceiveEmail()` |
| `tests/Feature/EmailNotificationServiceTest.php` | поведение сервиса |
| `tests/Feature/AnnouncementEmailTest.php` | выборка получателей промо-рассылки |

**Изменяются:**

| Файл | Что |
|---|---|
| `app/Models/User.php` | `$fillable`, `casts()`, `canReceiveEmail()`, `hasDeliverableEmail()` |
| `app/Http/Resources/Api/V1/NotificationSettingsResource.php` | поле `emailEnabled` |
| `app/Http/Requests/UpdateNotificationSettingsRequest.php` | правило, маппинг, сообщение |
| `app/Http/Controllers/Api/V1/NotificationSettingsController.php` | PHPDoc (источник для Scramble) |
| `app/Jobs/SendOrderStatusNotification.php` | email-ветка |
| `app/Jobs/SendReceiptReadyNotification.php` | email-ветка |
| `app/Jobs/SendSurchargeRequiredNotification.php` | email-ветка |
| `app/Jobs/SaveAnnouncementNotifications.php` | диспатч `SendAnnouncementEmails` |
| `tests/Feature/NotificationSettingsTest.php` | покрытие `emailEnabled` |
| `tests/Feature/NotificationGateTest.php` | покрытие email-гейта |
| `.env.example` | `NOTIFICATION_EMAIL_ORDER_URL` |

---

### Task 0: Подготовка ветки

**Files:** нет изменений в коде.

- [ ] **Step 1: Создать ветку от актуального main**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_storefront_back
git checkout main
git pull
git checkout -b feature/email-notification-channel
```

- [ ] **Step 2: Убедиться, что тесты на старте зелёные**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=Notification`
Expected: PASS — существующие `NotificationSettingsTest`, `NotificationGateTest`, `NotificationReadAllTest` проходят. Это базовая линия: любые падения после наших правок будут наши.

---

### Task 1: Колонка `email_enabled` и гейт в модели

**Files:**
- Create: `database/migrations/2026_08_10_120000_add_email_enabled_to_users_table.php`
- Create: `tests/Unit/UserCanReceiveEmailTest.php`
- Modify: `app/Models/User.php` (`$fillable` ~строка 44, `casts()` ~строка 66, блок «Notification settings» ~строки 188-214)

**Interfaces:**
- Consumes: `App\Enums\NotificationType` (кейсы `Order`, `Promo`), существующий `User::isNotificationTypeEnabled(NotificationType): bool`
- Produces: `User::canReceiveEmail(NotificationType $type): bool`, `User::hasDeliverableEmail(): bool`, колонка `users.email_enabled` (boolean, default `false`)

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Unit/UserCanReceiveEmailTest.php`. Тест лежит в `tests/Unit`, но пишет в БД (нужна колонка и касты), поэтому использует `RefreshDatabase` — так же, как feature-тесты модуля.

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserCanReceiveEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_disabled_by_default(): void
    {
        // fresh(): дефолт живёт в колонке БД, factory()->create() строку не перечитывает.
        $user = User::factory()->create()->fresh();

        $this->assertFalse($user->email_enabled);
        $this->assertFalse($user->canReceiveEmail(NotificationType::Order));
    }

    public function test_returns_true_when_channel_type_and_address_are_ok(): void
    {
        $user = User::factory()->create([
            'email' => 'client@example.com',
            'email_enabled' => true,
            'orders_enabled' => true,
        ]);

        $this->assertTrue($user->canReceiveEmail(NotificationType::Order));
    }

    public function test_returns_false_when_type_flag_disabled(): void
    {
        $user = User::factory()->create([
            'email' => 'client@example.com',
            'email_enabled' => true,
            'promo_enabled' => false,
        ]);

        $this->assertFalse($user->canReceiveEmail(NotificationType::Promo));
    }

    public function test_returns_false_when_address_missing(): void
    {
        $user = User::factory()->create([
            'email' => null,
            'email_enabled' => true,
            'orders_enabled' => true,
        ]);

        $this->assertFalse($user->hasDeliverableEmail());
        $this->assertFalse($user->canReceiveEmail(NotificationType::Order));
    }

    public function test_returns_false_when_address_malformed(): void
    {
        $user = User::factory()->create([
            'email' => 'не-адрес',
            'email_enabled' => true,
            'orders_enabled' => true,
        ]);

        $this->assertFalse($user->hasDeliverableEmail());
        $this->assertFalse($user->canReceiveEmail(NotificationType::Order));
    }

    public function test_unset_attribute_is_treated_as_disabled(): void
    {
        // Модель без загруженного атрибута: email_enabled === null.
        // Проверяем fail-safe ветку `?? false` — в отличие от push (`?? true`).
        $user = new User(['email' => 'client@example.com']);

        $this->assertNull($user->email_enabled);
        $this->assertFalse($user->canReceiveEmail(NotificationType::Order));
    }
}
```

- [ ] **Step 2: Запустить тест и убедиться, что он падает**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=UserCanReceiveEmailTest`
Expected: FAIL — «Call to undefined method App\Models\User::canReceiveEmail()» либо ошибка про отсутствующую колонку `email_enabled`.

- [ ] **Step 3: Создать миграцию**

Файл `database/migrations/2026_08_10_120000_add_email_enabled_to_users_table.php`. Guard `Schema::hasColumn` — по образцу `2026_05_11_131058_add_notification_settings_to_users_table.php`: на форках миграция может встретить уже существующую колонку.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'email_enabled')) {
                $table->boolean('email_enabled')->default(false)->after('push_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'email_enabled')) {
                $table->dropColumn('email_enabled');
            }
        });
    }
};
```

- [ ] **Step 4: Добавить поле в модель**

В `app/Models/User.php` в `$fillable` после `'push_enabled',`:

```php
        'email_enabled',
```

В `casts()` после `'push_enabled' => 'boolean',`:

```php
            'email_enabled' => 'boolean',
```

- [ ] **Step 5: Добавить гейт-методы**

В `app/Models/User.php` в конец блока «Notification settings», сразу после `canReceivePush()`:

```php
    /**
     * Можно ли слать email конкретного типа пользователю.
     * Email требует И общий тоггл email_enabled, И тип-тоггл, И пригодный адрес.
     *
     * В отличие от push, канал opt-in: неизвестное значение читаем как «нельзя».
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

- [ ] **Step 6: Запустить тест и убедиться, что он проходит**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=UserCanReceiveEmailTest`
Expected: PASS, 6 тестов.

- [ ] **Step 7: Прогнать существующие тесты уведомлений**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=Notification`
Expected: PASS — новая колонка ничего не сломала.

- [ ] **Step 8: Commit** (только по явной команде пользователя)

```bash
git add database/migrations/2026_08_10_120000_add_email_enabled_to_users_table.php app/Models/User.php tests/Unit/UserCanReceiveEmailTest.php
git commit -m "feat: add email_enabled flag and canReceiveEmail gate to user"
```

---

### Task 2: Флаг в API настроек уведомлений

**Files:**
- Modify: `app/Http/Resources/Api/V1/NotificationSettingsResource.php`
- Modify: `app/Http/Requests/UpdateNotificationSettingsRequest.php`
- Modify: `app/Http/Controllers/Api/V1/NotificationSettingsController.php`
- Modify: `tests/Feature/NotificationSettingsTest.php`

**Interfaces:**
- Consumes: `User::$fillable` с `email_enabled` из Task 1
- Produces: поле `emailEnabled` в ответе `GET|PATCH /api/v1/users/me/notification-settings` и в запросе `PATCH`

- [ ] **Step 1: Написать падающие тесты**

В `tests/Feature/NotificationSettingsTest.php` в существующий тест `test_show_returns_defaults_for_fresh_user` добавить строку после `->assertJsonPath('data.pushEnabled', true);`:

```php
            ->assertJsonPath('data.emailEnabled', false);
```

В `test_show_returns_persisted_flags` — в массив создания пользователя добавить `'email_enabled' => true,`, и после проверки `pushEnabled` добавить:

```php
            ->assertJsonPath('data.emailEnabled', true);
```

В `test_update_modifies_only_provided_fields` — в массив создания добавить `'email_enabled' => false,`, к проверкам ответа добавить `->assertJsonPath('data.emailEnabled', false);`, а к проверкам модели после `$user->refresh();`:

```php
        $this->assertFalse($user->email_enabled);
```

Переименовать `test_update_accepts_all_three_fields` в `test_update_accepts_all_four_fields` и дописать четвёртое поле:

```php
    public function test_update_accepts_all_four_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/users/me/notification-settings', [
            'ordersEnabled' => false,
            'promoEnabled' => false,
            'pushEnabled' => false,
            'emailEnabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.ordersEnabled', false)
            ->assertJsonPath('data.promoEnabled', false)
            ->assertJsonPath('data.pushEnabled', false)
            ->assertJsonPath('data.emailEnabled', true);

        $this->assertTrue($user->refresh()->email_enabled);
    }
```

Добавить два новых теста в конец класса:

```php
    public function test_update_validates_email_enabled_type(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/users/me/notification-settings', [
            'emailEnabled' => 'not-a-bool',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['emailEnabled']);
    }

    public function test_email_enabled_can_be_turned_on_without_address(): void
    {
        $user = User::factory()->empty()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/users/me/notification-settings', [
            'emailEnabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.emailEnabled', true);

        $this->assertTrue($user->refresh()->email_enabled);
    }
```

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=NotificationSettingsTest`
Expected: FAIL — «Property [data.emailEnabled] does not exist» и отсутствие ошибки валидации для `emailEnabled`.

- [ ] **Step 3: Добавить поле в ресурс**

`app/Http/Resources/Api/V1/NotificationSettingsResource.php` — обновить PHPDoc возврата и добавить поле:

```php
    /**
     * @return array{ordersEnabled: bool, promoEnabled: bool, pushEnabled: bool, emailEnabled: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'ordersEnabled' => (bool) ($this->orders_enabled ?? true),
            'promoEnabled' => (bool) ($this->promo_enabled ?? true),
            'pushEnabled' => (bool) ($this->push_enabled ?? true),
            'emailEnabled' => (bool) ($this->email_enabled ?? false),
        ];
    }
```

- [ ] **Step 4: Добавить поле в Form Request**

`app/Http/Requests/UpdateNotificationSettingsRequest.php` — три места. В `rules()` после `pushEnabled`:

```php
            'emailEnabled' => ['sometimes', 'boolean'],
```

В `settings()` в массив `$map` после `'pushEnabled' => 'push_enabled',`:

```php
            'emailEnabled' => 'email_enabled',
```

В `messages()` после сообщения про push:

```php
            'emailEnabled.boolean' => 'Поле «Email-уведомления» должно быть true или false.',
```

- [ ] **Step 5: Обновить PHPDoc контроллера**

`app/Http/Controllers/Api/V1/NotificationSettingsController.php` — из этих блоков Scramble генерирует `api.json`. В `show()`:

```php
    /**
     * Получить настройки уведомлений
     *
     * Возвращает четыре пользовательских флага: уведомления о заказах,
     * уведомления об акциях, общий push-канал и email-канал.
     *
     * @response array{data: NotificationSettingsResource}
     */
```

В `update()`:

```php
    /**
     * Обновить настройки уведомлений
     *
     * Принимает любые из ordersEnabled / promoEnabled / pushEnabled / emailEnabled.
     * Поля, отсутствующие в запросе, не изменяются; пустое тело оставляет настройки без изменений.
     * emailEnabled можно включить без заполненного email — письма начнут уходить,
     * когда пользователь укажет адрес в профиле.
     *
     * @response array{data: NotificationSettingsResource}
     */
```

- [ ] **Step 6: Запустить тесты и убедиться, что они проходят**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=NotificationSettingsTest`
Expected: PASS.

- [ ] **Step 7: Проверить OpenAPI-контракт**

Scramble отдаёт документацию на лету по роуту `/docs/api`, а `api.json` лежит в `.gitignore` и в репозиторий не попадает — отдельного шага деплоя контракта нет. Экспорт нужен только чтобы убедиться глазами, что поле попало в схему:

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan scramble:export && grep -c emailEnabled api.json`
Expected: число больше нуля (поле присутствует в схемах запроса и ответа). Файл `api.json` не коммитить.

- [ ] **Step 8: Commit** (только по явной команде пользователя)

```bash
git add app/Http/Resources/Api/V1/NotificationSettingsResource.php app/Http/Requests/UpdateNotificationSettingsRequest.php app/Http/Controllers/Api/V1/NotificationSettingsController.php tests/Feature/NotificationSettingsTest.php
git commit -m "feat: expose emailEnabled in notification settings api"
```

---

### Task 3: Письмо и сервис отправки

**Files:**
- Create: `config/notifications.php`
- Create: `app/Mail/UserNotificationMail.php`
- Create: `resources/views/emails/user-notification.blade.php`
- Create: `app/Services/EmailNotificationService.php`
- Create: `tests/Feature/EmailNotificationServiceTest.php`
- Modify: `.env.example`

**Interfaces:**
- Consumes: `User::canReceiveEmail()`, `User::hasDeliverableEmail()` из Task 1
- Produces:
  - `EmailNotificationService::sendToUser(User $user, array $notification, array $data = []): array` — `$notification` в формате `['title' => string, 'body' => string]`, `$data` — тот же payload, что уходит в push (может содержать `order_id`). Возврат: `['sent' => int, 'skipped' => string|null]`, где `skipped` ∈ `null | 'email_disabled' | 'no_email'`.
  - `UserNotificationMail(string $title, string $body, ?string $orderUrl = null)`

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Feature/EmailNotificationServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\UserNotificationMail;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class EmailNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EmailNotificationService
    {
        return app(EmailNotificationService::class);
    }

    public function test_queues_mail_for_eligible_user(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'client@example.com',
            'email_enabled' => true,
            'orders_enabled' => true,
        ]);

        $result = $this->service()->sendToUser(
            $user,
            ['title' => 'Заказ собран', 'body' => 'Ваш заказ №7 собран.'],
            ['order_id' => '7'],
        );

        $this->assertSame(1, $result['sent']);
        $this->assertNull($result['skipped']);

        Mail::assertQueued(UserNotificationMail::class, function (UserNotificationMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->title === 'Заказ собран';
        });
    }

    public function test_skips_when_channel_disabled(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'client@example.com',
            'email_enabled' => false,
        ]);

        $result = $this->service()->sendToUser($user, ['title' => 'T', 'body' => 'B']);

        $this->assertSame(0, $result['sent']);
        $this->assertSame('email_disabled', $result['skipped']);
        Mail::assertNothingQueued();
    }

    public function test_skips_when_address_missing(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => null,
            'email_enabled' => true,
        ]);

        $result = $this->service()->sendToUser($user, ['title' => 'T', 'body' => 'B']);

        $this->assertSame(0, $result['sent']);
        $this->assertSame('no_email', $result['skipped']);
        Mail::assertNothingQueued();
    }

    public function test_builds_order_url_from_config_template(): void
    {
        Mail::fake();
        config(['notifications.email.order_url_template' => 'https://shop.test/orders/{id}']);

        $user = User::factory()->create([
            'email' => 'client@example.com',
            'email_enabled' => true,
        ]);

        $this->service()->sendToUser($user, ['title' => 'T', 'body' => 'B'], ['order_id' => '42']);

        Mail::assertQueued(UserNotificationMail::class, function (UserNotificationMail $mail) {
            return $mail->orderUrl === 'https://shop.test/orders/42';
        });
    }

    public function test_order_url_is_null_without_template_or_order_id(): void
    {
        Mail::fake();
        config(['notifications.email.order_url_template' => null]);

        $user = User::factory()->create([
            'email' => 'client@example.com',
            'email_enabled' => true,
        ]);

        $this->service()->sendToUser($user, ['title' => 'T', 'body' => 'B'], ['order_id' => '42']);

        Mail::assertQueued(UserNotificationMail::class, fn (UserNotificationMail $mail) => $mail->orderUrl === null);
    }
}
```

- [ ] **Step 2: Запустить тест и убедиться, что он падает**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=EmailNotificationServiceTest`
Expected: FAIL — «Target class [App\Services\EmailNotificationService] does not exist».

- [ ] **Step 3: Создать конфиг**

`config/notifications.php`:

```php
<?php

declare(strict_types=1);

return [

    'email' => [

        /*
         * Шаблон ссылки на заказ для письма. Подстановка {id}.
         * У storefront_back в конфиге есть только APP_URL — адрес самого API,
         * домена витрины модуль не знает, а push-овый deep_link (order/17)
         * в письме бесполезен. Не задан — письмо уходит без кнопки.
         */
        'order_url_template' => env('NOTIFICATION_EMAIL_ORDER_URL'),

    ],

];
```

В `.env.example` после блока `MAIL_*` добавить:

```
NOTIFICATION_EMAIL_ORDER_URL=
```

- [ ] **Step 4: Создать Mailable**

`app/Mail/UserNotificationMail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо-уведомление пользователю витрины. Один Mailable на все типы:
 * заголовок и текст приходят готовыми из джоб — теми же, что уходят в push.
 */
final class UserNotificationMail extends Mailable
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $orderUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-notification',
            with: [
                'title' => $this->title,
                'body' => $this->body,
                'orderUrl' => $this->orderUrl,
            ],
        );
    }
}
```

- [ ] **Step 5: Создать шаблон письма**

`resources/views/emails/user-notification.blade.php` — стиль как у `emails/vacancy-response.blade.php`: обычный blade, inline-стили, без markdown-mail.

```blade
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #222;">
<h2 style="margin: 0 0 16px;">{{ $title }}</h2>

<p style="margin: 0 0 16px; line-height: 1.5;">{{ $body }}</p>

@if ($orderUrl)
    <p style="margin: 0 0 16px;">
        <a href="{{ $orderUrl }}"
           style="display: inline-block; padding: 10px 20px; background: #222; color: #fff; text-decoration: none; border-radius: 4px;">
            Перейти к заказу
        </a>
    </p>
@endif

<p style="margin: 24px 0 0; color: #888; font-size: 12px;">
    Вы получили это письмо, потому что включили email-уведомления в настройках приложения.
</p>
</body>
</html>
```

- [ ] **Step 6: Создать сервис**

`app/Services/EmailNotificationService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\UserNotificationMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Отправка email-уведомлений пользователю витрины.
 * Сигнатура зеркалит FirebaseNotificationService::sendToUser().
 */
final class EmailNotificationService
{
    /**
     * @param  array<string, string>  $notification  ['title' => ..., 'body' => ...]
     * @param  array<string, mixed>  $data  тот же payload, что уходит в push
     * @return array{sent: int, skipped: string|null}
     */
    public function sendToUser(User $user, array $notification, array $data = []): array
    {
        if (! ($user->email_enabled ?? false)) {
            Log::info('Email notification skipped: user disabled email channel', [
                'user_id' => $user->id,
            ]);

            return ['sent' => 0, 'skipped' => 'email_disabled'];
        }

        if (! $user->hasDeliverableEmail()) {
            Log::info('Email notification skipped: user has no deliverable address', [
                'user_id' => $user->id,
            ]);

            return ['sent' => 0, 'skipped' => 'no_email'];
        }

        $title = $notification['title'] ?? 'Уведомление';
        $body = $notification['body'] ?? '';

        Mail::to($user->email)->queue(
            new UserNotificationMail($title, $body, $this->resolveOrderUrl($data))
        );

        Log::info('Email notification queued', [
            'user_id' => $user->id,
            'title' => $title,
        ]);

        return ['sent' => 1, 'skipped' => null];
    }

    /**
     * Ссылка на заказ для кнопки в письме.
     * Нет шаблона в конфиге или order_id в payload — кнопки не будет.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveOrderUrl(array $data): ?string
    {
        $template = config('notifications.email.order_url_template');
        $orderId = $data['order_id'] ?? null;

        if (! $template || ! $orderId) {
            return null;
        }

        return str_replace('{id}', (string) $orderId, (string) $template);
    }
}
```

- [ ] **Step 7: Запустить тест и убедиться, что он проходит**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=EmailNotificationServiceTest`
Expected: PASS, 5 тестов.

- [ ] **Step 8: Commit** (только по явной команде пользователя)

```bash
git add config/notifications.php app/Mail/UserNotificationMail.php resources/views/emails/user-notification.blade.php app/Services/EmailNotificationService.php tests/Feature/EmailNotificationServiceTest.php .env.example
git commit -m "feat: add email notification service and mailable"
```

---

### Task 4: Email в джобах уведомлений о заказах

**Files:**
- Modify: `app/Jobs/SendOrderStatusNotification.php` (сигнатура `handle()` ~строка 31, блок после push ~строки 71-86)
- Modify: `app/Jobs/SendReceiptReadyNotification.php` (сигнатура `handle()` ~строка 31, блок после push)
- Modify: `app/Jobs/SendSurchargeRequiredNotification.php` (сигнатура `handle()` ~строка 27, блок после push)
- Modify: `tests/Feature/NotificationGateTest.php`

**Interfaces:**
- Consumes: `EmailNotificationService::sendToUser()` из Task 3, `User::canReceiveEmail()` из Task 1
- Produces: письма по всем трём событиям заказа (смена статуса, готовность чека, требуется доплата)

- [ ] **Step 1: Написать падающие тесты**

В `tests/Feature/NotificationGateTest.php` добавить импорты в шапку:

```php
use App\Mail\UserNotificationMail;
use Illuminate\Support\Facades\Mail;
```

Важно: `handle()` резолвит `FirebaseNotificationService` через контейнер даже когда push выключен, а `Messaging::class` в `AppServiceProvider` биндится на реальный `Kreait\Firebase\Factory` с credentials из БД. Поэтому каждый тест обязан подменить сервис моком — как это делают существующие тесты в этом же классе. Добавить приватный хелпер в класс:

```php
    private function mockFirebaseSilent(): void
    {
        $firebase = \Mockery::mock(FirebaseNotificationService::class);
        $firebase->shouldReceive('sendToUser')->zeroOrMoreTimes()
            ->andReturn(['success' => 0, 'failure' => 0, 'total' => 0]);
        $this->app->instance(FirebaseNotificationService::class, $firebase);
    }
```

И добавить в конец класса четыре теста:

```php
    // ==================== email gate (SendOrderStatusNotification) ====================

    public function test_email_is_sent_when_channel_and_type_enabled(): void
    {
        Mail::fake();
        $this->mockFirebaseSilent();

        $user = User::factory()->create([
            'orders_enabled' => true,
            'push_enabled' => false,
            'email_enabled' => true,
            'email' => 'client@example.com',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Pending,
            'shipping_method' => ShippingMethod::Courier,
            'payment_type' => PaymentType::Card,
            'total' => 1000,
        ]);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembling);

        Mail::assertQueued(UserNotificationMail::class);
    }

    public function test_email_is_skipped_when_channel_disabled(): void
    {
        Mail::fake();
        $this->mockFirebaseSilent();

        $user = User::factory()->create([
            'orders_enabled' => true,
            'push_enabled' => false,
            'email_enabled' => false,
            'email' => 'client@example.com',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Pending,
            'shipping_method' => ShippingMethod::Courier,
            'payment_type' => PaymentType::Card,
            'total' => 1000,
        ]);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembling);

        Mail::assertNothingQueued();
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'type' => 'order',
        ]);
    }

    public function test_email_is_skipped_when_user_has_no_address(): void
    {
        Mail::fake();
        $this->mockFirebaseSilent();

        $user = User::factory()->create([
            'orders_enabled' => true,
            'push_enabled' => false,
            'email_enabled' => true,
            'email' => null,
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Pending,
            'shipping_method' => ShippingMethod::Courier,
            'payment_type' => PaymentType::Card,
            'total' => 1000,
        ]);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembling);

        Mail::assertNothingQueued();
    }

    public function test_email_is_skipped_when_order_type_disabled(): void
    {
        Mail::fake();
        $this->mockFirebaseSilent();

        $user = User::factory()->create([
            'orders_enabled' => false,
            'push_enabled' => false,
            'email_enabled' => true,
            'email' => 'client@example.com',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Pending,
            'shipping_method' => ShippingMethod::Courier,
            'payment_type' => PaymentType::Card,
            'total' => 1000,
        ]);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembling);

        Mail::assertNothingQueued();
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $user->id,
            'type' => 'order',
        ]);
    }
```

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=NotificationGateTest`
Expected: FAIL — `test_email_is_sent_when_channel_and_type_enabled` падает с «The expected [App\Mail\UserNotificationMail] mailable was not queued».

- [ ] **Step 3: Подключить email в `SendOrderStatusNotification`**

Добавить импорт:

```php
use App\Services\EmailNotificationService;
```

Изменить сигнатуру `handle()`:

```php
    public function handle(
        FirebaseNotificationService $firebaseNotificationService,
        EmailNotificationService $emailNotificationService,
    ): void {
```

Внутри `handle()` `$dataPayload` сейчас объявлен внутри `if (canReceivePush(...))` — его нужно поднять выше, чтобы им пользовались оба канала. Заменить существующий блок push на:

```php
        $dataPayload = [
            'type' => 'order_status_changed',
            'order_id' => (string) $order->id,
            'status' => $this->newStatus->value,
            'action' => 'open_order_details',
            'notification_id' => (string) $notification->id,
        ];

        // Push шлём только если общий push-канал включён.
        if ($order->user->canReceivePush(NotificationType::Order)) {
            $firebaseNotificationService->sendToUser(
                $order->user,
                $notificationText,
                $dataPayload
            );
        }

        // Email — независимый канал со своим тогглом и требованием адреса.
        if ($order->user->canReceiveEmail(NotificationType::Order)) {
            try {
                $emailNotificationService->sendToUser(
                    $order->user,
                    $notificationText,
                    $dataPayload
                );
            } catch (\Throwable $e) {
                Log::warning('order-status email failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
```

- [ ] **Step 4: Подключить email в `SendReceiptReadyNotification`**

Добавить импорт `use App\Services\EmailNotificationService;`, изменить сигнатуру:

```php
    public function handle(
        FirebaseNotificationService $firebaseNotificationService,
        EmailNotificationService $emailNotificationService,
    ): void {
```

`$dataPayload` там тоже объявлен внутри push-блока — поднять его перед `if`, затем сразу за push-блоком (и строго **до** `$receipt->update(['notified_at' => now()])`, чтобы идемпотентность чека не изменилась) вставить:

```php
        if ($user->canReceiveEmail(NotificationType::Order)) {
            try {
                $emailNotificationService->sendToUser(
                    $user,
                    ['title' => $title, 'body' => $body],
                    $dataPayload
                );
            } catch (\Throwable $e) {
                Log::warning('receipt-ready email failed', [
                    'receipt' => $receipt->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
```

- [ ] **Step 5: Подключить email в `SendSurchargeRequiredNotification`**

Добавить импорт `use App\Services\EmailNotificationService;`, изменить сигнатуру:

```php
    public function handle(
        FirebaseNotificationService $firebaseNotificationService,
        EmailNotificationService $emailNotificationService,
    ): void {
```

Payload там передаётся литералом прямо в вызов push — вынести его в переменную перед `if (canReceivePush(...))`:

```php
        $dataPayload = [
            'type' => 'order_surcharge_required',
            'order_id' => (string) $order->id,
            'action' => 'open_order_details',
            'notification_id' => (string) $notification->id,
        ];
```

Соответственно в вызове `$firebaseNotificationService->sendToUser()` третьим аргументом подставить `$dataPayload`, а после push-блока добавить:

```php
        if ($order->user->canReceiveEmail(NotificationType::Order)) {
            try {
                $emailNotificationService->sendToUser(
                    $order->user,
                    ['title' => $title, 'body' => $body],
                    $dataPayload
                );
            } catch (\Throwable $e) {
                Log::warning('surcharge-required email failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
```

- [ ] **Step 6: Запустить тесты и убедиться, что они проходят**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=NotificationGateTest`
Expected: PASS — 7 тестов (3 существующих + 4 новых).

- [ ] **Step 7: Прогнать тесты чеков**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=ReceiptReadyPushTest`
Expected: PASS — правка `SendReceiptReadyNotification` не сломала существующее покрытие push.

- [ ] **Step 8: Commit** (только по явной команде пользователя)

```bash
git add app/Jobs/SendOrderStatusNotification.php app/Jobs/SendReceiptReadyNotification.php app/Jobs/SendSurchargeRequiredNotification.php tests/Feature/NotificationGateTest.php
git commit -m "feat: send order notifications over email channel"
```

---

### Task 5: Промо-рассылка анонсов по email

**Files:**
- Create: `app/Jobs/SendAnnouncementEmails.php`
- Create: `tests/Feature/AnnouncementEmailTest.php`
- Modify: `app/Jobs/SaveAnnouncementNotifications.php` (конец `handle()`)

**Interfaces:**
- Consumes: `EmailNotificationService::sendToUser()` из Task 3, `User::canReceiveEmail()` из Task 1
- Produces: `SendAnnouncementEmails::dispatch(array $announcementData)` — принимает тот же массив, что и `SaveAnnouncementNotifications`

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Feature/AnnouncementEmailTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SaveAnnouncementNotifications;
use App\Mail\UserNotificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AnnouncementEmailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function announcement(): array
    {
        return [
            'title' => 'Скидки недели',
            'short_description' => 'Коротко',
            'body' => 'Подробности акции',
            'image_url' => null,
            'promo_code' => null,
            'announcement_id' => 42,
        ];
    }

    public function test_email_goes_only_to_users_with_all_conditions_met(): void
    {
        Mail::fake();

        $recipient = User::factory()->create([
            'promo_enabled' => true,
            'email_enabled' => true,
            'email' => 'yes@example.com',
        ]);

        // Промо выключено.
        User::factory()->create([
            'promo_enabled' => false,
            'email_enabled' => true,
            'email' => 'promo-off@example.com',
        ]);

        // Email-канал выключен.
        User::factory()->create([
            'promo_enabled' => true,
            'email_enabled' => false,
            'email' => 'channel-off@example.com',
        ]);

        // Адреса нет.
        User::factory()->create([
            'promo_enabled' => true,
            'email_enabled' => true,
            'email' => null,
        ]);

        SaveAnnouncementNotifications::dispatchSync($this->announcement());

        Mail::assertQueuedCount(1);
        Mail::assertQueued(
            UserNotificationMail::class,
            fn (UserNotificationMail $mail) => $mail->hasTo($recipient->email)
        );
    }

    public function test_announcement_email_has_no_order_button(): void
    {
        Mail::fake();
        config(['notifications.email.order_url_template' => 'https://shop.test/orders/{id}']);

        User::factory()->create([
            'promo_enabled' => true,
            'email_enabled' => true,
            'email' => 'yes@example.com',
        ]);

        SaveAnnouncementNotifications::dispatchSync($this->announcement());

        Mail::assertQueued(
            UserNotificationMail::class,
            fn (UserNotificationMail $mail) => $mail->orderUrl === null
        );
    }

    public function test_in_app_notifications_are_still_created(): void
    {
        Mail::fake();

        User::factory()->count(2)->create([
            'promo_enabled' => true,
            'email_enabled' => false,
        ]);

        SaveAnnouncementNotifications::dispatchSync($this->announcement());

        $this->assertDatabaseCount('user_notifications', 2);
    }
}
```

- [ ] **Step 2: Запустить тест и убедиться, что он падает**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=AnnouncementEmailTest`
Expected: FAIL — `Mail::assertQueuedCount(1)` получает 0, писем никто не шлёт.

- [ ] **Step 3: Создать джобу рассылки**

`app/Jobs/SendAnnouncementEmails.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Рассылка промо-анонса по email.
 *
 * Отдельная джоба, а не часть SaveAnnouncementNotifications: та пишет
 * UserNotification::insert() пачками без загрузки моделей, и при её трёх
 * попытках падение почты задваивало бы in-app уведомления по всей базе.
 */
final class SendAnnouncementEmails implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array{title: string, short_description: string, body: string|null, image_url: string|null, promo_code: string|null, announcement_id: string|int|null}  $announcementData
     */
    public function __construct(
        private array $announcementData,
    ) {}

    public function handle(EmailNotificationService $emailNotificationService): void
    {
        $title = $this->announcementData['title'];
        $body = $this->announcementData['body'] ?: $this->announcementData['short_description'];
        $queued = 0;

        User::query()
            ->where('promo_enabled', true)
            ->where('email_enabled', true)
            ->whereNotNull('email')
            ->chunkById(500, function ($users) use ($emailNotificationService, $title, $body, &$queued) {
                foreach ($users as $user) {
                    if (! $user->canReceiveEmail(NotificationType::Promo)) {
                        continue;
                    }

                    try {
                        // Без order_id — в промо-письме кнопки заказа нет.
                        $emailNotificationService->sendToUser($user, [
                            'title' => $title,
                            'body' => $body,
                        ]);
                        $queued++;
                    } catch (\Throwable $e) {
                        Log::warning('announcement email failed', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        Log::info('Announcement emails queued', [
            'title' => $title,
            'users_count' => $queued,
        ]);
    }
}
```

- [ ] **Step 4: Диспатчить рассылку из `SaveAnnouncementNotifications`**

В `app/Jobs/SaveAnnouncementNotifications.php` в самый конец `handle()`, после финального `Log::info('Announcement notifications saved', ...)`:

```php
        SendAnnouncementEmails::dispatch($this->announcementData);
```

- [ ] **Step 5: Запустить тест и убедиться, что он проходит**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=AnnouncementEmailTest`
Expected: PASS, 3 теста.

- [ ] **Step 6: Прогнать весь пакет уведомлений**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test --filter=Notification`
Expected: PASS — включая `NotificationGateTest::test_promo_gate_skips_users_with_promo_disabled` (у фабричных пользователей `email_enabled` по умолчанию `false`, писем нет, счётчик in-app не меняется).

- [ ] **Step 7: Прогнать полный набор тестов модуля**

Run: `/opt/homebrew/opt/php@8.4/bin/php artisan test`
Expected: PASS либо ровно те падения, что были на baseline из Task 0 Step 2. Новых падений быть не должно.

- [ ] **Step 8: Commit** (только по явной команде пользователя)

```bash
git add app/Jobs/SendAnnouncementEmails.php app/Jobs/SaveAnnouncementNotifications.php tests/Feature/AnnouncementEmailTest.php
git commit -m "feat: send promo announcements over email channel"
```

---

## Пререквизиты деплоя

Проверяются после мержа, перед верификацией на dev:

1. Миграция накатана: `php artisan migrate` на dev.
2. `MAIL_*` в `.env` витрины указывают на реальный SMTP. При `MAIL_MAILER=log` письма уйдут в лог — проверить фактическое значение на сервере (read-only) перед тем, как объявлять фичу работающей.
3. `NOTIFICATION_EMAIL_ORDER_URL` — опционально; без него письмо без кнопки.
4. `queue:work` крутится в supervisor (уже настроен) — иначе письма зависнут в таблице `jobs`.
5. Контракт: Scramble генерирует документацию на лету по `/docs/api`, `api.json` в `.gitignore` — после деплоя кода поле `emailEnabled` появляется в схеме само, отдельного шага не требуется.

## Верификация на dev (после автодеплоя)

- `PATCH /api/v1/users/me/notification-settings` с `{"emailEnabled": true}` под токеном тестового пользователя → в ответе `emailEnabled: true`, в БД `users.email_enabled = 1`.
- Провести заказ до статуса «Собран» → проверить, что письмо реально ушло: запись `Email notification queued` в `storage/logs/laravel.log` и отсутствие ошибок SMTP, плюс письмо в ящике тестового адреса.
- Обе стороны проверки обязательны: сначала подтвердить, что предусловие выполнено (флаг включён, адрес заполнен, статус реально сменился), потом — что письмо доставлено.

## Вне объёма

- Верификация email (`email_verified_at`) — отклонена на этапе дизайна.
- UI мобильного приложения.
- Раскатка в Aladdin / Lastochka / Electrohub через sync-core — отдельной командой.

---

## Отклонения от плана при исполнении

Зафиксировано по факту, чтобы план соответствовал коду:

1. **`api.json` не коммитится.** Файл в `.gitignore`, Scramble отдаёт документацию на лету по `/docs/api`. Шаг «регенерация» переформулирован в проверку, из коммита файл убран.

2. **`tests/Feature/Fiscal/ReceiptReadyPushTest.php` — правка вызовов.** Тест вызывает `(new SendReceiptReadyNotification(...))->handle($fake)` напрямую, а не через `dispatchSync`. Расширение сигнатуры `handle()` вторым аргументом сломало 4 теста с `ArgumentCountError`; во все 5 вызовов добавлен `app(EmailNotificationService::class)`.

3. **`tests/Feature/AnnouncementNotificationHandlerTest.php` — сужена проверка очереди.** Тест защищает регрессию LASTOCHKI-JAMI-11020 (push обгонял in-app запись) и проверял это через `assertDatabaseCount('jobs', 0)`. Новая `SendAnnouncementEmails` штатно ложится в `jobs` и роняла тест. Проверка заменена на точечную: `SaveAnnouncementNotifications` не должен откладываться в очередь. Инвариант сохранён — email-рассылка в доставке in-app записи не участвует и обогнать её не может. Синхронный вариант отвергнут: рассылка по всей базе заблокировала бы RabbitMQ-консьюмер, а `Mail::queue()` внутри всё равно пишет в `jobs`.

4. **Добавлены два теста рендеринга шаблона** (`test_template_renders_with_order_button`, `test_template_renders_without_order_button`). `Mail::fake()` не рендерит blade, поэтому битый шаблон обнаружился бы только на проде.

5. **`test_email_disabled_by_default` читает пользователя через `fresh()`** — дефолт живёт в колонке БД, а `factory()->create()` строку не перечитывает, поэтому в модели атрибут остаётся `null`.

## Результат прогона

Baseline на чистом `main`: 2212 тестов, 69 errors, 38 failures, 4 risky.
После реализации: 2234 теста (+22 новых), 69 errors, 38 failures, 4 risky.
Новых падений нет — предсуществующие ошибки модуля (Carbon `$period`, `Product::category()`, Cdek, RabbitMQ) к каналу отношения не имеют.

Полный прогон требует `-d memory_limit=2G` и запуска через `vendor/bin/phpunit`: `artisan test` порождает дочерние процессы, которые флаг не наследуют, и падает на 128M.
