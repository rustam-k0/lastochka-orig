# Статус «Назначен курьер» (courier_assignment) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** При включённом модуле `courier_assignment` назначение курьера перестаёт автостартовать рейс; АП показывает виртуальный статус «Назначен курьер» (= `ASSEMBLED (3)` + назначение) в таблице, фильтре (код 8) и карточке заказа.

**Architecture:** delivery_back гейтит автостарт (`DeliveryAssignCouriers` + REST `OrderController::delivery`) по флагу; purchase_back разворачивает виртуальный код 8 в EXISTS-условие по `delivery_orders` (сам заказ + подзаказы) и делит «Собрано (3)» при включённом флаге; admin_front показывает опцию/бейджи только при включённом модуле (`useModuleConfigStore`). Реального status_id в БД нет — события/консьюмеры не затронуты. Спека: `mendev_delivery_back/docs/superpowers/specs/2026-07-18-courier-assigned-status-design.md`.

**Tech Stack:** Laravel 9 / PHP 8.1 (delivery_back `/graphql-delivery` + REST, purchase_back `/graphql-purchase` Lighthouse), Vue 3 Options API (admin_front, Pinia `moduleConfig` store).

## Global Constraints

- PHPUnit ТОЛЬКО локально (`DB_HOST=127.0.0.1 vendor/bin/phpunit <один файл за раз>`); НИКОГДА на сервере/в поде. Тесты только с `DatabaseTransactions`.
- Коммиты только в feature-ветку `feature/courier-assigned-status` (в каждом из 3 репо); merge/push — ТОЛЬКО по явной команде пользователя. Без Co-Authored-By.
- Семантика фильтра (спека): `[8]` → `status 3 AND EXISTS назначение`; `[3]` при флаге ON → `status 3 AND NOT EXISTS`; `[3]` при OFF → все 3; `[3,8]` → все 3. Композиция с кодами 5/7 в той же OR-группе.
- Fail-safe: при флаге OFF поведение всех сервисов байт-в-байт текущее.
- Аддендум контракта мобилке — внешний текст: БЕЗ длинных тире «—» (заменять на двоеточие/точку/переформулировку).
- EXISTS «назначение» = `delivery_orders.order_id` ∈ {сам заказ, его подзаказы} (зеркало `OrderCouriersResolver::selfAndChildOrderIds`).

---

### Task 1: delivery_back — назначение при флаге не стартует рейс

**Files:**
- Modify: `mendev_delivery_back/app/GraphQL/Mutations/Delivery/DeliveryAssignCouriers.php:33-42`
- Modify: `mendev_delivery_back/app/Http/Controllers/OrderController.php:107-116`
- Modify: `mendev_delivery_back/docs/specs/2026-07-17-courier-assignment-api-contract.md` (аддендум в конец)
- Test: `mendev_delivery_back/tests/Feature/CourierAssignedStatusTest.php` (создать)

**Interfaces:**
- Consumes: `Order::isCourierAssignmentModuleEnabled(): bool` (уже есть в `app/Models/Order.php:310`), `DeliveryStart::deliveryStart($delivery, $delivery_order)`, `Status::ORDER_ASSEMBLED/ORDER_IN_DELIVERY`, `Status::DELIVERY_WAIT_TO_START`.
- Produces: поведение «флаг ON → заказ остаётся 3 + delivery_order WAIT_TO_START, рейс без started_at», на которое опираются фильтр (Task 2) и UI (Task 3).

- [ ] **Step 1: Создать ветку**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_delivery_back
git checkout -b feature/courier-assigned-status
```

- [ ] **Step 2: Написать падающий тест**

Создать `tests/Feature/CourierAssignedStatusTest.php` (хелперы = паттерн `DeliveryAssignCouriersTest`; `setModules` = паттерн `CourierAssignmentModuleTest::setModule`, но с обоими ключами):

```php
<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Delivery\DeliveryStart;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Модуль courier_assignment: назначение курьера НЕ стартует рейс, заказ
 * остаётся «Назначен курьер» (ASSEMBLED + delivery_order WAIT_TO_START)
 * до DeliveryStart курьером.
 * Спека: docs/superpowers/specs/2026-07-18-courier-assigned-status-design.md
 */
class CourierAssignedStatusTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private int $shopId;
    private int $clientId;
    private User $collector;
    private int $courier1Id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(FcmNotificationService::class)
            ->shouldReceive('sendToUser')->andReturnNull();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'CAS shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->collector = $this->makeStaff('collector');
        $this->courier1Id = $this->makeStaff('courier')->id;
    }

    private function makeStaff(string $roleName): User
    {
        $userId = \DB::table('users')->insertGetId([
            'name' => ucfirst($roleName), 'surname' => 'Тестовый ' . uniqid(),
            'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::findOrFail($userId);
        $user->assignRole(Role::findOrCreate($roleName, 'api'));

        \DB::table('shifts')->insert([
            'user_id' => $user->id, 'shop_id' => $this->shopId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function insertOrder(array $attrs = []): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'cas-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function setModules(bool $courierAssignment, bool $heavy = false): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode([
                'courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $courierAssignment],
                'heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => $heavy],
            ]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private const MUTATION = '
        mutation DeliveryAssignCouriers($order_id: Int!, $courier_ids: [Int!]!) {
            DeliveryAssignCouriers(order_id: $order_id, courier_ids: $courier_ids) { code message }
        }
    ';

    private function assign(int $orderId, array $courierIds)
    {
        $this->actingAs($this->collector, 'api');

        return $this->graphQL(self::MUTATION, ['order_id' => $orderId, 'courier_ids' => $courierIds]);
    }

    /** @test */
    public function assignment_with_flag_keeps_order_assembled_and_trip_not_started()
    {
        $this->setModules(courierAssignment: true);
        $orderId = $this->insertOrder();

        $this->assign($orderId, [$this->courier1Id])
            ->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);

        $deliveryOrder = \DB::table('delivery_orders')->where('order_id', $orderId)->first();
        $this->assertNotNull($deliveryOrder);
        $this->assertSame(Status::DELIVERY_WAIT_TO_START, (int) $deliveryOrder->status_id);
        $this->assertNull(\DB::table('deliveries')->where('id', $deliveryOrder->delivery_id)->value('started_at'));
        $this->assertSame(Status::ORDER_ASSEMBLED, (int) Order::find($orderId)->status_id);
    }

    /** @test */
    public function rest_admin_assignment_with_flag_keeps_order_assembled()
    {
        $this->setModules(courierAssignment: true);
        config(['services.system.token' => 'test-system-token']);
        $orderId = $this->insertOrder();

        $response = $this->postJson('/backend-delivery/order/delivery', [
            'token' => 'test-system-token',
            'order_id' => $orderId,
            'courier_id' => $this->courier1Id,
        ]);

        $response->assertOk();
        $deliveryOrder = \DB::table('delivery_orders')->where('order_id', $orderId)->first();
        $this->assertNotNull($deliveryOrder);
        $this->assertNull(\DB::table('deliveries')->where('id', $deliveryOrder->delivery_id)->value('started_at'));
        $this->assertSame(Status::ORDER_ASSEMBLED, (int) Order::find($orderId)->status_id);
    }

    /** @test */
    public function delivery_start_moves_assigned_order_to_in_delivery()
    {
        $this->setModules(courierAssignment: true);
        $orderId = $this->insertOrder();
        $this->assign($orderId, [$this->courier1Id])
            ->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);

        // Старт рейса курьером: тот же static, который зовёт мутация DeliveryStart
        $delivery = Delivery::whereUserId($this->courier1Id)->first();
        $deliveryOrder = $delivery->deliveryOrders()
            ->where('status_id', Status::DELIVERY_WAIT_TO_START)->orderBy('priority')->first();
        DeliveryStart::deliveryStart($delivery, $deliveryOrder);

        $this->assertSame(Status::ORDER_IN_DELIVERY, (int) Order::find($orderId)->status_id);
        $this->assertNotNull($delivery->fresh()->started_at);
    }
}
```

- [ ] **Step 3: Убедиться, что тест падает**

Run: `cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_delivery_back && DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/CourierAssignedStatusTest.php`
Expected: FAIL — первые два теста красные (заказ сейчас автостартует → status 4, started_at не NULL). Третий может быть красным на assert started_at/статуса (заказ уже 4 до deliveryStart) — тоже ок.

- [ ] **Step 4: Реализация**

`app/GraphQL/Mutations/Delivery/DeliveryAssignCouriers.php` — заменить блок (строки 33-42):

```php
      // Зеркало REST-пути OrderController::delivery: рейс в пути — в хвост
      // очереди, иначе доставка стартует назначением. При модуле
      // courier_assignment автостарта нет: заказ остаётся «Назначен курьер»
      // (ASSEMBLED + WAIT_TO_START), доставку начинает курьер (DeliveryStart).
      if ($delivery && $data && $delivery->started_at != null) {
        $last_order = $delivery->deliveryOrders()->orderByDesc('priority')->first();
        DeliveryOrder::whereId($data['delivery_order']->id)->update([
          'priority' => $last_order->priority + 1,
        ]);
      } elseif (!Order::isCourierAssignmentModuleEnabled()) {
        DeliveryStart::deliveryStart($data['delivery'], $data['delivery_order']);
      }
```

`app/Http/Controllers/OrderController.php` — заменить блок (строки 107-116):

```php
    //Если курьер уже в процессе доставки, то добавляем ему еще заказ в последний приоритет
    if ($delivery && $data && $delivery->started_at != null) {
      $last_order = $delivery->deliveryOrders()->orderByDesc('priority')->first();
      DeliveryOrder::whereId($data['delivery_order']->id)->update([
        'priority' => $last_order->priority + 1
      ]);

    } elseif ($data && !Order::isCourierAssignmentModuleEnabled()) {
      // Модуль courier_assignment: назначение (в т.ч. админом из АП) не
      // стартует рейс — заказ остаётся «Назначен курьер», старт за курьером.
      DeliveryStart::deliveryStart($data['delivery'], $data['delivery_order']);
    }
```

(`use App\Models\Order;` в контроллере уже есть, строка 14.)

- [ ] **Step 5: Тесты зелёные**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/CourierAssignedStatusTest.php`
Expected: OK (3 tests)

- [ ] **Step 6: Регрессия автостарта при флаге OFF и смежных гейтов**

Run по одному:
`DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/DeliveryAssignCouriersTest.php` — Expected: OK (тест `collector_assigns_two_couriers_to_heavy_order` фиксирует автостарт при heavy ON / CA OFF)
`DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/CourierAssignmentModuleTest.php` — Expected: OK
`DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/OrderByCourierAssignmentScopeTest.php` — Expected: OK
`DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/DeliveryTakeOrderCourierAssignmentTest.php` — Expected: OK

- [ ] **Step 7: Аддендум контракта мобилке**

Дописать в конец `docs/specs/2026-07-17-courier-assignment-api-contract.md` (внешний текст: без длинных тире):

```markdown

## Аддендум (2026-07-18): назначение больше не стартует доставку

При включённом `courier_assignment_enabled` назначение курьера (сборщиком через `DeliveryAssignCouriers` или админом из панели) НЕ запускает доставку автоматически. Заказ после назначения остаётся в статусе `assembled`. В админ-панели он отображается как «Назначен курьер».

Что это значит для приложения курьера:

1. Курьер получает пуш `order_assigned` и видит назначенный заказ в списке (как раньше).
2. Чтобы начать доставку, курьер обязан нажать «Начать доставку» (mutation `DeliveryStart`). Только после этого заказ переходит в `in_delivery`.
3. Экран старта рейса обязателен в флоу курьера при включённом флаге: без нажатия заказ останется назначенным и в доставку не перейдёт.

Для приложения сборщика изменений нет: флоу «завершил сборку, назначил курьера» прежний.
```

- [ ] **Step 8: Commit**

```bash
git add app/GraphQL/Mutations/Delivery/DeliveryAssignCouriers.php app/Http/Controllers/OrderController.php tests/Feature/CourierAssignedStatusTest.php docs/specs/2026-07-17-courier-assignment-api-contract.md docs/superpowers/
git commit -m "feat: назначение при courier_assignment не стартует рейс (статус «Назначен курьер»)"
```

---

### Task 2: purchase_back — виртуальный код 8 в фильтре статусов

**Files:**
- Modify: `mendev_purchase_back/app/Models/Order.php` (public static хелпер флага)
- Modify: `mendev_purchase_back/app/GraphQL/Validators/Order/OrderCourierAddValidator.php:56-62` (перевод на хелпер)
- Modify: `mendev_purchase_back/app/Filters/OrderFilter.php` (const 8 + statuses() + whereCourierAssigned)
- Modify: `mendev_purchase_back/graphql/Purchase/Order/OrderQueries.graphql:3` (докстрока)
- Test: `mendev_purchase_back/tests/Feature/OrderCourierAssignedStatusFilterTest.php` (создать)

**Interfaces:**
- Consumes: поведение Task 1 (заказ 3 + delivery_orders = «Назначен курьер»); `Status::ORDER_ASSEMBLED = 3`; `AdminSetting::getValue('project_modules')`; существующие `OrderFilter::FILTER_ISSUED = 7` и OR-группа `statuses()`.
- Produces: `Order::isCourierAssignmentModuleEnabled(): bool` (public static); `OrderFilter::FILTER_COURIER_ASSIGNED = 8`; контракт для фронта — `admin_orders_paginate(statuses: [8])`.

- [ ] **Step 1: Создать ветку**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_purchase_back
git checkout -b feature/courier-assigned-status
```

- [ ] **Step 2: Написать падающий тест**

Создать `tests/Feature/OrderCourierAssignedStatusFilterTest.php` (хелперы makeShop/makeClient/makeStaffToken/gql/makeOrder = копия `OrderIssuedStatusFilterTest` с префиксом `ocas`):

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
 * Фильтр «Назначен курьер» (виртуальный статус 8) в списке заказов АП.
 * Спека: mendev_delivery_back/docs/superpowers/specs/2026-07-18-courier-assigned-status-design.md
 */
class OrderCourierAssignedStatusFilterTest extends TestCase
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
            'email' => uniqid('ocas_') . '@test.local',
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

    private function makeOrder(int $statusId, string $marker, array $attrs = []): Order
    {
        $shopId = $this->makeShop('OCAS shop ' . $marker);
        $clientId = $this->makeClient();

        return Order::factory()->create(array_merge([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'status_id' => $statusId,
            'type_id' => Type::RECEIVE_DELIVERY,
            'payment_type_id' => 1,
            'uuid' => 'ocas-' . $marker . '-' . uniqid(),
        ], $attrs));
    }

    private function makeCourierAssignment(int $orderId): void
    {
        $courierId = \DB::table('users')->insertGetId([
            'name' => 'Courier',
            'email' => uniqid('ocasc_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => $courierId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('delivery_orders')->insert([
            'delivery_id' => $deliveryId,
            'order_id' => $orderId,
            'status_id' => 1, // WAIT_TO_START; delivery-констант в purchase_back нет, EXISTS на него не смотрит
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setCourierAssignmentModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function test_courier_assigned_filter_returns_only_assembled_with_assignment(): void
    {
        $this->setCourierAssignmentModule(true);
        $token = $this->makeStaffToken();
        $assigned = $this->makeOrder(Status::ORDER_ASSEMBLED, 'assigned1');
        $this->makeCourierAssignment($assigned->id);
        $bare = $this->makeOrder(Status::ORDER_ASSEMBLED, 'bare1');
        $inDelivery = $this->makeOrder(Status::ORDER_IN_DELIVERY, 'indelivery1');
        $this->makeCourierAssignment($inDelivery->id);

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [8]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($assigned->id));
        $this->assertFalse($ids->contains($bare->id));
        $this->assertFalse($ids->contains($inDelivery->id));
    }

    public function test_assembled_filter_excludes_assigned_when_module_enabled(): void
    {
        $this->setCourierAssignmentModule(true);
        $token = $this->makeStaffToken();
        $assigned = $this->makeOrder(Status::ORDER_ASSEMBLED, 'assigned2');
        $this->makeCourierAssignment($assigned->id);
        $bare = $this->makeOrder(Status::ORDER_ASSEMBLED, 'bare2');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [3]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($bare->id));
        $this->assertFalse($ids->contains($assigned->id));
    }

    public function test_assembled_filter_returns_all_when_module_disabled(): void
    {
        $this->setCourierAssignmentModule(false);
        $token = $this->makeStaffToken();
        $assigned = $this->makeOrder(Status::ORDER_ASSEMBLED, 'assigned3');
        $this->makeCourierAssignment($assigned->id);
        $bare = $this->makeOrder(Status::ORDER_ASSEMBLED, 'bare3');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [3]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($bare->id));
        $this->assertTrue($ids->contains($assigned->id));
    }

    public function test_assembled_and_assigned_together_return_all_assembled(): void
    {
        $this->setCourierAssignmentModule(true);
        $token = $this->makeStaffToken();
        $assigned = $this->makeOrder(Status::ORDER_ASSEMBLED, 'assigned4');
        $this->makeCourierAssignment($assigned->id);
        $bare = $this->makeOrder(Status::ORDER_ASSEMBLED, 'bare4');
        $created = $this->makeOrder(Status::ORDER_CREATED, 'created4');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [3, 8]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($assigned->id));
        $this->assertTrue($ids->contains($bare->id));
        $this->assertFalse($ids->contains($created->id));
    }

    public function test_assigned_composes_with_regular_and_issued_codes(): void
    {
        $this->setCourierAssignmentModule(true);
        $token = $this->makeStaffToken();
        $assigned = $this->makeOrder(Status::ORDER_ASSEMBLED, 'assigned5');
        $this->makeCourierAssignment($assigned->id);
        $created = $this->makeOrder(Status::ORDER_CREATED, 'created5');
        $issuedPickup = $this->makeOrder(Status::ORDER_FINISHED, 'issued5', ['type_id' => Type::RECEIVE_PICKUP]);
        $deliveredDelivery = $this->makeOrder(Status::ORDER_FINISHED, 'delivered5');

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [1, 7, 8]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($created->id));
        $this->assertTrue($ids->contains($issuedPickup->id));
        $this->assertTrue($ids->contains($assigned->id));
        $this->assertFalse($ids->contains($deliveredDelivery->id));
    }

    public function test_child_order_assignment_counts_for_parent(): void
    {
        $this->setCourierAssignmentModule(true);
        $token = $this->makeStaffToken();
        $parent = $this->makeOrder(Status::ORDER_ASSEMBLED, 'parent6');
        $child = $this->makeOrder(Status::ORDER_ASSEMBLED, 'child6', [
            'parent_order_id' => $parent->id,
            'shop_id' => $parent->shop_id,
            'client_id' => $parent->client_id,
        ]);
        $this->makeCourierAssignment($child->id);

        $resp = $this->gql($token, '{ admin_orders_paginate(statuses: [8]) { data { id } paginatorInfo { total } } }');

        $resp->assertJsonMissingPath('errors');
        $ids = collect($resp->json('data.admin_orders_paginate.data'))->pluck('id');
        $this->assertTrue($ids->contains($parent->id));
    }
}
```

- [ ] **Step 3: Убедиться, что тест падает**

Run: `cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_purchase_back && DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/OrderCourierAssignedStatusFilterTest.php`
Expected: FAIL — `[8]` уходит в `whereIn` и ничего не находит; `[3]` при флаге ON включает назначенные. Тест `module_disabled` и `[3,8]` могут быть зелёными частично — ок.

- [ ] **Step 4: Реализация**

`app/Models/Order.php` — добавить public static метод (рядом с `scopeAdmin`, до relations; `AdminSetting` в том же namespace, импорт не нужен):

```php
  /**
   * Включён ли модуль «Назначение курьера сборщиком» (courier_assignment).
   * Читает project_modules из admin_settings (общая БД, зеркало delivery_back).
   */
  public static function isCourierAssignmentModuleEnabled(): bool
  {
    $raw = AdminSetting::getValue('project_modules');
    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['courier_assignment']['enabled'] ?? false);
  }
```

`app/GraphQL/Validators/Order/OrderCourierAddValidator.php` — строку 22 заменить на:

```php
          if (!self::isHeavyOrdersModuleEnabled() && !Order::isCourierAssignmentModuleEnabled()) {
```

и удалить приватный метод `isCourierAssignmentModuleEnabled()` (строки 56-62).

`app/Filters/OrderFilter.php` — добавить константу после `FILTER_ISSUED` и заменить `statuses()`; добавить `use App\Models\Order;` к импортам:

```php
  /**
   * Виртуальный код фильтра «Назначен курьер» (собранный заказ с назначенным
   * курьером, модуль courier_assignment). В таблице statuses не существует.
   */
  public const FILTER_COURIER_ASSIGNED = 8;
```

```php
  /**
   * Фильтр по статусам. Коды 1-6 — реальные status_id; особые случаи:
   * 3 («Собрано») — при включённом модуле courier_assignment только
   *   собранные БЕЗ назначенного курьера (при выключенном — все собранные),
   * 5 («Доставлен») — завершённые заказы доставки (без самовывозов),
   * 7 («Выдан») — завершённые самовывозы (status_id=5 AND type_id=1),
   * 8 («Назначен курьер») — собранные с назначенным курьером,
   * 5 и 7 вместе — все завершённые; 3 и 8 вместе — все собранные.
   */
  public function statuses($value): void
  {
    $value = array_map('intval', $value);
    $hasIssued = in_array(self::FILTER_ISSUED, $value, true);
    $hasFinished = in_array(Status::ORDER_FINISHED, $value, true);
    $hasCourierAssigned = in_array(self::FILTER_COURIER_ASSIGNED, $value, true);
    $hasAssembled = in_array(Status::ORDER_ASSEMBLED, $value, true);
    $plain = array_values(array_diff($value, [
      self::FILTER_ISSUED,
      Status::ORDER_FINISHED,
      self::FILTER_COURIER_ASSIGNED,
      Status::ORDER_ASSEMBLED,
    ]));

    if ($hasIssued && $hasFinished) {
      $plain[] = Status::ORDER_FINISHED;
      $hasIssued = false;
      $hasFinished = false;
    }

    if ($hasAssembled && $hasCourierAssigned) {
      $plain[] = Status::ORDER_ASSEMBLED;
      $hasAssembled = false;
      $hasCourierAssigned = false;
    } elseif ($hasAssembled && !Order::isCourierAssignmentModuleEnabled()) {
      // Модуль выключен: «Собрано» = все собранные (текущее поведение)
      $plain[] = Status::ORDER_ASSEMBLED;
      $hasAssembled = false;
    }

    $this->builder->where(function ($query) use ($plain, $hasIssued, $hasFinished, $hasCourierAssigned, $hasAssembled) {
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

      if ($hasCourierAssigned) {
        $query->orWhere(function ($q) {
          $q->where('status_id', Status::ORDER_ASSEMBLED);
          $this->whereCourierAssigned($q, true);
        });
      }

      if ($hasAssembled) {
        // Достижимо только при включённом модуле: собранные без назначения
        $query->orWhere(function ($q) {
          $q->where('status_id', Status::ORDER_ASSEMBLED);
          $this->whereCourierAssigned($q, false);
        });
      }
    });
  }

  /**
   * Назначен ли заказу курьер: существует delivery_orders на сам заказ или
   * его подзаказ (зеркало OrderCouriersResolver / selfAndChildOrderIds).
   */
  private function whereCourierAssigned($query, bool $exists): void
  {
    $method = $exists ? 'whereExists' : 'whereNotExists';
    $query->{$method}(function ($q) {
      $q->selectRaw('1')
        ->from('delivery_orders')
        ->whereRaw('(delivery_orders.order_id = orders.id OR delivery_orders.order_id IN (select child.id from orders as child where child.parent_order_id = orders.id))');
    });
  }
```

`graphql/Purchase/Order/OrderQueries.graphql` — заменить докстроку `statuses`:

```graphql
    "Фильтрация по статусам. 1-6 — реальные статусы; 3 — «Собрано» (при включённом модуле courier_assignment — только без назначенного курьера); 5 — «Доставлен» (завершённая доставка, без самовывозов); 7 — виртуальный «Выдан» (завершённый самовывоз); 8 — виртуальный «Назначен курьер» (собран + курьер назначен); 5 и 7 вместе — все завершённые; 3 и 8 вместе — все собранные"
    statuses:[Int]
```

- [ ] **Step 5: Тесты зелёные**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/OrderCourierAssignedStatusFilterTest.php`
Expected: OK (6 tests)

- [ ] **Step 6: Регрессия соседних фильтров и валидатора**

Run по одному:
`DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/OrderIssuedStatusFilterTest.php` — Expected: OK (5 tests)
`DB_HOST=127.0.0.1 vendor/bin/phpunit tests/Feature/OrderPaymentStatusFilterTest.php` — Expected: OK (6 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Models/Order.php app/GraphQL/Validators/Order/OrderCourierAddValidator.php app/Filters/OrderFilter.php graphql/Purchase/Order/OrderQueries.graphql tests/Feature/OrderCourierAssignedStatusFilterTest.php
git commit -m "feat: фильтр «Назначен курьер» (виртуальный статус 8) в admin_orders_paginate"
```

---

### Task 3: admin_front — опция фильтра, бейдж таблицы, подпись в карточке

**Files:**
- Modify: `mendev_admin_front/src/views/orders/components/OrdersPageFilters.vue` (STATUS_OPTIONS → computed, опция 8)
- Modify: `mendev_admin_front/src/views/orders/index.vue:116-121` (бейдж) + computed
- Modify: `mendev_admin_front/src/views/orders/components/OrderPageAside.vue:12-13` (подпись шага 3) + computed

**Interfaces:**
- Consumes: `admin_orders_paginate(statuses: [8])` из Task 2; `useModuleConfigStore().isModuleEnabled("courier_assignment")` (стор существует, импортирован в index.vue:257 и OrderPageAside.vue:325); `row.couriers` (list query), `assignedCouriers` computed в aside (`page?.couriers || []`).
- Produces: ничего для последующих задач.

- [ ] **Step 1: Создать ветку**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_admin_front
git checkout -b feature/courier-assigned-status
```

- [ ] **Step 2: OrdersPageFilters.vue — опция «Назначен курьер» при включённом модуле**

Добавить импорт после существующих (строка ~107):

```js
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
```

В `data()` удалить строку `statusOptions: STATUS_OPTIONS,`.

В `computed` добавить (первым) и поправить `statusesSelection`:

```js
    statusOptions() {
      // id 8 — виртуальный код «Назначен курьер» (собран + курьер назначен),
      // виден только при включённом модуле courier_assignment
      if (!useModuleConfigStore().isModuleEnabled("courier_assignment")) {
        return STATUS_OPTIONS;
      }
      const opts = [...STATUS_OPTIONS];
      const idx = opts.findIndex((o) => o.id === 3);
      opts.splice(idx + 1, 0, { id: 8, title: "Назначен курьер" });
      return opts;
    },
    statusesSelection() {
      return this.mapIdsToOptions(this.localFilters.statuses, this.statusOptions);
    },
```

- [ ] **Step 3: index.vue — бейдж «Назначен курьер» в таблице**

Заменить блок status_id === 3 (строки 116-121):

```html
            <template v-if="row.status_id === 3">
              <div class="status status--orange">
                <div class="orders-page__dot"></div>
                <span v-if="courierAssignmentModuleEnabled && row.couriers?.length">Назначен курьер</span>
                <span v-else>Собрано</span>
              </div>
            </template>
```

В `computed` (рядом с `heavyOrdersModuleEnabled`, ~строка 704) добавить:

```js
    courierAssignmentModuleEnabled() {
      return useModuleConfigStore().isModuleEnabled("courier_assignment");
    },
```

- [ ] **Step 4: OrderPageAside.vue — подпись шага 3 в карточке**

Заменить строки 12-13:

```html
                <span v-if="item.id === 5 && page.type_id === 1">Выдан</span>
                <span v-else-if="item.id === 3 && courierAssignmentEnabled && assignedCouriers.length">Назначен курьер</span>
                <span v-else>{{ item.title }}</span>
```

В `computed` (рядом с `heavyOrdersModuleEnabled`, ~строка 589) добавить:

```js
    courierAssignmentEnabled() {
      return useModuleConfigStore().isModuleEnabled("courier_assignment");
    },
```

- [ ] **Step 5: Lint изменённых файлов**

Run: `npx eslint src/views/orders/components/OrdersPageFilters.vue src/views/orders/index.vue src/views/orders/components/OrderPageAside.vue`
Expected: количество ошибок не выросло относительно master (pre-existing prettier-ошибки допустимы; сравнить через `git stash` при сомнении).

- [ ] **Step 6: Commit**

```bash
git add src/views/orders/components/OrdersPageFilters.vue src/views/orders/index.vue src/views/orders/components/OrderPageAside.vue
git commit -m "feat: статус «Назначен курьер» в таблице, фильтре и карточке заказа (courier_assignment)"
```

---

### Task 4: Merge, деплой и E2E на dev — ТОЛЬКО по явной команде пользователя

**Files:** нет правок кода.

**Interfaces:**
- Consumes: ветки `feature/courier-assigned-status` трёх репо (Task 1-3).

- [ ] **Step 1: Получить явную команду пользователя на merge+push** (без неё — STOP)

- [ ] **Step 2: Merge и push (после команды)** — в каждом из трёх репо:

```bash
git checkout master && git pull --ff-only origin master && git merge --no-ff feature/courier-assigned-status -m "merge: feature/courier-assigned-status" && git push origin master
```

Репо: `mendev_delivery_back`, `mendev_purchase_back`, `mendev_admin_front`. Ветки НЕ удалять.

- [ ] **Step 3: CI трёх репо** (сверять sha пайплайна с запушенным коммитом):

```bash
python3 ~/.claude/gitlab_cli.py last_pipeline mendev/mendev_delivery_back --ref master --wait
python3 ~/.claude/gitlab_cli.py last_pipeline mendev/mendev_purchase_back --ref master --wait
python3 ~/.claude/gitlab_cli.py last_pipeline mendev/mendev_admin_front --ref master --wait
```

- [ ] **Step 4: E2E на dev** (`https://raz-dev.mendev.ru/admin/orders`, флаг включить на время проверки и вернуть в исходное состояние; включение/выключение флага на dev — write-операция, выполняется в рамках E2E с явным упоминанием в отчёте):

1. Зафиксировать исходное состояние флага: `SELECT value FROM mendev_djami.admin_settings WHERE `key`='project_modules'`.
2. Включить флаг: `JSON_SET` courier_assignment.enabled=true (как в live-E2E courier-assignment 2026-07-17).
3. Проверить фильтр: опция «Назначен курьер» появилась в селекте статусов; при выключенном флаге (шаг 6) её нет.
4. Создать предусловие: собранный заказ + назначение курьера (REST `/backend-delivery/order/delivery` с системным токеном или мутация сборщиком) → заказ в АП «Назначен курьер» (таблица + карточка), рейс НЕ стартовал (`deliveries.started_at IS NULL`), статус в БД остаётся 3.
5. Фильтры: `[8]` — только назначенные; `[3]` — только без назначения; `[3,8]` — все собранные.
6. Вернуть флаг в исходное состояние, убедиться что опция 8 исчезла и бейдж снова «Собрано».
7. Обе стороны: предусловие (назначение реально создано в БД) + результат (UI корректен).
