<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ручная отметка оплаты наличного заказа из карточки заказа в АП
 * (мутация OrderMarkPaid): только наличные, не отменённые; ставит
 * paid/paid_at/payment_status на заказе и подзаказах, пишет историю.
 */
class OrderMarkPaidTest extends TestCase
{
    use DatabaseTransactions;

    private function setFlag(string $value): void
    {
        AdminSetting::setValue('role_access_matrix_enabled', $value, 'boolean');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
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

    /**
     * @return array{0: User, 1: string, 2: Role}
     */
    private function makeStaff(string $roleName = Role::ADMIN, array $roleAttrs = []): array
    {
        $role = Role::findOrCreate($roleName, 'api');
        if ($roleAttrs !== []) {
            $role->forceFill($roleAttrs)->save();
        }

        $userId = \DB::table('users')->insertGetId([
            'name' => 'Иван',
            'surname' => 'Кассиров',
            'email' => uniqid('omp_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return [$user, $user->createToken('test')->plainTextToken, $role];
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'shop_id' => $this->makeShop('OMP test shop ' . uniqid()),
            'client_id' => $this->makeClient(),
            'payment_type_id' => Type::PAYMENT_CASH,
            'type_id' => Type::RECEIVE_DELIVERY,
            'status_id' => Status::ORDER_ASSEMBLED,
            'paid' => false,
            'paid_at' => null,
        ], $attrs));
    }

    private function gql(string $token, string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    private function markPaid(string $token, int $orderId)
    {
        return $this->gql($token, 'mutation { OrderMarkPaid(id: ' . $orderId . ') { code message } }');
    }

    public function test_it_marks_cash_order_as_paid_and_logs_history(): void
    {
        $this->setFlag('0');
        [$staff, $token] = $this->makeStaff();
        $order = $this->makeOrder();

        $resp = $this->markPaid($token, $order->id);

        $resp->assertJsonMissingPath('errors');
        $resp->assertJsonPath('data.OrderMarkPaid.code', 200);

        $order->refresh();
        $this->assertTrue((bool) $order->paid);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('paid', $order->payment_status);
        $this->assertTrue($order->is_paid);

        $row = OrderHistory::where('order_id', $order->id)
            ->where('action', OrderHistory::ACTION_PAYMENT_FIXED)
            ->first();
        $this->assertNotNull($row, 'В истории заказа должна появиться запись payment_fixed');
        $this->assertSame(OrderHistory::ACTOR_STAFF, $row->actor_type);
        $this->assertSame($staff->id, $row->actor_id);
        $this->assertSame('Кассиров Иван', $row->actor_name);
        $this->assertSame('Не оплачен', $row->old_value);
        $this->assertSame('Оплачен', $row->new_value);
    }

    public function test_graphql_exposes_is_paid_and_paid_at_after_marking(): void
    {
        $this->setFlag('0');
        [, $token] = $this->makeStaff();
        $order = $this->makeOrder();

        $this->markPaid($token, $order->id);

        $resp = $this->gql($token, '{ admin_orders_item(id: ' . $order->id . ') { is_paid paid_at payment_status } }');

        $resp->assertJsonMissingPath('errors');
        $this->assertTrue($resp->json('data.admin_orders_item.is_paid'));
        $this->assertNotNull($resp->json('data.admin_orders_item.paid_at'));
        $this->assertSame('paid', $resp->json('data.admin_orders_item.payment_status'));
    }

    public function test_it_marks_sub_orders_as_paid_too(): void
    {
        $this->setFlag('0');
        [, $token] = $this->makeStaff();
        $parent = $this->makeOrder();
        $child = $this->makeOrder([
            'shop_id' => $parent->shop_id,
            'client_id' => $parent->client_id,
            'parent_order_id' => $parent->id,
        ]);

        $this->markPaid($token, $parent->id)->assertJsonPath('data.OrderMarkPaid.code', 200);

        $child->refresh();
        $this->assertTrue((bool) $child->paid);
        $this->assertNotNull($child->paid_at);
        $this->assertSame('paid', $child->payment_status);
    }

    public function test_it_rejects_sub_order_itself(): void
    {
        $this->setFlag('0');
        [, $token] = $this->makeStaff();
        $parent = $this->makeOrder();
        $child = $this->makeOrder([
            'shop_id' => $parent->shop_id,
            'client_id' => $parent->client_id,
            'parent_order_id' => $parent->id,
        ]);

        $this->markPaid($token, $child->id)->assertJsonPath('data.OrderMarkPaid.code', 422);

        $parent->refresh();
        $child->refresh();
        $this->assertFalse((bool) $parent->paid);
        $this->assertFalse((bool) $child->paid);
    }

    public function test_it_rejects_card_order(): void
    {
        $this->setFlag('0');
        [, $token] = $this->makeStaff();
        $order = $this->makeOrder(['payment_type_id' => Type::PAYMENT_CARD]);

        $resp = $this->markPaid($token, $order->id);

        $resp->assertJsonPath('data.OrderMarkPaid.code', 422);
        $order->refresh();
        $this->assertFalse((bool) $order->paid);
        $this->assertNull($order->paid_at);
        $this->assertSame(0, OrderHistory::where('order_id', $order->id)->count());
    }

    public function test_it_rejects_cancelled_order(): void
    {
        $this->setFlag('0');
        [, $token] = $this->makeStaff();
        $order = $this->makeOrder(['status_id' => Status::ORDER_CANCELLED]);

        $resp = $this->markPaid($token, $order->id);

        $resp->assertJsonPath('data.OrderMarkPaid.code', 422);
        $order->refresh();
        $this->assertFalse((bool) $order->paid);
    }

    public function test_it_is_idempotent_for_already_paid_order(): void
    {
        $this->setFlag('0');
        [, $token] = $this->makeStaff();
        $paidAt = now()->subDay()->startOfSecond();
        $order = $this->makeOrder(['paid' => true, 'paid_at' => $paidAt, 'payment_status' => 'paid']);

        $resp = $this->markPaid($token, $order->id);

        $resp->assertJsonPath('data.OrderMarkPaid.code', 200);
        $order->refresh();
        // Дата первой оплаты не перезаписывается, история не дублируется
        $this->assertSame($paidAt->toDateTimeString(), $order->paid_at->toDateTimeString());
        $this->assertSame(0, OrderHistory::where('order_id', $order->id)->count());
    }

    public function test_it_allows_finished_cash_order(): void
    {
        // Курьер уже доставил, деньги получены, админ фиксирует факт постфактум
        $this->setFlag('0');
        [, $token] = $this->makeStaff();
        $order = $this->makeOrder(['status_id' => Status::ORDER_FINISHED]);

        $this->markPaid($token, $order->id)->assertJsonPath('data.OrderMarkPaid.code', 200);
        $this->assertTrue((bool) $order->refresh()->paid);
    }

    public function test_staff_without_permission_is_blocked_under_matrix(): void
    {
        $this->setFlag('1');
        [, $token] = $this->makeStaff('role_no_mark_paid', ['scope_type' => Role::SCOPE_GLOBAL, 'is_system' => false]);
        $order = $this->makeOrder();

        $resp = $this->markPaid($token, $order->id);

        $this->assertStringContainsString(
            'Нет доступа к действию: mark_paid',
            json_encode($resp->json('errors'), JSON_UNESCAPED_UNICODE)
        );
        $this->assertFalse((bool) $order->refresh()->paid);
    }

    public function test_staff_with_permission_is_allowed_under_matrix(): void
    {
        $this->setFlag('1');
        [, $token, $role] = $this->makeStaff('role_can_mark_paid', ['scope_type' => Role::SCOPE_GLOBAL, 'is_system' => false]);
        $role->givePermissionTo(Permission::findOrCreate('orders.mark_paid', 'api'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $order = $this->makeOrder();

        $resp = $this->markPaid($token, $order->id);

        $resp->assertJsonMissingPath('errors');
        $resp->assertJsonPath('data.OrderMarkPaid.code', 200);
        $this->assertTrue((bool) $order->refresh()->paid);
    }

    public function test_client_guard_cannot_mark_paid(): void
    {
        $this->setFlag('0');
        $order = $this->makeOrder();

        $resp = $this->postJson('/graphql-purchase', [
            'query' => 'mutation { OrderMarkPaid(id: ' . $order->id . ') { code } }',
        ]);

        $this->assertNotNull($resp->json('errors'));
        $this->assertFalse((bool) $order->refresh()->paid);
    }
}
