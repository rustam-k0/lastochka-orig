<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Раздел «Магазины», вкладка «Статистика» (Platrum, дизайн 2026-07-10):
 * GraphQL shop_statistics — выручка (status 5 по finished_at), количество
 * (по created_at, кроме отменённых 6) и активные заказы по статусам.
 * В статистику попадают ТОЛЬКО корневые заказы (parent_order_id IS NULL),
 * иначе сплит по зонам сборки задваивает выручку и счётчики.
 */
class ShopStatisticsTest extends TestCase
{
    use DatabaseTransactions;

    /** Замороженное «сейчас» — середина месяца, чтобы сегодня/месяц/период не пересекались случайно. */
    private Carbon $now;

    private int $shopId;
    private int $otherShopId;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::create(2026, 7, 15, 10, 0, 0);
        Carbon::setTestNow($this->now);

        $this->shopId = $this->makeShop('Stat shop ' . uniqid());
        $this->otherShopId = $this->makeShop('Other shop ' . uniqid());
        $this->clientId = $this->makeClient();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeShop(string $title): int
    {
        return \DB::table('shops')->insertGetId([
            'title' => $title,
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 1,
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
            'name' => 'Стат',
            'surname' => 'Смотрящий',
            'email' => uniqid('stat_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Прямая вставка заказа (минуя fillable/события) для точного контроля
     * created_at / finished_at / slot_start_date / parent_order_id.
     */
    private function insertOrder(array $attrs): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'client_id' => $this->clientId,
            'type_id' => Type::RECEIVE_DELIVERY,
            'payment_type_id' => 1,
            'status_id' => Status::ORDER_FINISHED,
            'amount' => 1000,
            'parent_order_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function gql(string $token, string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    private function query(int $shopId, string $extraArgs = '', string $body = ''): string
    {
        $body = $body ?: 'revenue_today { sum orders_count } revenue_month { sum orders_count } '
            . 'revenue_period { sum orders_count } orders_today orders_month orders_period '
            . 'active_orders { new waiting_collector assembling ready_for_delivery in_delivery }';

        return '{ shop_statistics(shop_id: ' . $shopId . $extraArgs . ') { ' . $body . ' } }';
    }

    public function test_revenue_counts_only_finished_own_shop_period_and_excludes_suborders(): void
    {
        $token = $this->makeStaffToken();

        // Корневой parent для подзаказа
        $parentId = $this->insertOrder(['status_id' => Status::ORDER_FINISHED, 'finished_at' => now(), 'amount' => 0]);

        // Учитывается: сегодня, статус 5, свой магазин, корневой
        $this->insertOrder(['status_id' => Status::ORDER_FINISHED, 'finished_at' => now()->copy()->setTime(9, 0), 'amount' => 1000]);
        // Учитывается только в месяце (3 июля — этот месяц, но не сегодня)
        $this->insertOrder(['status_id' => Status::ORDER_FINISHED, 'finished_at' => Carbon::create(2026, 7, 3, 12, 0), 'amount' => 2000]);
        // Прошлый месяц — не сегодня и не в этом месяце
        $this->insertOrder(['status_id' => Status::ORDER_FINISHED, 'finished_at' => Carbon::create(2026, 6, 20, 12, 0), 'amount' => 4000]);
        // Подзаказ — исключён (parent_order_id != null)
        $this->insertOrder(['status_id' => Status::ORDER_FINISHED, 'finished_at' => now(), 'amount' => 500, 'parent_order_id' => $parentId]);
        // Не статус 5 — исключён из выручки
        $this->insertOrder(['status_id' => Status::ORDER_IN_DELIVERY, 'finished_at' => now(), 'amount' => 999]);
        // Другой магазин — исключён
        $this->insertOrder(['shop_id' => $this->otherShopId, 'status_id' => Status::ORDER_FINISHED, 'finished_at' => now(), 'amount' => 7777]);

        $resp = $this->gql($token, $this->query($this->shopId, ', month: "2026-07"'));
        $resp->assertJsonMissingPath('errors');

        // Сегодня: только заказ 1000 (parent имеет amount 0 и finished сегодня → тоже сегодня! учтём)
        // parent finished_at = now() amount 0 → входит в сегодня и месяц, но 0 не меняет сумму.
        $this->assertSame(1000.0, (float) $resp->json('data.shop_statistics.revenue_today.sum'));
        $this->assertSame(2, $resp->json('data.shop_statistics.revenue_today.orders_count')); // parent(0) + 1000

        // Месяц: parent(0) + 1000 + 2000 = 3000, count 3
        $this->assertSame(3000.0, (float) $resp->json('data.shop_statistics.revenue_month.sum'));
        $this->assertSame(3, $resp->json('data.shop_statistics.revenue_month.orders_count'));
    }

    public function test_revenue_period_null_when_not_passed_and_computed_when_passed(): void
    {
        $token = $this->makeStaffToken();

        $this->insertOrder(['status_id' => Status::ORDER_FINISHED, 'finished_at' => Carbon::create(2026, 7, 10, 12, 0), 'amount' => 1500]);
        $this->insertOrder(['status_id' => Status::ORDER_FINISHED, 'finished_at' => Carbon::create(2026, 7, 25, 12, 0), 'amount' => 3000]);

        // Без периода — revenue_period / orders_period = null
        $resp = $this->gql($token, $this->query($this->shopId));
        $resp->assertJsonMissingPath('errors');
        $this->assertNull($resp->json('data.shop_statistics.revenue_period'));
        $this->assertNull($resp->json('data.shop_statistics.orders_period'));

        // С периодом 05-07..20-07 — только первый заказ (1500)
        $resp2 = $this->gql($token, $this->query($this->shopId, ', period_from: "2026-07-05", period_to: "2026-07-20"'));
        $resp2->assertJsonMissingPath('errors');
        $this->assertSame(1500.0, (float) $resp2->json('data.shop_statistics.revenue_period.sum'));
        $this->assertSame(1, $resp2->json('data.shop_statistics.revenue_period.orders_count'));
    }

    public function test_orders_count_by_created_at_excludes_cancelled(): void
    {
        $token = $this->makeStaffToken();

        // Сегодня, не отменённые
        $this->insertOrder(['status_id' => Status::ORDER_FINISHED, 'created_at' => now()->copy()->setTime(9, 0), 'finished_at' => now()]);
        $this->insertOrder(['status_id' => Status::ORDER_CREATED, 'created_at' => now()->copy()->setTime(8, 0), 'finished_at' => null]);
        // Сегодня, отменён (6) — исключён
        $this->insertOrder(['status_id' => Status::ORDER_CANCELED, 'created_at' => now(), 'finished_at' => null]);
        // Этот месяц, но не сегодня
        $this->insertOrder(['status_id' => Status::ORDER_CREATED, 'created_at' => Carbon::create(2026, 7, 2, 12, 0), 'finished_at' => null]);
        // Подзаказ сегодня — исключён
        $parentId = $this->insertOrder(['status_id' => Status::ORDER_CREATED, 'created_at' => now(), 'finished_at' => null]);
        $this->insertOrder(['status_id' => Status::ORDER_CREATED, 'created_at' => now(), 'finished_at' => null, 'parent_order_id' => $parentId]);

        $resp = $this->gql($token, $this->query($this->shopId, ', month: "2026-07"'));
        $resp->assertJsonMissingPath('errors');

        // Сегодня: 2 (finished) + (created 8:00) + parent(created) = 3 корневых не-отменённых сегодня
        $this->assertSame(3, $resp->json('data.shop_statistics.orders_today'));
        // Месяц: + заказ 2 июля = 4
        $this->assertSame(4, $resp->json('data.shop_statistics.orders_month'));
    }

    public function test_active_orders_buckets_and_slot_boundaries(): void
    {
        $token = $this->makeStaffToken();
        $endOfDay = now()->copy()->endOfDay();

        // new: статус 1, слот после конца сегодня
        $this->insertOrder(['status_id' => Status::ORDER_CREATED, 'slot_start_date' => $endOfDay->copy()->addHour(), 'finished_at' => null]);
        // waiting_collector: статус 1, слот <= конца сегодня
        $this->insertOrder(['status_id' => Status::ORDER_CREATED, 'slot_start_date' => now()->copy()->setTime(20, 0), 'finished_at' => null]);
        // waiting_collector: статус 1, слот NULL
        $this->insertOrder(['status_id' => Status::ORDER_CREATED, 'slot_start_date' => null, 'finished_at' => null]);
        // assembling (2), ready_for_delivery (3), in_delivery (4)
        $this->insertOrder(['status_id' => Status::ORDER_PREPARING, 'finished_at' => null]);
        $this->insertOrder(['status_id' => Status::ORDER_ASSEMBLED, 'finished_at' => null]);
        $this->insertOrder(['status_id' => Status::ORDER_IN_DELIVERY, 'finished_at' => null]);
        // не активные: 5 и 6
        $this->insertOrder(['status_id' => Status::ORDER_FINISHED, 'finished_at' => now()]);
        $this->insertOrder(['status_id' => Status::ORDER_CANCELED, 'finished_at' => null]);
        // подзаказ (2) — исключён
        $parentId = $this->insertOrder(['status_id' => Status::ORDER_PREPARING, 'finished_at' => null]);
        $this->insertOrder(['status_id' => Status::ORDER_PREPARING, 'finished_at' => null, 'parent_order_id' => $parentId]);
        // другой магазин (1) — исключён
        $this->insertOrder(['shop_id' => $this->otherShopId, 'status_id' => Status::ORDER_CREATED, 'slot_start_date' => null, 'finished_at' => null]);

        $resp = $this->gql($token, $this->query($this->shopId));
        $resp->assertJsonMissingPath('errors');

        $a = $resp->json('data.shop_statistics.active_orders');
        $this->assertSame(1, $a['new']);
        $this->assertSame(2, $a['waiting_collector']);
        $this->assertSame(2, $a['assembling']); // 1 одиночный + 1 parent (подзаказ исключён)
        $this->assertSame(1, $a['ready_for_delivery']);
        $this->assertSame(1, $a['in_delivery']);
    }

    public function test_pickup_orders_excluded_from_delivery_buckets(): void
    {
        // MID-146: собранный самовывоз не «Готов к доставке» и не «В доставке» —
        // доставки у него нет, после сборки он ждёт выдачи. До сборки
        // (создан/ожидает/в сборке) самовывоз считается как обычный заказ.
        $token = $this->makeStaffToken();

        $this->insertOrder(['type_id' => Type::RECEIVE_PICKUP, 'status_id' => Status::ORDER_CREATED, 'slot_start_date' => null, 'finished_at' => null]);
        $this->insertOrder(['type_id' => Type::RECEIVE_PICKUP, 'status_id' => Status::ORDER_PREPARING, 'finished_at' => null]);
        $this->insertOrder(['type_id' => Type::RECEIVE_PICKUP, 'status_id' => Status::ORDER_ASSEMBLED, 'finished_at' => null]);
        $this->insertOrder(['type_id' => Type::RECEIVE_PICKUP, 'status_id' => Status::ORDER_IN_DELIVERY, 'finished_at' => null]);
        // Доставка для контраста — учитывается
        $this->insertOrder(['status_id' => Status::ORDER_ASSEMBLED, 'finished_at' => null]);

        $resp = $this->gql($token, $this->query($this->shopId));
        $resp->assertJsonMissingPath('errors');

        $a = $resp->json('data.shop_statistics.active_orders');
        $this->assertSame(1, $a['waiting_collector']);
        $this->assertSame(1, $a['assembling']);
        $this->assertSame(1, $a['ready_for_delivery']);
        $this->assertSame(0, $a['in_delivery']);
    }

    public function test_validation_period_over_92_days(): void
    {
        $token = $this->makeStaffToken();

        $resp = $this->gql($token, $this->query($this->shopId, ', period_from: "2026-01-01", period_to: "2026-12-31"'));

        $this->assertSame('validation', $resp->json('errors.0.extensions.category'));
    }

    public function test_validation_bad_month_format(): void
    {
        $token = $this->makeStaffToken();

        $resp = $this->gql($token, $this->query($this->shopId, ', month: "2026/07"'));

        $this->assertSame('validation', $resp->json('errors.0.extensions.category'));
    }

    public function test_validation_period_from_after_to(): void
    {
        $token = $this->makeStaffToken();

        $resp = $this->gql($token, $this->query($this->shopId, ', period_from: "2026-07-20", period_to: "2026-07-01"'));

        $this->assertSame('validation', $resp->json('errors.0.extensions.category'));
    }
}
