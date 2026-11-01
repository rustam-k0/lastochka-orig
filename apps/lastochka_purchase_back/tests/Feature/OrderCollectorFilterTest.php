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
 * Фильтр по сборщикам в списке заказов АП.
 * Код 0 — виртуальный «Без сборщика».
 * Спека: docs/superpowers/specs/2026-07-31-orders-collector-filter-design.md
 */
class OrderCollectorFilterTest extends TestCase
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
        $userId = \DB::table('users')->insertGetId([
            'name' => 'Test',
            'email' => uniqid('ocol_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole(Role::findOrCreate(Role::ADMIN, 'api'));

        return $user->createToken('test')->plainTextToken;
    }

    private function gql(string $token, string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    private function makeOrder(string $marker, array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'shop_id' => $this->makeShop('OCOL shop ' . $marker),
            'client_id' => $this->makeClient(),
            'status_id' => Status::ORDER_ASSEMBLED,
            'type_id' => Type::RECEIVE_DELIVERY,
            'payment_type_id' => 1,
            'uuid' => 'ocol-' . $marker . '-' . uniqid(),
        ], $attrs));
    }

    private function makeCollector(string $marker): int
    {
        return \DB::table('users')->insertGetId([
            'name' => 'Collector ' . $marker,
            'email' => uniqid('ocolc_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assemble(int $orderId, int $collectorId, bool $deleted = false): int
    {
        return \DB::table('assemblies')->insertGetId([
            'order_id' => $orderId,
            'user_id' => $collectorId,
            'deleted_at' => $deleted ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * first: 100 — максимум, разрешённый lighthouse.php (max_count).
     *
     * @return array<int>
     */
    private function idsFor(string $token, string $collectorsArg): array
    {
        $resp = $this->gql($token, '{ admin_orders_paginate(collectors: ' . $collectorsArg . ', first: 100) { data { id } } }');
        $resp->assertJsonMissingPath('errors');

        return collect($resp->json('data.admin_orders_paginate.data'))->pluck('id')->all();
    }

    public function test_filters_by_single_collector(): void
    {
        $token = $this->makeStaffToken();
        $collectorA = $this->makeCollector('a1');
        $collectorB = $this->makeCollector('b1');
        $orderA = $this->makeOrder('a1');
        $orderB = $this->makeOrder('b1');
        $this->assemble($orderA->id, $collectorA);
        $this->assemble($orderB->id, $collectorB);

        $ids = $this->idsFor($token, '[' . $collectorA . ']');

        $this->assertContains($orderA->id, $ids);
        $this->assertNotContains($orderB->id, $ids);
    }

    public function test_filters_by_several_collectors(): void
    {
        $token = $this->makeStaffToken();
        $collectorA = $this->makeCollector('a2');
        $collectorB = $this->makeCollector('b2');
        $collectorC = $this->makeCollector('c2');
        $orderA = $this->makeOrder('a2');
        $orderB = $this->makeOrder('b2');
        $orderC = $this->makeOrder('c2');
        $this->assemble($orderA->id, $collectorA);
        $this->assemble($orderB->id, $collectorB);
        $this->assemble($orderC->id, $collectorC);

        $ids = $this->idsFor($token, '[' . $collectorA . ', ' . $collectorB . ']');

        $this->assertContains($orderA->id, $ids);
        $this->assertContains($orderB->id, $ids);
        $this->assertNotContains($orderC->id, $ids);
    }

    public function test_zero_code_returns_orders_without_collector(): void
    {
        $token = $this->makeStaffToken();
        $collector = $this->makeCollector('a3');
        $assembled = $this->makeOrder('a3');
        $bare = $this->makeOrder('b3');
        $this->assemble($assembled->id, $collector);

        $ids = $this->idsFor($token, '[0]');

        $this->assertContains($bare->id, $ids);
        $this->assertNotContains($assembled->id, $ids);
    }

    public function test_collector_and_zero_code_compose_via_or(): void
    {
        $token = $this->makeStaffToken();
        $collectorA = $this->makeCollector('a4');
        $collectorB = $this->makeCollector('b4');
        $orderA = $this->makeOrder('a4');
        $orderB = $this->makeOrder('b4');
        $bare = $this->makeOrder('c4');
        $this->assemble($orderA->id, $collectorA);
        $this->assemble($orderB->id, $collectorB);

        $ids = $this->idsFor($token, '[0, ' . $collectorA . ']');

        $this->assertContains($orderA->id, $ids);
        $this->assertContains($bare->id, $ids);
        $this->assertNotContains($orderB->id, $ids);
    }

    public function test_child_order_assembly_matches_parent(): void
    {
        $token = $this->makeStaffToken();
        $collector = $this->makeCollector('a5');
        $parent = $this->makeOrder('p5');
        $child = $this->makeOrder('c5', [
            'parent_order_id' => $parent->id,
            'shop_id' => $parent->shop_id,
            'client_id' => $parent->client_id,
        ]);
        $this->assemble($child->id, $collector);

        $ids = $this->idsFor($token, '[' . $collector . ']');

        $this->assertContains($parent->id, $ids);
    }

    public function test_soft_deleted_assembly_is_ignored(): void
    {
        $token = $this->makeStaffToken();
        $collector = $this->makeCollector('a6');
        $order = $this->makeOrder('a6');
        $this->assemble($order->id, $collector, true);

        $byCollector = $this->idsFor($token, '[' . $collector . ']');
        $withoutCollector = $this->idsFor($token, '[0]');

        $this->assertNotContains($order->id, $byCollector);
        $this->assertContains($order->id, $withoutCollector);
    }

    /**
     * Переназначение сборщика: старая сборка мягко удалена, активна новая.
     * Фильтр обязан отдавать заказ по текущему сборщику и не отдавать по
     * прежнему — так же, как колонка «Сборщик» (OrderCollectorResolver).
     */
    public function test_reassigned_collector_replaces_previous(): void
    {
        $token = $this->makeStaffToken();
        $previous = $this->makeCollector('a7');
        $current = $this->makeCollector('b7');
        $order = $this->makeOrder('a7');
        $this->assemble($order->id, $previous, true);
        $this->assemble($order->id, $current);

        $byPrevious = $this->idsFor($token, '[' . $previous . ']');
        $byCurrent = $this->idsFor($token, '[' . $current . ']');

        $this->assertNotContains($order->id, $byPrevious);
        $this->assertContains($order->id, $byCurrent);
    }

    /**
     * Расщеплённый заказ: подзаказы собирают разные сборщики. Фильтр по
     * любому из них возвращает родителя (см. раздел «Семантика при
     * расщеплённых заказах» в спеке).
     */
    public function test_split_order_matches_by_any_of_its_collectors(): void
    {
        $token = $this->makeStaffToken();
        $collectorA = $this->makeCollector('a8');
        $collectorB = $this->makeCollector('b8');
        $parent = $this->makeOrder('p8');
        $childA = $this->makeOrder('ca8', [
            'parent_order_id' => $parent->id,
            'shop_id' => $parent->shop_id,
            'client_id' => $parent->client_id,
        ]);
        $childB = $this->makeOrder('cb8', [
            'parent_order_id' => $parent->id,
            'shop_id' => $parent->shop_id,
            'client_id' => $parent->client_id,
        ]);
        $this->assemble($childA->id, $collectorA);
        $this->assemble($childB->id, $collectorB);

        $this->assertContains($parent->id, $this->idsFor($token, '[' . $collectorA . ']'));
        $this->assertContains($parent->id, $this->idsFor($token, '[' . $collectorB . ']'));
    }
}
