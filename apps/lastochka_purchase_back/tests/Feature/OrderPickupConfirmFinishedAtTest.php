<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * finished_at при выдаче самовывоза (раздел «Магазины», статистика по выручке):
 * OrderPickupConfirm при переводе в статус 5 должен ставить finished_at,
 * но НЕ перезаписывать уже проставленное значение (гейт finished_at ??= now()).
 */
class OrderPickupConfirmFinishedAtTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): int
    {
        return \DB::table('shops')->insertGetId([
            'title' => 'Pickup fin shop ' . uniqid(),
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
            'name' => 'Иван',
            'surname' => 'Выдающий',
            'email' => uniqid('pcf_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole($role);

        return $user->createToken('test')->plainTextToken;
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'shop_id' => $this->makeShop(),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'type_id' => Type::RECEIVE_PICKUP,
            'status_id' => Status::ORDER_ASSEMBLED,
        ], $attrs));
    }

    private function gql(string $token, string $query)
    {
        return $this->postJson('/graphql-purchase', ['query' => $query], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    public function test_it_sets_finished_at_on_confirm(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 12, 0, 0));

        try {
            $token = $this->makeStaffToken();
            $order = $this->makeOrder(['finished_at' => null]);

            $resp = $this->gql($token, 'mutation { OrderPickupConfirm(id: ' . $order->id . ') { code } }');
            $resp->assertJsonPath('data.OrderPickupConfirm.code', 200);

            $order->refresh();
            $this->assertSame(Status::ORDER_FINISHED, $order->status_id);
            $this->assertNotNull($order->finished_at);
            $this->assertSame('2026-07-15 12:00:00', $order->finished_at->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_does_not_overwrite_existing_finished_at(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 12, 0, 0));

        try {
            $token = $this->makeStaffToken();
            $preset = Carbon::create(2020, 1, 1, 8, 0, 0);
            $order = $this->makeOrder(['finished_at' => $preset]);

            $resp = $this->gql($token, 'mutation { OrderPickupConfirm(id: ' . $order->id . ') { code } }');
            $resp->assertJsonPath('data.OrderPickupConfirm.code', 200);

            $order->refresh();
            $this->assertSame(Status::ORDER_FINISHED, $order->status_id);
            $this->assertSame('2020-01-01 08:00:00', $order->finished_at->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
