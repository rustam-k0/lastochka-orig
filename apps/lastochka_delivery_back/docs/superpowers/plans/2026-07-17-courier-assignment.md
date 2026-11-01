# Courier Assignment (модуль `courier_assignment`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сборщик назначает одного курьера по завершении сборки; курьеры видят только назначенные им заказы — всё за фича-флагом `courier_assignment` (default OFF).

**Architecture:** Реюз существующего механизма назначения курьеров (мутация `DeliveryAssignCouriers`, query `shift_couriers`, флоу heavy_orders): расширяем гейт на новый флаг, добавляем scope видимости заказов для курьера, блокируем self-take и общий пуш «заказ готов». Тумблер — в projectModules (admin_back), delivery_back читает `admin_settings.project_modules` общей БД напрямую (зеркало `isHeavyOrdersModuleEnabled`).

**Tech Stack:** PHP 8.1 / Laravel 9 / Lighthouse 5 (delivery_back), Laravel (admin_back), PHPUnit + DatabaseTransactions против локальной БД mendev_test.

**Спека:** `docs/superpowers/specs/2026-07-17-courier-assignment-design.md`

## Global Constraints

- Флаг `courier_assignment`, default **OFF** во всех форках; при OFF поведение байт-в-байт текущее (fail-safe: любая ошибка чтения флага = OFF).
- Тексты новых ошибок: «Назначение курьеров отключено» (гейт мутации), «Заказы назначает сборщик» (self-take).
- Тесты гонять ТОЛЬКО локально: delivery_back — `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter=<Test>`; admin_back — `vendor/bin/phpunit --filter=<Test>`. phpunit этих репо принимает только первый path-аргумент.
- НЕ мержить в master, НЕ пушить, НЕ деплоить — только feature-ветки, по явной команде пользователя дальше.
- Коммиты в `feature/courier-assignment` (обе репы), формат `feat: ...` / `test: ...`.
- User модель delivery_back полностью guarded — в тестах сырой `DB::table('users')->insertGetId` + `User::findOrFail` (паттерн DeliveryAssignCouriersTest::makeStaff).

---

### Task 1: delivery_back — флаг + query `courier_assignment_enabled`

**Files:**
- Modify: `app/Models/Order.php` (~строка 268, после `isHeavyOrdersModuleEnabled`)
- Create: `app/GraphQL/Queries/CourierAssignmentEnabled.php`
- Modify: `graphql/Delivery/User/UserQueries.graphql`
- Test: `tests/Feature/CourierAssignmentModuleTest.php`

**Interfaces:**
- Produces: `Order::isCourierAssignmentModuleEnabled(): bool` (static) — используется задачами 2-5; GraphQL query `courier_assignment_enabled: Boolean`.

- [ ] **Step 1: Создать ветку**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_delivery_back
git checkout -b feature/courier-assignment
```

- [ ] **Step 2: Написать падающий тест**

`tests/Feature/CourierAssignmentModuleTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class CourierAssignmentModuleTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private function setModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** @test */
    public function flag_query_returns_false_by_default()
    {
        \DB::table('admin_settings')->where('key', 'project_modules')->delete();

        $this->graphQL('{ courier_assignment_enabled }')
            ->assertJson(['data' => ['courier_assignment_enabled' => false]]);
    }

    /** @test */
    public function flag_query_returns_true_when_module_enabled()
    {
        $this->setModule(true);

        $this->graphQL('{ courier_assignment_enabled }')
            ->assertJson(['data' => ['courier_assignment_enabled' => true]]);
    }

    /** @test */
    public function flag_query_returns_false_when_module_disabled()
    {
        $this->setModule(false);

        $this->graphQL('{ courier_assignment_enabled }')
            ->assertJson(['data' => ['courier_assignment_enabled' => false]]);
    }
}
```

- [ ] **Step 3: Убедиться что тест падает**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter=CourierAssignmentModuleTest`
Expected: FAIL (Cannot query field "courier_assignment_enabled").

- [ ] **Step 4: Реализация**

`app/Models/Order.php` — сразу после метода `isHeavyOrdersModuleEnabled()`:

```php
  /**
   * Включён ли модуль «Назначение курьера сборщиком»
   * (courier_assignment в admin_settings.project_modules).
   * Читаем общую БД напрямую (зеркало isHeavyOrdersModuleEnabled).
   */
  public static function isCourierAssignmentModuleEnabled(): bool
  {
    $raw = \Illuminate\Support\Facades\DB::table('admin_settings')
      ->where('key', 'project_modules')
      ->value('value');

    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['courier_assignment']['enabled'] ?? false);
  }
```

`app/GraphQL/Queries/CourierAssignmentEnabled.php` (зеркало HeavyOrdersEnabled):

```php
<?php

namespace App\GraphQL\Queries;

use App\Models\Order;

final class CourierAssignmentEnabled
{
  public function __invoke($_, array $args): bool
  {
    return Order::isCourierAssignmentModuleEnabled();
  }
}
```

`graphql/Delivery/User/UserQueries.graphql` — после блока `heavy_orders_enabled`:

```graphql
  "Включён ли модуль «Назначение курьера сборщиком» (courier_assignment в project_modules) — для гейтинга экранов в мобильном приложении"
  courier_assignment_enabled: Boolean
```

(Lighthouse резолвит по конвенции namespace `App\GraphQL\Queries` — как `heavy_orders_enabled` без явного `@field`.)

- [ ] **Step 5: Прогнать тест**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter=CourierAssignmentModuleTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Order.php app/GraphQL/Queries/CourierAssignmentEnabled.php graphql/Delivery/User/UserQueries.graphql tests/Feature/CourierAssignmentModuleTest.php
git commit -m "feat: флаг courier_assignment и query courier_assignment_enabled (MID-151)"
```

---

### Task 2: delivery_back — гейт DeliveryAssignCouriers на два флага

**Files:**
- Modify: `app/GraphQL/Validators/Delivery/DeliveryAssignCouriersValidator.php:21-24`
- Modify: `app/GraphQL/Mutations/Delivery/DeliveryAssignCouriers.php:19-21`
- Test: `tests/Feature/DeliveryAssignCouriersTest.php` (расширить)

**Interfaces:**
- Consumes: `Order::isCourierAssignmentModuleEnabled()` (Task 1).
- Produces: мутация `DeliveryAssignCouriers` работает при courier_assignment ON без heavy_orders; текст ошибки при обоих OFF — «Назначение курьеров отключено».

- [ ] **Step 1: Написать падающие тесты**

В `tests/Feature/DeliveryAssignCouriersTest.php` добавить helper рядом с `enableModule()` и 3 теста в конец класса:

```php
    private function enableCourierAssignmentModule(bool $enabled = true, bool $heavy = false): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode([
                'courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled],
                'heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => $heavy],
            ]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** @test */
    public function courier_assignment_module_allows_single_assignment_without_heavy_orders()
    {
        $this->enableCourierAssignmentModule(true, false);
        $orderId = $this->insertOrder(['is_heavy' => 0]);

        $response = $this->assign($orderId, [$this->courier1Id]);

        $response->assertJson(['data' => ['DeliveryAssignCouriers' => ['code' => 200]]]);
        $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
        // Платформенная семантика назначения: доставка стартует
        $this->assertSame(Status::ORDER_IN_DELIVERY, (int) Order::find($orderId)->status_id);
    }

    /** @test */
    public function courier_assignment_module_still_rejects_multiple_couriers_for_regular_order()
    {
        $this->enableCourierAssignmentModule(true, false);
        $orderId = $this->insertOrder(['is_heavy' => 0]);

        $response = $this->assign($orderId, [$this->courier1Id, $this->courier2Id]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }

    /** @test */
    public function mutation_rejected_when_both_modules_disabled()
    {
        $this->enableCourierAssignmentModule(false, false);
        $orderId = $this->insertOrder(['is_heavy' => 0]);

        $response = $this->assign($orderId, [$this->courier1Id]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertStringContainsString(
            'Назначение курьеров отключено',
            json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }
```

Существующий тест `mutation_rejected_when_module_disabled` (строка ~151) проверяет только наличие errors — текст не матчит, НЕ трогать.

- [ ] **Step 2: Убедиться что новые тесты падают**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter=DeliveryAssignCouriersTest`
Expected: FAIL — `courier_assignment_module_allows_single_assignment_without_heavy_orders` (ошибка «Тяжёлые заказы отключена» вместо 200) и `mutation_rejected_when_both_modules_disabled` (текст ошибки старый). Остальные PASS.

- [ ] **Step 3: Реализация**

`DeliveryAssignCouriersValidator.php` — заменить блок:

```php
          if (!Order::isHeavyOrdersModuleEnabled()) {
            $fail('Функция «Тяжёлые заказы» отключена');
            return;
          }
```

на:

```php
          if (!Order::isHeavyOrdersModuleEnabled() && !Order::isCourierAssignmentModuleEnabled()) {
            $fail('Назначение курьеров отключено');
            return;
          }
```

`DeliveryAssignCouriers.php` — заменить:

```php
    if (!Order::isHeavyOrdersModuleEnabled()) {
      throw new UserError('Функция «Тяжёлые заказы» отключена');
    }
```

на:

```php
    if (!Order::isHeavyOrdersModuleEnabled() && !Order::isCourierAssignmentModuleEnabled()) {
      throw new UserError('Назначение курьеров отключено');
    }
```

- [ ] **Step 4: Прогнать весь файл тестов**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter=DeliveryAssignCouriersTest`
Expected: PASS (все, включая старые heavy-тесты).

- [ ] **Step 5: Commit**

```bash
git add app/GraphQL/Validators/Delivery/DeliveryAssignCouriersValidator.php app/GraphQL/Mutations/Delivery/DeliveryAssignCouriers.php tests/Feature/DeliveryAssignCouriersTest.php
git commit -m "feat: DeliveryAssignCouriers доступна при модуле courier_assignment (MID-151)"
```

---

### Task 3: delivery_back — курьер видит только назначенные заказы

**Files:**
- Modify: `app/Models/Order.php` (scopes, после `scopeByAssemblyZone`)
- Modify: `graphql/Delivery/Order/OrderQueries.graphql` (orders_paginate + order)
- Test: `tests/Feature/OrderByCourierAssignmentScopeTest.php`

**Interfaces:**
- Consumes: `Order::isCourierAssignmentModuleEnabled()` (Task 1).
- Produces: scope `byCourierAssignment` в выдачах `orders_paginate` и `order`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/OrderByCourierAssignmentScopeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class OrderByCourierAssignmentScopeTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private int $shopId;
    private int $clientId;
    private User $courier;
    private User $otherCourier;
    private User $collector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Scope shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->courier = $this->makeStaff('courier');
        $this->otherCourier = $this->makeStaff('courier');
        $this->collector = $this->makeStaff('collector');
    }

    private function makeStaff(string $roleName): User
    {
        $userId = \DB::table('users')->insertGetId([
            'name' => ucfirst($roleName), 'surname' => 'Тестовый ' . uniqid(),
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
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
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'bcas-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function assignToCourier(int $orderId, int $courierId): void
    {
        $deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => $courierId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('delivery_orders')->insert([
            'delivery_id' => $deliveryId, 'order_id' => $orderId,
            'status_id' => Status::DELIVERY_WAIT_TO_START, 'priority' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function setModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private const QUERY = '
        query Orders($status: Int) {
            orders_paginate(status: $status, first: 50) { data { id } }
        }
    ';

    private function fetchIds(User $actor): array
    {
        $this->actingAs($actor, 'api');
        $response = $this->graphQL(self::QUERY, ['status' => Status::ORDER_ASSEMBLED]);

        return array_map('intval', array_column($response->json('data.orders_paginate.data') ?? [], 'id'));
    }

    /** @test */
    public function courier_sees_only_assigned_orders_when_module_enabled()
    {
        $this->setModule(true);
        $mineId = $this->insertOrder();
        $foreignId = $this->insertOrder();
        $unassignedId = $this->insertOrder();
        $this->assignToCourier($mineId, $this->courier->id);
        $this->assignToCourier($foreignId, $this->otherCourier->id);

        $ids = $this->fetchIds($this->courier);

        $this->assertContains($mineId, $ids);
        $this->assertNotContains($foreignId, $ids);
        $this->assertNotContains($unassignedId, $ids);
    }

    /** @test */
    public function courier_sees_all_assembled_orders_when_module_disabled()
    {
        $this->setModule(false);
        $mineId = $this->insertOrder();
        $unassignedId = $this->insertOrder();
        $this->assignToCourier($mineId, $this->courier->id);

        $ids = $this->fetchIds($this->courier);

        $this->assertContains($mineId, $ids);
        $this->assertContains($unassignedId, $ids);
    }

    /** @test */
    public function courier_sees_assigned_order_even_when_delivery_finished()
    {
        $this->setModule(true);
        $mineId = $this->insertOrder(['status_id' => Status::ORDER_FINISHED]);
        $deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => $this->courier->id, 'started_at' => now(), 'finished_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('delivery_orders')->insert([
            'delivery_id' => $deliveryId, 'order_id' => $mineId,
            'status_id' => Status::DELIVERY_FINISHED, 'priority' => 1,
            'started_at' => now(), 'finished_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->courier, 'api');
        $response = $this->graphQL(self::QUERY, ['status' => Status::ORDER_FINISHED]);
        $ids = array_map('intval', array_column($response->json('data.orders_paginate.data') ?? [], 'id'));

        $this->assertContains($mineId, $ids);
    }

    /** @test */
    public function collector_is_not_affected_by_module()
    {
        $this->setModule(true);
        $unassignedId = $this->insertOrder();

        $ids = $this->fetchIds($this->collector);

        $this->assertContains($unassignedId, $ids);
    }

    /** @test */
    public function single_order_query_hides_foreign_order_from_courier()
    {
        $this->setModule(true);
        $foreignId = $this->insertOrder();
        $this->assignToCourier($foreignId, $this->otherCourier->id);

        $this->actingAs($this->courier, 'api');
        $response = $this->graphQL('query Order($id: Int) { order(id: $id) { id } }', ['id' => $foreignId]);

        $this->assertNull($response->json('data.order'));
    }
}
```

- [ ] **Step 2: Убедиться что тесты падают**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter=OrderByCourierAssignmentScopeTest`
Expected: FAIL — `courier_sees_only_assigned_orders_when_module_enabled` (видит все 3) и `single_order_query_hides_foreign_order_from_courier` (заказ виден). Регрессионные (module disabled / collector) — PASS уже сейчас.

- [ ] **Step 3: Реализация**

`app/Models/Order.php` — после `scopeByAssemblyZone` (или после `scopeOnlyParentOrStandalone`):

```php
  /**
   * Модуль «Назначение курьера сборщиком» (courier_assignment): курьер видит
   * только заказы, назначенные ему (через его Delivery, включая завершённые
   * рейсы — доставленные сегодня остаются видимыми, как в scopeDaily).
   * При выключенном модуле и для остальных ролей — no-op.
   */
  public function scopeByCourierAssignment($query): void
  {
    if (!self::isCourierAssignmentModuleEnabled()) {
      return;
    }

    $user = auth()->user();
    if (!$user || !$user->hasRole(Role::COURIER)) {
      return;
    }

    $query->whereHas('deliveryOrders', function ($q) use ($user) {
      $q->whereHas('delivery', function ($q) use ($user) {
        $q->withoutGlobalScope(new NotFinished())->whereUserId($user->id);
      });
    });
  }
```

(`Role`, `NotFinished` уже импортированы в Order.php — проверить `use`-блок, при отсутствии добавить.)

`graphql/Delivery/Order/OrderQueries.graphql`:

```graphql
  orders_paginate(
    status:Int
  ):[Order] @paginate(scopes: ["filter","daily","currentShop","excludePickup","byRole","byAssemblyZone","byCourierAssignment"]) @guard
```

```graphql
  order(
    id:Int @eq
  ):Order @find(scopes: ["filter" ,"currentShop","byCourierAssignment"]) @guard
```

- [ ] **Step 4: Прогнать тесты + регрессию scope-тестов**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter=OrderByCourierAssignmentScopeTest`
Expected: PASS (5 tests).

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter='OrderByAssemblyZoneScopeTest|OrderDailyScopeTest'`
Expected: PASS (регрессия соседних scope).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Order.php graphql/Delivery/Order/OrderQueries.graphql tests/Feature/OrderByCourierAssignmentScopeTest.php
git commit -m "feat: курьер видит только назначенные заказы при модуле courier_assignment (MID-151)"
```

---

### Task 4: delivery_back — запрет самостоятельного взятия заказа курьером

**Files:**
- Modify: `app/GraphQL/Mutations/Delivery/DeliveryTakeOrder.php` (`__invoke`)
- Test: `tests/Feature/DeliveryTakeOrderCourierAssignmentTest.php`

**Interfaces:**
- Consumes: `Order::isCourierAssignmentModuleEnabled()` (Task 1).
- Produces: `DeliveryTakeOrder` (GraphQL) при флаге кидает «Заказы назначает сборщик»; static `takeOrder()` НЕ гейтится (REST-назначение из админки и DeliveryAssignCouriers продолжают работать).

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/DeliveryTakeOrderCourierAssignmentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class DeliveryTakeOrderCourierAssignmentTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private int $shopId;
    private int $clientId;
    private User $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Take shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $courierId = \DB::table('users')->insertGetId([
            'name' => 'Courier', 'surname' => 'Тестовый ' . uniqid(),
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->courier = User::findOrFail($courierId);
        $this->courier->assignRole(Role::findOrCreate('courier', 'api'));
        \DB::table('shifts')->insert([
            'user_id' => $courierId, 'shop_id' => $this->shopId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertOrder(): int
    {
        return \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'dtoc-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function setModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private const MUTATION = '
        mutation Take($order_id: Int!) {
            DeliveryTakeOrder(order_id: $order_id) { code message }
        }
    ';

    /** @test */
    public function courier_cannot_self_take_order_when_module_enabled()
    {
        $this->setModule(true);
        $orderId = $this->insertOrder();

        $this->actingAs($this->courier, 'api');
        $response = $this->graphQL(self::MUTATION, ['order_id' => $orderId]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertStringContainsString(
            'Заказы назначает сборщик',
            json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame(0, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }

    /** @test */
    public function courier_can_self_take_order_when_module_disabled()
    {
        $this->setModule(false);
        $orderId = $this->insertOrder();

        $this->actingAs($this->courier, 'api');
        $response = $this->graphQL(self::MUTATION, ['order_id' => $orderId]);

        $response->assertJson(['data' => ['DeliveryTakeOrder' => ['code' => 200]]]);
        $this->assertSame(1, \DB::table('delivery_orders')->where('order_id', $orderId)->count());
    }
}
```

Примечание: если схема `DeliveryTakeOrder` отличается (проверить `graphql/Delivery/Delivery/DeliveryTakeOrder.graphql` — имя аргумента и ability), скорректировать MUTATION и настройку смены под реальный гейт (`driverOnShift` требует активную доставку? — свериться со схемой; при `@can(ability: "driverOnShift")` достаточно роли courier + смены, если гейт иной — воспроизвести условия из схемы).

- [ ] **Step 2: Убедиться что первый тест падает**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter=DeliveryTakeOrderCourierAssignmentTest`
Expected: `courier_cannot_self_take_order_when_module_enabled` FAIL (мутация проходит), `courier_can_self_take_order_when_module_disabled` PASS.

- [ ] **Step 3: Реализация**

`DeliveryTakeOrder.php` — в начало `__invoke` (до `self::takeOrder`):

```php
    // Модуль courier_assignment: заказы распределяет сборщик, самостоятельное
    // взятие отключено. Guard только здесь: static takeOrder() обязан работать
    // для REST-назначения из админки и DeliveryAssignCouriers.
    if (\App\Models\Order::isCourierAssignmentModuleEnabled()) {
      throw new \GraphQL\Error\UserError('Заказы назначает сборщик');
    }
```

- [ ] **Step 4: Прогнать тесты + heavy-регрессию self-take**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter='DeliveryTakeOrderCourierAssignmentTest|DeliveryTakeOrderHeavyValidatorTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/GraphQL/Mutations/Delivery/DeliveryTakeOrder.php tests/Feature/DeliveryTakeOrderCourierAssignmentTest.php
git commit -m "feat: запрет self-take заказа курьером при модуле courier_assignment (MID-151)"
```

---

### Task 5: delivery_back — скип общего пуша «заказ готов к доставке»

**Files:**
- Modify: `app/GraphQL/Mutations/Assembly/AssemblyFinish.php` (`notifyCouriersOrderReady`, ~строка 121)
- Test: `tests/Feature/AssemblyFinishCourierAssignmentPushTest.php`

**Interfaces:**
- Consumes: `Order::isCourierAssignmentModuleEnabled()` (Task 1).
- Produces: при флаге пуш `order_ready_for_delivery` всем курьерам смены не отправляется.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/AssemblyFinishCourierAssignmentPushTest.php` (вызов приватного static через рефлексию — как в тестах с protected-методами, паттерн OrphanedAssemblyItemsCleanupTest):

```php
<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AssemblyFinishCourierAssignmentPushTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Push shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Курьер на смене магазина — чтобы был получатель пуша
        $courierId = \DB::table('users')->insertGetId([
            'name' => 'Courier', 'surname' => 'Тестовый ' . uniqid(),
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        User::findOrFail($courierId)->assignRole(Role::findOrCreate('courier', 'api'));
        \DB::table('shifts')->insert([
            'user_id' => $courierId, 'shop_id' => $this->shopId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeAssembledOrder(): Order
    {
        $orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'afp-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Order::findOrFail($orderId);
    }

    private function setModule(bool $enabled): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function callNotify(Order $order): void
    {
        $method = new \ReflectionMethod(AssemblyFinish::class, 'notifyCouriersOrderReady');
        $method->setAccessible(true);
        $method->invoke(null, $order);
    }

    /** @test */
    public function broadcast_push_skipped_when_module_enabled()
    {
        $this->setModule(true);
        $this->mock(FcmNotificationService::class)
            ->shouldNotReceive('sendToUsers');

        $this->callNotify($this->makeAssembledOrder());
    }

    /** @test */
    public function broadcast_push_sent_when_module_disabled()
    {
        $this->setModule(false);
        $this->mock(FcmNotificationService::class)
            ->shouldReceive('sendToUsers')->once();

        $this->callNotify($this->makeAssembledOrder());
    }
}
```

- [ ] **Step 2: Убедиться что тест падает**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter=AssemblyFinishCourierAssignmentPushTest`
Expected: `broadcast_push_skipped_when_module_enabled` FAIL (sendToUsers вызван), второй PASS.

- [ ] **Step 3: Реализация**

`AssemblyFinish.php`, `notifyCouriersOrderReady` — после guard'а `if ($order->status_id !== Status::ORDER_ASSEMBLED)`:

```php
    // Модуль courier_assignment: курьеры не видят общий список — общий пуш
    // не имеет смысла, назначенному курьеру пуш шлёт DeliveryAssignCouriers.
    if (Order::isCourierAssignmentModuleEnabled()) {
      return;
    }
```

- [ ] **Step 4: Прогнать тест + push-регрессию**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter='AssemblyFinishCourierAssignmentPushTest|PushNotificationZoneTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/GraphQL/Mutations/Assembly/AssemblyFinish.php tests/Feature/AssemblyFinishCourierAssignmentPushTest.php
git commit -m "feat: скип общего пуша order_ready_for_delivery при модуле courier_assignment (MID-151)"
```

---

### Task 6: delivery_back — docstring shift_couriers + API-контракт для мобильной команды

**Files:**
- Modify: `graphql/Delivery/User/UserQueries.graphql` (docstring shift_couriers)
- Create: `docs/specs/2026-07-17-courier-assignment-api-contract.md`

**Interfaces:**
- Consumes: всё из задач 1-5 (описывает готовое поведение).
- Produces: контракт для мобильной разработки (subtask Platrum 12183).

- [ ] **Step 1: Обновить docstring**

В `UserQueries.graphql` заменить:

```graphql
  "Курьеры на смене магазина текущего сборщика (экран назначения курьеров, флоу «Тяжёлый заказ»)"
```

на:

```graphql
  "Курьеры на смене магазина текущего сборщика (экран назначения курьеров: модули heavy_orders и courier_assignment)"
```

- [ ] **Step 2: Написать контракт**

`docs/specs/2026-07-17-courier-assignment-api-contract.md` — по структуре
`2026-07-07-heavy-order-collector-courier-api-contract.md`: гейт
`courier_assignment_enabled`; флоу сборщика (кнопка «Назначить курьера» после
AssemblyFinish для ВСЕХ заказов → `shift_couriers` → `DeliveryAssignCouriers`
с одним id); поведение курьера (общий список отдаёт только назначенные,
self-take `DeliveryTakeOrder` возвращает ошибку «Заказы назначает сборщик»,
рейс/пуш `order_assigned` без изменений); тексты ошибок; отличия от
heavy-флоу; таблица push-типов (order_ready_for_delivery при включённом
модуле не приходит). Без длинных тире в тексте (правило исходящих документов).

- [ ] **Step 3: Прогнать полную регрессию фичи**

Run: `DB_HOST=127.0.0.1 vendor/bin/phpunit --filter='CourierAssignment|DeliveryAssignCouriers|DeliveryTakeOrder|OrderByCourierAssignment|AssemblyFinishCourierAssignmentPush'`
Expected: PASS (все тесты фичи + соседи).

- [ ] **Step 4: Commit**

```bash
git add graphql/Delivery/User/UserQueries.graphql docs/specs/2026-07-17-courier-assignment-api-contract.md docs/superpowers/specs/2026-07-17-courier-assignment-design.md docs/superpowers/plans/2026-07-17-courier-assignment.md
git commit -m "docs: контракт courier_assignment для мобильной команды + спека/план (MID-151)"
```

---

### Task 7: admin_back — тумблер модуля в «Настройки → Модули»

**Files:**
- Modify: `app/GraphQL/Queries/AdminSetting/AdminSettingQueries.php` (`getDefaultModulesConfig`, после ключа `heavy_orders`)
- Test: `tests/Feature/CourierAssignmentModuleDefaultTest.php`

**Interfaces:**
- Consumes: ничего из других задач (независимый репозиторий mendev_admin_back).
- Produces: ключ `courier_assignment` в merged-конфиге projectModules → тумблер в UI админки.

- [ ] **Step 1: Создать ветку**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_admin_back
git checkout -b feature/courier-assignment
```

- [ ] **Step 2: Написать падающий тест**

`tests/Feature/CourierAssignmentModuleDefaultTest.php` (по образцу HeavyOrdersModuleTest):

```php
<?php

namespace Tests\Feature;

use App\GraphQL\Queries\AdminSetting\AdminSettingQueries;
use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CourierAssignmentModuleDefaultTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function courier_assignment_module_present_in_defaults_and_disabled()
    {
        AdminSetting::where('key', 'project_modules')->delete();

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertArrayHasKey('courier_assignment', $decoded);
        $this->assertFalse($decoded['courier_assignment']['enabled']);
        $this->assertSame('Назначение курьера сборщиком', $decoded['courier_assignment']['label']);
    }

    /** @test */
    public function courier_assignment_merged_into_stored_blob()
    {
        AdminSetting::setValue('project_modules', json_encode([
            'bonuses' => ['label' => 'Бонусная система', 'enabled' => true],
        ]), 'json');

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertTrue($decoded['bonuses']['enabled']);
        $this->assertArrayHasKey('courier_assignment', $decoded);
        $this->assertFalse($decoded['courier_assignment']['enabled']);
    }
}
```

- [ ] **Step 3: Убедиться что тест падает**

Run: `vendor/bin/phpunit --filter=CourierAssignmentModuleDefaultTest`
Expected: FAIL (`courier_assignment` отсутствует).

- [ ] **Step 4: Реализация**

`AdminSettingQueries.php`, `getDefaultModulesConfig()` — после блока `heavy_orders`:

```php
            'courier_assignment' => [
                'label' => 'Назначение курьера сборщиком',
                'enabled' => false,
                'description' => 'Сборщик назначает курьера по завершении сборки; курьеры видят только назначенные им заказы, самостоятельное взятие заказов отключено',
            ],
```

- [ ] **Step 5: Прогнать тесты**

Run: `vendor/bin/phpunit --filter='CourierAssignmentModuleDefaultTest|HeavyOrdersModuleTest'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/GraphQL/Queries/AdminSetting/AdminSettingQueries.php tests/Feature/CourierAssignmentModuleDefaultTest.php
git commit -m "feat: модуль courier_assignment в project_modules (MID-151)"
```

---

### Task 8: Финальная регрессия

- [ ] **Step 1: delivery_back — прогнать затронутые группы**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_delivery_back
DB_HOST=127.0.0.1 vendor/bin/phpunit --filter='CourierAssignment|DeliveryAssignCouriers|DeliveryTakeOrder|AssemblyFinish|OrderBy|OrderDaily|PushNotification|ShiftCouriers'
```

Expected: PASS, кроме известных pre-existing падений (`AssemblyFinishPickupReadyAtTest` — нет роли courier в mendev_test; сверить с `git stash`-прогоном при сомнении).

- [ ] **Step 2: admin_back — прогнать группу модулей**

```bash
cd /Users/daibelbuzarov/PhpstormProjects/mendev/mendev_admin_back
vendor/bin/phpunit --filter='ModuleTest|ModuleDefault'
```

Expected: PASS.

- [ ] **Step 3: Диффы zero-change при выключенном флаге**

Просмотреть `git diff master` в обоих репо: каждая правка существующего кода — либо добавление ранних return/расширение гейта условием на новый флаг, либо новые файлы. Изменений старого поведения при выключенном флаге нет.
