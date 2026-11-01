<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * T1 — раздел «Магазины» вкладками (admin_back):
 * ShopFilter — поиск и фильтры списка магазинов (shops_paginate).
 *
 *  - search: LIKE по title OR address OR code (сгруппировано в замыкание,
 *    чтобы не сломать остальные фильтры).
 *  - active (Boolean): true → status_id = 1; false → status_id != 1.
 *  - delivery_enabled (Boolean): true → есть активная зона доставки
 *    (whereHas zones is_active=1); false → нет ни одной активной зоны
 *    (whereDoesntHave) — включая магазины без зон вовсе.
 *  - pickup_enabled (Boolean): совпадение по колонке is_pickup_enabled.
 *
 * Матрица доступов включена (role_access_matrix_enabled='1'). Админ —
 * глобальный (scope_type=global), проходит onlyAdmin у shops_paginate.
 *
 * Каждый фикстур-магазин помечен уникальным токеном в title, а запросы
 * скоупятся этим токеном через search — так результат ограничен фикстурами
 * теста независимо от прочих данных в mendev_test.
 */
class ShopFilterTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    protected User $admin;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::setValue('role_access_matrix_enabled', '1', 'boolean');

        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();

        $adminRole = Role::findOrCreate(Role::ADMIN);
        $adminRole->scope_type = Role::SCOPE_GLOBAL;
        $adminRole->save();

        $this->admin = User::create([
            'name' => 'Shop Filter Admin',
            'phone' => '+7902' . mt_rand(1000000, 9999999),
            'email' => uniqid('shopfilter_admin_') . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $this->admin->assignRole($adminRole);
        $this->assertTrue($this->admin->fresh()->isGlobalScope());

        // Уникальный токен теста: попадает в title каждого фикстура и
        // используется как search-скоуп, чтобы ограничить выборку фикстурами.
        $this->token = 'zzt' . mt_rand(1000000, 9999999);
    }

    private function createShop(array $attrs = []): Shop
    {
        return Shop::create(array_merge([
            'title' => 'Shop ' . $this->token . ' ' . uniqid(),
            'code' => 'code-' . uniqid(),
            'address' => 'addr-' . uniqid(),
            'status_id' => Status::CODE_ACTIVE,
        ], $attrs));
    }

    private function createZone(Shop $shop, bool $isActive): ShopZone
    {
        return ShopZone::create([
            'shop_id' => $shop->id,
            'name' => 'Зона ' . uniqid(),
            'delivery_price' => 200,
            'min_order_price' => 600,
            'delivery_type' => 'own',
            'is_active' => $isActive,
        ]);
    }

    /**
     * Возвращает список id магазинов, отданных shops_paginate под фильтрами.
     * Неуказанные фильтры уходят как null (QueryFilter их пропускает).
     */
    private function queryShopIds(array $filters): array
    {
        $vars = array_merge([
            'search' => null,
            'active' => null,
            'delivery_enabled' => null,
            'pickup_enabled' => null,
            'ids' => null,
        ], $filters);

        $query = '
            query ($search: String, $active: Boolean, $delivery_enabled: Boolean, $pickup_enabled: Boolean, $ids: [Int]) {
                shops_paginate(
                    first: 100
                    search: $search
                    active: $active
                    delivery_enabled: $delivery_enabled
                    pickup_enabled: $pickup_enabled
                    ids: $ids
                ) {
                    data {
                        id
                        title
                        address
                        code
                        is_pickup_enabled
                    }
                }
            }
        ';

        $response = $this->actingAs($this->admin)->graphQL($query, $vars);
        $json = $response->json();

        $this->assertArrayNotHasKey(
            'errors',
            $json,
            'GraphQL errors: ' . json_encode($json['errors'] ?? null, JSON_UNESCAPED_UNICODE)
        );

        return collect($json['data']['shops_paginate']['data'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ----------------------------------------------------------------------
    //  search — по title / address / code + не-матч
    // ----------------------------------------------------------------------

    /** @test */
    public function search_matches_by_title(): void
    {
        $needle = 'нужное-название-' . uniqid();
        $match = $this->createShop(['title' => 'Магазин ' . $this->token . ' ' . $needle]);
        $other = $this->createShop(); // токен есть, но needle нет

        $ids = $this->queryShopIds(['search' => $needle]);

        $this->assertContains($match->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    /** @test */
    public function search_matches_by_address(): void
    {
        $needle = 'ленинапроспект' . uniqid();
        $match = $this->createShop(['address' => 'г. Москва, ' . $needle . ', д.1']);
        $other = $this->createShop();

        $ids = $this->queryShopIds(['search' => $needle]);

        $this->assertContains($match->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    /** @test */
    public function search_matches_by_code(): void
    {
        $needle = 'uniqcode' . mt_rand(100000, 999999);
        $match = $this->createShop(['code' => $needle]);
        $other = $this->createShop();

        $ids = $this->queryShopIds(['search' => $needle]);

        $this->assertContains($match->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    /** @test */
    public function search_no_match_returns_empty(): void
    {
        $this->createShop();
        $this->createShop();

        $ids = $this->queryShopIds(['search' => 'absent-needle-' . uniqid()]);

        $this->assertSame([], $ids, 'Несуществующий запрос не должен ничего возвращать');
    }

    // ----------------------------------------------------------------------
    //  active — true / false / absent
    // ----------------------------------------------------------------------

    /** @test */
    public function active_true_returns_only_active(): void
    {
        $active = $this->createShop(['status_id' => Status::CODE_ACTIVE]);
        $inactive = $this->createShop(['status_id' => Status::CODE_NOT_PUBLISHED]);

        $ids = $this->queryShopIds(['search' => $this->token, 'active' => true]);

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    /** @test */
    public function active_false_returns_only_inactive(): void
    {
        $active = $this->createShop(['status_id' => Status::CODE_ACTIVE]);
        $inactive = $this->createShop(['status_id' => Status::CODE_NOT_PUBLISHED]);

        // active=false — bool false НЕ должен отбрасываться QueryFilter::apply
        $ids = $this->queryShopIds(['search' => $this->token, 'active' => false]);

        $this->assertContains($inactive->id, $ids);
        $this->assertNotContains($active->id, $ids);
    }

    /** @test */
    public function active_absent_returns_both(): void
    {
        $active = $this->createShop(['status_id' => Status::CODE_ACTIVE]);
        $inactive = $this->createShop(['status_id' => Status::CODE_NOT_PUBLISHED]);

        $ids = $this->queryShopIds(['search' => $this->token]);

        $this->assertContains($active->id, $ids);
        $this->assertContains($inactive->id, $ids);
    }

    // ----------------------------------------------------------------------
    //  delivery_enabled — true / false (whereHas / whereDoesntHave активной зоны)
    // ----------------------------------------------------------------------

    /** @test */
    public function delivery_enabled_true_returns_shops_with_active_zone(): void
    {
        $withActiveZone = $this->createShop();
        $this->createZone($withActiveZone, true);

        $withInactiveZone = $this->createShop();
        $this->createZone($withInactiveZone, false);

        $withoutZone = $this->createShop();

        $ids = $this->queryShopIds(['search' => $this->token, 'delivery_enabled' => true]);

        $this->assertContains($withActiveZone->id, $ids);
        $this->assertNotContains($withInactiveZone->id, $ids);
        $this->assertNotContains($withoutZone->id, $ids);
    }

    /** @test */
    public function delivery_enabled_false_returns_shops_without_active_zone(): void
    {
        $withActiveZone = $this->createShop();
        $this->createZone($withActiveZone, true);

        $withInactiveZone = $this->createShop();
        $this->createZone($withInactiveZone, false);

        $withoutZone = $this->createShop();

        $ids = $this->queryShopIds(['search' => $this->token, 'delivery_enabled' => false]);

        $this->assertNotContains($withActiveZone->id, $ids);
        $this->assertContains($withInactiveZone->id, $ids);
        $this->assertContains($withoutZone->id, $ids);
    }

    // ----------------------------------------------------------------------
    //  pickup_enabled — true / false
    // ----------------------------------------------------------------------

    /** @test */
    public function pickup_enabled_true_returns_only_pickup_shops(): void
    {
        $pickupOn = $this->createShop(['is_pickup_enabled' => true]);
        $pickupOff = $this->createShop(['is_pickup_enabled' => false]);

        $ids = $this->queryShopIds(['search' => $this->token, 'pickup_enabled' => true]);

        $this->assertContains($pickupOn->id, $ids);
        $this->assertNotContains($pickupOff->id, $ids);
    }

    /** @test */
    public function pickup_enabled_false_returns_only_non_pickup_shops(): void
    {
        $pickupOn = $this->createShop(['is_pickup_enabled' => true]);
        $pickupOff = $this->createShop(['is_pickup_enabled' => false]);

        $ids = $this->queryShopIds(['search' => $this->token, 'pickup_enabled' => false]);

        $this->assertContains($pickupOff->id, $ids);
        $this->assertNotContains($pickupOn->id, $ids);
    }

    // ----------------------------------------------------------------------
    //  Комбинация фильтров
    // ----------------------------------------------------------------------

    /** @test */
    public function combined_filters_are_intersected(): void
    {
        // Целевой: активный + самовывоз + попадает в поиск.
        $target = $this->createShop([
            'status_id' => Status::CODE_ACTIVE,
            'is_pickup_enabled' => true,
        ]);

        // Активный, но без самовывоза — отсеивается pickup_enabled.
        $noPickup = $this->createShop([
            'status_id' => Status::CODE_ACTIVE,
            'is_pickup_enabled' => false,
        ]);

        // Самовывоз есть, но не активен — отсеивается active.
        $inactive = $this->createShop([
            'status_id' => Status::CODE_NOT_PUBLISHED,
            'is_pickup_enabled' => true,
        ]);

        $ids = $this->queryShopIds([
            'search' => $this->token,
            'active' => true,
            'pickup_enabled' => true,
        ]);

        $this->assertContains($target->id, $ids);
        $this->assertNotContains($noPickup->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    /** @test */
    public function search_grouping_does_not_leak_across_active_filter(): void
    {
        // Регрессионный тест на группировку OR: search-условия должны быть
        // в замыкании, иначе active=true через AND «утечёт» и вернёт
        // неактивный магазин, чьё имя совпало по поиску.
        $needle = 'grptoken' . mt_rand(100000, 999999);
        $activeMatch = $this->createShop([
            'title' => 'Активный ' . $this->token . ' ' . $needle,
            'status_id' => Status::CODE_ACTIVE,
        ]);
        $inactiveMatch = $this->createShop([
            'title' => 'Неактивный ' . $this->token . ' ' . $needle,
            'status_id' => Status::CODE_NOT_PUBLISHED,
        ]);

        $ids = $this->queryShopIds(['search' => $needle, 'active' => true]);

        $this->assertContains($activeMatch->id, $ids);
        $this->assertNotContains(
            $inactiveMatch->id,
            $ids,
            'active=true не должен возвращать неактивный магазин даже при совпадении поиска'
        );
    }

    // ----------------------------------------------------------------------
    //  ids — подгрузка выбранных магазинов по id (MID-310: выбранный магазин
    //  в форме редактирования может не попасть в первую страницу списка)
    // ----------------------------------------------------------------------

    /** @test */
    public function ids_returns_only_requested_shops(): void
    {
        $a = $this->createShop();
        $b = $this->createShop();
        $c = $this->createShop();

        $ids = $this->queryShopIds(['ids' => [$a->id, $c->id]]);

        $this->assertEqualsCanonicalizing([$a->id, $c->id], $ids);
        $this->assertNotContains($b->id, $ids);
    }

    /** @test */
    public function empty_ids_is_ignored(): void
    {
        $a = $this->createShop();
        $b = $this->createShop();

        $ids = $this->queryShopIds(['search' => $this->token, 'ids' => []]);

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }
}
