<?php

namespace Tests\Feature;

use App\Models\OrderCancelReason;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class OrderCancelReasonCrudTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    /**
     * Изолированный фикстурный пользователь с ролью admin — для тестов,
     * которым требуется onlyAdmin.
     */
    private function makeAdminUser(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'surname' => 'Fixture',
            'email' => 'admin_' . uniqid() . '@example.com',
            'phone' => '+7900' . random_int(1000000, 9999999),
            'password' => bcrypt('password123'),
        ]);
        $user->assignRole(Role::findOrCreate(Role::ADMIN));

        return $user;
    }

    public function test_can_create_update_and_delete_reason(): void
    {
        $user = $this->makeAdminUser();

        $create = $this->actingAs($user)->graphQL('
            mutation ($title: String!, $is_active: Boolean, $position: Int) {
                OrderCancelReasonCreate(title: $title, is_active: $is_active, position: $position) {
                    code
                    message
                }
            }
        ', [
            'title' => 'Тестовая причина',
            'is_active' => true,
            'position' => 99,
        ]);

        $create->assertJson(['data' => ['OrderCancelReasonCreate' => ['code' => 200]]]);

        $reason = OrderCancelReason::where('title', 'Тестовая причина')->firstOrFail();

        $update = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $title: String) {
                OrderCancelReasonUpdate(id: $id, title: $title) {
                    code
                    message
                }
            }
        ', [
            'id' => $reason->id,
            'title' => 'Обновлённая причина',
        ]);

        $update->assertJson(['data' => ['OrderCancelReasonUpdate' => ['code' => 200]]]);
        $this->assertSame('Обновлённая причина', $reason->fresh()->title);

        $delete = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) {
                OrderCancelReasonDelete(id: $id) {
                    code
                    message
                }
            }
        ', ['id' => $reason->id]);

        $delete->assertJson(['data' => ['OrderCancelReasonDelete' => ['code' => 200]]]);
        $this->assertSoftDeleted('order_cancel_reasons', ['id' => $reason->id]);
    }
}
