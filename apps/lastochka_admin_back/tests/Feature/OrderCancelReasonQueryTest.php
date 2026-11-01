<?php

namespace Tests\Feature;

use App\Models\OrderCancelReason;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class OrderCancelReasonQueryTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test OrderCancelReasons
        OrderCancelReason::create([
            'title' => 'Причина 1',
            'is_active' => true,
            'position' => 1,
        ]);

        OrderCancelReason::create([
            'title' => 'Причина 2',
            'is_active' => true,
            'position' => 2,
        ]);
    }

    /**
     * Изолированный фикстурный пользователь БЕЗ роли admin — доказывает,
     * что orderCancelReasons доступен любому авторизованному сотруднику,
     * а не только администратору.
     */
    private function makeStaffUser(): User
    {
        $user = User::create([
            'name' => 'Staff',
            'surname' => 'Fixture',
            'email' => 'staff_' . uniqid() . '@example.com',
            'phone' => '+7900' . random_int(1000000, 9999999),
            'password' => bcrypt('password123'),
        ]);

        // Role::SHOP явно приводим к scope_type=shop (не global), чтобы
        // фикстура гарантированно НЕ проходила onlyAdmin ни при выключенной,
        // ни при включённой матрице доступов (role_access_matrix_enabled).
        $role = Role::findOrCreate(Role::SHOP);
        if ($role->scope_type !== Role::SCOPE_SHOP) {
            $role->update(['scope_type' => Role::SCOPE_SHOP]);
        }
        $user->assignRole($role);

        return $user;
    }

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

    public function test_order_cancel_reasons_returns_only_active(): void
    {
        $staff = $this->makeStaffUser();

        $inactive = OrderCancelReason::first();
        $inactive->update(['is_active' => false]);

        $active = OrderCancelReason::where('id', '!=', $inactive->id)->firstOrFail();

        $response = $this->actingAs($staff)->graphQL('
            query {
                orderCancelReasons {
                    id
                    title
                }
            }
        ');

        $ids = collect($response->json('data.orderCancelReasons'))->pluck('id');

        // Позитивная проверка: активная причина реально присутствует в ответе.
        // Если поле когда-нибудь получит @can(ability: "onlyAdmin") и этот
        // не-админ фикстурный пользователь потеряет доступ, Lighthouse вернёт
        // null, коллекция будет пустой — и эта проверка упадёт (а не молча
        // пройдёт, как было бы с одной лишь негативной проверкой).
        $this->assertGreaterThan(0, $ids->count());
        $this->assertTrue($ids->contains($active->id));

        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_all_order_cancel_reasons_returns_inactive_too(): void
    {
        $admin = $this->makeAdminUser();

        $inactive = OrderCancelReason::first();
        $inactive->update(['is_active' => false]);

        $response = $this->actingAs($admin)->graphQL('
            query {
                allOrderCancelReasons {
                    id
                    is_active
                }
            }
        ');

        $ids = collect($response->json('data.allOrderCancelReasons'))->pluck('id');

        $this->assertTrue($ids->contains($inactive->id));
    }
}
