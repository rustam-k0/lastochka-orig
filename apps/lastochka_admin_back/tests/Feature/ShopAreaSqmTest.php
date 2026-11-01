<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ShopAreaSqmTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

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

    // slot_time/slot_max_orders/type_id обязательны в ShopUpdateValidator для
    // глобального админа (adminRequiredRule()) — без них ShopUpdate падает на
    // валидации раньше, чем успевает дойти до area_sqm. Паттерн скопирован из
    // ShopHeavyOrderThresholdTest (существующий тест того же admin-блока fillInfo).
    public function test_shop_update_saves_area_sqm(): void
    {
        $user = $this->makeAdminUser();
        $shop = Shop::create(['title' => 'Тестовый магазин ' . uniqid()]);

        $response = $this->actingAs($user)->graphQL('
            mutation (
                $id: Int
                $title: String
                $slot_time: Int
                $slot_max_orders: Int
                $type_id: Int
                $area_sqm: Float
            ) {
                ShopUpdate(
                    id: $id
                    title: $title
                    slot_time: $slot_time
                    slot_max_orders: $slot_max_orders
                    type_id: $type_id
                    area_sqm: $area_sqm
                ) { code message }
            }
        ', [
            'id' => $shop->id,
            'title' => $shop->title,
            'slot_time' => 60,
            'slot_max_orders' => 10,
            'type_id' => Type::SHOP_TYPE_STORE,
            'area_sqm' => 120.5,
        ]);

        $response->assertJson(['data' => ['ShopUpdate' => ['code' => 200]]]);
        $this->assertSame(120.5, (float) $shop->fresh()->area_sqm);
    }

    public function test_shop_update_rejects_negative_area_sqm(): void
    {
        $user = $this->makeAdminUser();
        $shop = Shop::create(['title' => 'Тестовый магазин ' . uniqid(), 'area_sqm' => 50]);

        $response = $this->actingAs($user)->graphQL('
            mutation (
                $id: Int
                $title: String
                $slot_time: Int
                $slot_max_orders: Int
                $type_id: Int
                $area_sqm: Float
            ) {
                ShopUpdate(
                    id: $id
                    title: $title
                    slot_time: $slot_time
                    slot_max_orders: $slot_max_orders
                    type_id: $type_id
                    area_sqm: $area_sqm
                ) { code message }
            }
        ', [
            'id' => $shop->id,
            'title' => $shop->title,
            'slot_time' => 60,
            'slot_max_orders' => 10,
            'type_id' => Type::SHOP_TYPE_STORE,
            'area_sqm' => -5,
        ]);

        $response->assertGraphQLErrorMessage('Validation failed for the field [ShopUpdate].');
        $this->assertSame(50.0, (float) $shop->fresh()->area_sqm);
    }
}
