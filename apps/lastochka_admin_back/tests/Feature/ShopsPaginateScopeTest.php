<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * MID-199: список магазинов (shops_paginate) должен быть доступен shop-scope
 * ролям с разделом «Магазины» (гейт onlyShop + scope accessible) — под старым
 * onlyAdmin роль «Зам админа» получала «This action is unauthorized» и раздел
 * был пуст.
 *
 * Смежное (найдено при разборе MID-202): ShopUpdate за
 * @section(key:"my_store", anyOf:["stores"]) — глобальная кастомная роль
 * с section.stores должна сохранять магазин, хотя гранта my_store у неё нет.
 */
class ShopsPaginateScopeTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private Shop $ownShop;
    private Shop $otherShop;
    private User $shopUser;
    private User $globalUser;

    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::setValue('role_access_matrix_enabled', '1', 'boolean');

        $this->ownShop = Shop::create(['title' => 'Paginate own shop ' . uniqid()]);
        $this->otherShop = Shop::create(['title' => 'Paginate other shop ' . uniqid()]);

        $storesSection = Permission::findOrCreate('section.stores');

        $shopRole = Role::findOrCreate('role_shops_paginate_shop_test');
        $shopRole->scope_type = Role::SCOPE_SHOP;
        $shopRole->save();
        $shopRole->givePermissionTo($storesSection);

        $globalRole = Role::findOrCreate('role_shops_paginate_global_test');
        $globalRole->scope_type = Role::SCOPE_GLOBAL;
        $globalRole->save();
        $globalRole->givePermissionTo($storesSection);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->shopUser = User::factory()->create();
        $this->shopUser->assignRole($shopRole);
        UserShop::create(['shop_id' => $this->ownShop->id, 'user_id' => $this->shopUser->id]);

        $this->globalUser = User::factory()->create();
        $this->globalUser->assignRole($globalRole);
    }

    private function paginateIds($response): array
    {
        return array_column($response->json('data.shops_paginate.data'), 'id');
    }

    public function test_shop_scope_role_sees_only_own_shops(): void
    {
        $response = $this->actingAs($this->shopUser)->graphQL(
            '{ shops_paginate(first: 100) { data { id } } }'
        );

        $response->assertJson(fn ($json) => $json->missing('errors')->etc());
        $ids = $this->paginateIds($response);
        $this->assertContains($this->ownShop->id, $ids);
        $this->assertNotContains($this->otherShop->id, $ids);
    }

    public function test_global_custom_role_sees_all_shops(): void
    {
        $response = $this->actingAs($this->globalUser)->graphQL(
            '{ shops_paginate(first: 100) { data { id } } }'
        );

        $response->assertJson(fn ($json) => $json->missing('errors')->etc());
        $ids = $this->paginateIds($response);
        $this->assertContains($this->ownShop->id, $ids);
        $this->assertContains($this->otherShop->id, $ids);
    }

    public function test_role_without_stores_section_denied(): void
    {
        $role = Role::findOrCreate('role_shops_paginate_denied_test');
        $role->scope_type = Role::SCOPE_SHOP;
        $role->save();
        $role->givePermissionTo(Permission::findOrCreate('section.orders'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($role);
        UserShop::create(['shop_id' => $this->ownShop->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->graphQL(
            '{ shops_paginate(first: 10) { data { id } } }'
        );

        // Локально APP_DEBUG=true — Lighthouse отдаёт кастомный текст директивы
        // (на проде клиент видит дефолтное "This action is unauthorized.").
        $response->assertGraphQLErrorMessage('Нет доступа к разделу: stores');
    }

    public function test_global_custom_role_with_stores_can_shop_update(): void
    {
        $newTitle = 'Updated by global custom role ' . uniqid();

        // slot_time / slot_max_orders / type_id — required для глобальных
        // (ShopUpdateValidator::adminRequiredRule).
        $response = $this->actingAs($this->globalUser)->graphQL(
            'mutation ($id: Int, $title: String, $slot_time: Int, $slot_max_orders: Int, $type_id: Int) {
              ShopUpdate(id: $id, title: $title, slot_time: $slot_time, slot_max_orders: $slot_max_orders, type_id: $type_id) { code message }
            }',
            [
                'id' => $this->ownShop->id,
                'title' => $newTitle,
                'slot_time' => 60,
                'slot_max_orders' => 5,
                'type_id' => 1,
            ]
        );

        $response->assertJson(fn ($json) => $json->missing('errors')->etc());
        $this->assertSame(200, $response->json('data.ShopUpdate.code'));
        $this->assertSame($newTitle, $this->ownShop->fresh()->title);
    }
}
