# Фильтр «Выдан» (самовывоз) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Опция «Выдан» в фильтре статусов страницы «Заказы» АП: виртуальный код 7 = завершённый самовывоз (`status_id=5 AND type_id=1`), «Доставлен» (5) перестаёт включать самовывозы.

**Architecture:** Фронт шлёт виртуальный код 7 в существующий аргумент `statuses:[Int]` запроса `admin_orders_paginate`; бэкенд разворачивает 5/7 в условия по `status_id`+`type_id` внутри `OrderFilter::statuses()`. БД, события, другие сервисы не затрагиваются. Спека: `docs/superpowers/specs/2026-07-18-pickup-issued-filter-design.md`.

**Tech Stack:** Laravel 9 / PHP 8.1 (purchase_back, GraphQL Lighthouse 5.31), Vue 3 Options API (admin_front).

## Global Constraints

- PHPUnit гонять ТОЛЬКО на локальной машине (`DB_HOST=127.0.0.1 vendor/bin/phpunit <один файл>`); НИКОГДА не на сервере/в поде. phpunit.xml НЕ переопределяет DB — тесты идут в БД из `.env`.
- phpunit docker-модулей принимает только ПЕРВЫЙ path-аргумент — файлы гонять по одному.
- Тесты только с `DatabaseTransactions` (НЕ `RefreshDatabase`).
- Коммиты только в feature-ветку `feature/pickup-issued-filter`; merge в master и push — ТОЛЬКО по явной команде пользователя.
- Без Co-Authored-By в коммитах (атрибуция отключена глобально).
- Семантика фильтра (утверждена): `[7]` → `status_id=5 AND type_id=1`; `[5]` → `status_id=5 AND (type_id!=1 OR type_id IS NULL)`; `[5,7]` → `status_id=5`; прочие коды → `whereIn` как раньше.

---

### Task 1: Бэкенд — OrderFilter::statuses() с виртуальным кодом 7

**Files:**
- Modify: `mendev_purchase_back/app/Filters/OrderFilter.php:43-46`
- Modify: `mendev_purchase_back/graphql/Purchase/Order/OrderQueries.graphql:3`
- Test: `mendev_purchase_back/tests/Feature/OrderIssuedStatusFilterTest.php` (создать)

**Interfaces:**
- Consumes: `Status::ORDER_FINISHED = 5`, `Status::ORDER_ASSEMBLED = 3`, `Status::ORDER_CANCELLED = 6` (`app/Models/Status.php`), `Type::RECEIVE_PICKUP = 1`, `Type::RECEIVE_DELIVERY = 2` (`app/Models/Type.php`), `Order::factory()` (`database/factories/OrderFactory.php`).
- Produces: контракт для фронта — аргумент `statuses:[Int]` запроса `admin_orders_paginate` принимает виртуальный код `OrderFilter::FILTER_ISSUED = 7`.

- [ ] **Step 1: Создать ветку**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_purchase_back
git checkout -b feature/pickup-issued-filter
```

- [ ] **Step 2: Написать падающий тест**

Создать `tests/Feature/OrderIssuedStatusFilterTest.php` (хелперы скопированы 1:1 из прецедента `tests/Feature/OrderPaymentStatusFilterTest.php`, только префиксы `opsf`→`oisf`):

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Фильтр «Выдан» (виртуальный статус 7) в списке заказов АП.
 * Спека: docs/superpowers/specs/2026-07-18-pickup-issued-filter-design.md
 */
class OrderIssuedStatusFilterTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(string $title): int
    {
        return \DB::table('shops')->insertGetId([
            'title' => $title,
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0,
            'is_bonus_enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeClient(): int
    {
        return \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeStaffToken(): string
    {
        $role = Role::findOrCreate(Role::ADMIN, 'api');

        $userId = \DB::table('users')->insertGetId([
            'name' => 'Test',
            'email' => uniqid('oisf_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return $user->createToken('test')->plainTextToken;
    }

    private function gql(string $token, string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    private function makeOrder(int $statusId, int $typeId, string $marker): Order
    {
        $shopId = $this->makeShop('OISF shop ' . $marker);
        $clientId = $this->makeClient();

        return Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'status_id' => $statusId,
            'type_id' => $typeId,
            'uuid' => 'oisf-' . $marker . '-' . uniqid(),
        ]);
    }

    public function test_issued_filter_returns_only_finished_pickup(): void
    {
        $token = $this->makeStaffToken();
        $issued = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_PICKUP, 'issued1');
        $delivered = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_DELIVERY, 'delivered1');
        $assembledPickup = $this->makeOrder(Status::ORDER_ASSEMBLED, Type::RECEIVE_PICKUP, 'assembled1');
        $cancelledPickup = $this->makeOrder(Status::ORDER_CANCELLED, Type::RECEIVE_PICKUP, 'cancelled1');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [7]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($issued->id));
        $this->assertFalse($ids->contains($delivered->id));
        $this->assertFalse($ids->contains($assembledPickup->id));
        $this->assertFalse($ids->contains($cancelledPickup->id));
    }

    public function test_delivered_filter_excludes_finished_pickup(): void
    {
        $token = $this->makeStaffToken();
        $issued = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_PICKUP, 'issued2');
        $delivered = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_DELIVERY, 'delivered2');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [5]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($delivered->id));
        $this->assertFalse($ids->contains($issued->id));
    }

    public function test_delivered_and_issued_together_return_all_finished(): void
    {
        $token = $this->makeStaffToken();
        $issued = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_PICKUP, 'issued3');
        $delivered = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_DELIVERY, 'delivered3');
        $created = $this->makeOrder(Status::ORDER_CREATED, Type::RECEIVE_DELIVERY, 'created3');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [5, 7]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($issued->id));
        $this->assertTrue($ids->contains($delivered->id));
        $this->assertFalse($ids->contains($created->id));
    }

    public function test_issued_combines_with_regular_statuses(): void
    {
        $token = $this->makeStaffToken();
        $created = $this->makeOrder(Status::ORDER_CREATED, Type::RECEIVE_DELIVERY, 'created4');
        $issued = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_PICKUP, 'issued4');
        $delivered = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_DELIVERY, 'delivered4');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [1, 7]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($created->id));
        $this->assertTrue($ids->contains($issued->id));
        $this->assertFalse($ids->contains($delivered->id));
    }

    public function test_without_statuses_filter_unaffected(): void
    {
        $token = $this->makeStaffToken();
        $issued = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_PICKUP, 'issued5');
        $delivered = $this->makeOrder(Status::ORDER_FINISHED, Type::RECEIVE_DELIVERY, 'delivered5');

        $resp = $this->gql($token, '{ admin_orders_paginate { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($issued->id));
        $this->assertTrue($ids->contains($delivered->id));
    }
}
```

- [ ] **Step 3: Убедиться, что тест падает**

Run: `cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_purchase_back && DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/OrderIssuedStatusFilterTest.php`
Expected: FAIL — `test_issued_filter_returns_only_finished_pickup` и `test_delivered_filter_excludes_finished_pickup` красные (код 7 сейчас уходит в `whereIn` и не находит ничего / 5 включает самовывоз). Остальные три могут быть зелёными — это ок (они фиксируют неизменность поведения).

- [ ] **Step 4: Реализация — `OrderFilter::statuses()`**

В `app/Filters/OrderFilter.php` добавить импорты и заменить метод `statuses()` (строки 43-46):

```php
use App\Models\Status;
use App\Models\Type;
```

```php
  /**
   * Виртуальный код фильтра «Выдан» (завершённый самовывоз).
   * В таблице statuses не существует — разворачивается в условие
   * status_id + type_id, см. statuses().
   */
  public const FILTER_ISSUED = 7;

  /**
   * Фильтр по статусам. Коды 1-6 — реальные status_id; особые случаи:
   * 5 («Доставлен») — завершённые заказы доставки (без самовывозов),
   * 7 («Выдан») — завершённые самовывозы (status_id=5 AND type_id=1),
   * 5 и 7 вместе — все завершённые.
   */
  public function statuses($value): void
  {
    $value = array_map('intval', $value);
    $hasIssued = in_array(self::FILTER_ISSUED, $value, true);
    $hasFinished = in_array(Status::ORDER_FINISHED, $value, true);
    $plain = array_values(array_diff($value, [self::FILTER_ISSUED, Status::ORDER_FINISHED]));

    if ($hasIssued && $hasFinished) {
      $plain[] = Status::ORDER_FINISHED;
      $hasIssued = false;
      $hasFinished = false;
    }

    $this->builder->where(function ($query) use ($plain, $hasIssued, $hasFinished) {
      $query->whereIn('status_id', $plain);

      if ($hasIssued) {
        $query->orWhere(function ($q) {
          $q->where('status_id', Status::ORDER_FINISHED)
            ->where('type_id', Type::RECEIVE_PICKUP);
        });
      }

      if ($hasFinished) {
        $query->orWhere(function ($q) {
          $q->where('status_id', Status::ORDER_FINISHED)
            ->where(function ($t) {
              $t->where('type_id', '!=', Type::RECEIVE_PICKUP)
                ->orWhereNull('type_id');
            });
        });
      }
    });
  }
```

В `graphql/Purchase/Order/OrderQueries.graphql` строку 3 заменить на:

```graphql
    "Фильтрация по статусам. 1-6 — реальные статусы; 5 — «Доставлен» (завершённая доставка, без самовывозов); 7 — виртуальный «Выдан» (завершённый самовывоз); 5 и 7 вместе — все завершённые"
    statuses:[Int]
```

- [ ] **Step 5: Убедиться, что тесты зелёные**

Run: `cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_purchase_back && DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/OrderIssuedStatusFilterTest.php`
Expected: OK (5 tests)

- [ ] **Step 6: Регрессия соседнего фильтра**

Run: `cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_purchase_back && DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/OrderPaymentStatusFilterTest.php`
Expected: OK (6 tests)

- [ ] **Step 7: Commit**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_purchase_back
git add app/Filters/OrderFilter.php graphql/Purchase/Order/OrderQueries.graphql tests/Feature/OrderIssuedStatusFilterTest.php docs/superpowers/
git commit -m "feat: фильтр «Выдан» (виртуальный статус 7) в admin_orders_paginate"
```

---

### Task 2: Фронт — опция «Выдан» в фильтре + бейдж подзаказа

**Files:**
- Modify: `mendev_admin_front/src/views/orders/components/OrdersPageFilters.vue:109-116` (STATUS_OPTIONS)
- Modify: `mendev_admin_front/src/views/orders/open.vue:589-598` (orderStatusLabel)

**Interfaces:**
- Consumes: контракт Task 1 — `admin_orders_paginate(statuses:[Int])` принимает виртуальный код 7. Фронт шлёт выбранные id опций как есть (generic `updateMulti('statuses')` → `index.vue` → GraphQL), трансляций не нужно.
- Produces: ничего для последующих задач.

Тестовой инфраструктуры на фронте нет (прецедент кодовой базы — без unit-тестов); проверка = eslint + ручная верификация в Task 3.

- [ ] **Step 1: Создать ветку**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_admin_front
git checkout -b feature/pickup-issued-filter
```

- [ ] **Step 2: STATUS_OPTIONS в OrdersPageFilters.vue**

Заменить (строки 109-116):

```js
const STATUS_OPTIONS = [
  { id: 1, title: "Создан" },
  { id: 2, title: "На сборке" },
  { id: 3, title: "Собрано" },
  { id: 4, title: "В доставке" },
  { id: 5, title: "Доставлен" },
  { id: 6, title: "Отменён" },
];
```

на:

```js
// id 7 — виртуальный код «Выдан» (завершённый самовывоз), разворачивается
// на бэкенде в status_id=5 AND type_id=1; «Доставлен» (5) — только доставка
const STATUS_OPTIONS = [
  { id: 1, title: "Создан" },
  { id: 2, title: "На сборке" },
  { id: 3, title: "Собрано" },
  { id: 4, title: "В доставке" },
  { id: 5, title: "Доставлен" },
  { id: 7, title: "Выдан" },
  { id: 6, title: "Отменён" },
];
```

- [ ] **Step 3: orderStatusLabel в open.vue**

Заменить (строки 589-599):

```js
    orderStatusLabel(statusId) {
      const map = {
        1: "Создан",
        2: "На сборке",
        3: "Собран",
        4: "В доставке",
        5: "Доставлен",
        6: "Отменён",
      };
      return map[statusId] ?? `Статус ${statusId}`;
    },
```

на:

```js
    orderStatusLabel(statusId) {
      const map = {
        1: "Создан",
        2: "На сборке",
        3: "Собран",
        4: "В доставке",
        5: this.page?.type_id === 1 ? "Выдан" : "Доставлен",
        6: "Отменён",
      };
      return map[statusId] ?? `Статус ${statusId}`;
    },
```

- [ ] **Step 4: Lint изменённых файлов**

Run: `cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_admin_front && npx eslint src/views/orders/components/OrdersPageFilters.vue src/views/orders/open.vue`
Expected: без ошибок (warnings, существовавшие до правки, допустимы).

- [ ] **Step 5: Commit**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_admin_front
git add src/views/orders/components/OrdersPageFilters.vue src/views/orders/open.vue
git commit -m "feat: опция «Выдан» в фильтре статусов заказов (самовывоз)"
```

---

### Task 3: Merge, деплой и верификация на dev — ТОЛЬКО по явной команде пользователя

**Files:** нет правок кода.

**Interfaces:**
- Consumes: ветки `feature/pickup-issued-filter` обоих репо (Task 1, Task 2).

- [ ] **Step 1: Получить явную команду пользователя на merge+push** (без неё — STOP)

- [ ] **Step 2: Merge и push (после команды)**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_purchase_back
git checkout master && git merge --no-ff feature/pickup-issued-filter && git push
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_admin_front
git checkout master && git merge --no-ff feature/pickup-issued-filter && git push
```

Ветки НЕ удалять (правило проекта).

- [ ] **Step 3: CI обоих репо**

```bash
python3 ~/.claude/gitlab_cli.py last_pipeline mendev/mendev_purchase_back --ref master --wait
python3 ~/.claude/gitlab_cli.py last_pipeline mendev/mendev_admin_front --ref master --wait
```

Expected: exit 0 у обоих; сверить sha пайплайна с запушенным коммитом (ловушка: `--wait` сразу после push может поймать предыдущий пайплайн).

- [ ] **Step 4: Ручная верификация на dev через claude-in-chrome**

`https://raz-dev.mendev.ru/admin/orders` (логин `admin@mail.com`/`12345678`, 2FA-код вводит пользователь):
1. Фильтр «Статус» содержит опцию «Выдан».
2. Выбрать «Выдан» → в таблице только зелёные бейджи «Выдан», тип «Самовывоз» (предусловие: на dev существует хотя бы один выданный самовывоз — если нет, создать через кнопку «Заказ выдан» в карточке собранного самовывоза).
3. Выбрать только «Доставлен» → выданных самовывозов в выборке нет.
4. Выбрать «Доставлен» + «Выдан» → присутствуют оба вида завершённых.
5. URL-персист: перезагрузить страницу с выбранным «Выдан» — фильтр сохранился.
