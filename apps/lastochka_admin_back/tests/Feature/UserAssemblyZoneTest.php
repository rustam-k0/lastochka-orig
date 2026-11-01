<?php

namespace Tests\Feature;

use App\Models\AssemblyZone;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserAssemblyZone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class UserAssemblyZoneTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Самодостаточный admin: не зависим от seed-данных конкретной БД.
        $adminRole = Role::firstOrCreate(
            ['name' => Role::ADMIN, 'guard_name' => 'api'],
            ['name_ru' => 'Администратор'],
        );
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->adminUser = $this->makeUser();
        $this->adminUser->assignRole($adminRole);
    }

    private function makeUser(): User
    {
        return User::create([
            'name'    => 'Тест',
            'surname' => 'Сборщик',
            'phone'   => '+7' . mt_rand(9000000000, 9999999999),
            'email'   => 'collector_' . uniqid() . '@test.local',
        ]);
    }

    private function makeShop(): Shop
    {
        return Shop::create([
            'title' => 'Магазин ' . uniqid(),
        ]);
    }

    private function makeZone(string $title = 'Зона'): AssemblyZone
    {
        return AssemblyZone::create([
            'title'    => $title . ' ' . uniqid(),
            'color'    => '#3B82F6',
            'position' => 0,
        ]);
    }

    // ---- resolveAssemblyZoneId ----

    /** @test */
    public function resolve_returns_permanent_binding(): void
    {
        $user = $this->makeUser();
        $shop = $this->makeShop();
        $zone = $this->makeZone();

        UserAssemblyZone::create([
            'user_id'          => $user->id,
            'shop_id'          => $shop->id,
            'assembly_zone_id' => $zone->id,
            'date'             => null,
        ]);

        $this->assertSame($zone->id, $user->fresh()->resolveAssemblyZoneId($shop->id));
    }

    /** @test */
    public function resolve_prefers_dated_binding_over_permanent(): void
    {
        $user = $this->makeUser();
        $shop = $this->makeShop();
        $permanentZone = $this->makeZone('Постоянная');
        $datedZone = $this->makeZone('На дату');

        UserAssemblyZone::create([
            'user_id'          => $user->id,
            'shop_id'          => $shop->id,
            'assembly_zone_id' => $permanentZone->id,
            'date'             => null,
        ]);
        UserAssemblyZone::create([
            'user_id'          => $user->id,
            'shop_id'          => $shop->id,
            'assembly_zone_id' => $datedZone->id,
            'date'             => Carbon::today()->toDateString(),
        ]);

        $this->assertSame($datedZone->id, $user->fresh()->resolveAssemblyZoneId($shop->id));
    }

    /** @test */
    public function resolve_returns_null_when_unbound(): void
    {
        $user = $this->makeUser();
        $shop = $this->makeShop();

        $this->assertNull($user->resolveAssemblyZoneId($shop->id));
    }

    /** @test */
    public function resolve_is_scoped_per_shop(): void
    {
        $user = $this->makeUser();
        $shopA = $this->makeShop();
        $shopB = $this->makeShop();
        $zone = $this->makeZone();

        UserAssemblyZone::create([
            'user_id'          => $user->id,
            'shop_id'          => $shopA->id,
            'assembly_zone_id' => $zone->id,
            'date'             => null,
        ]);

        $this->assertSame($zone->id, $user->fresh()->resolveAssemblyZoneId($shopA->id));
        $this->assertNull($user->fresh()->resolveAssemblyZoneId($shopB->id));
    }

    // ---- мутации ----

    private const ASSIGN = '
        mutation ($user_id: Int!, $shop_id: Int!, $assembly_zone_id: Int, $date: Date) {
            UserAssemblyZoneAssign(user_id: $user_id, shop_id: $shop_id, assembly_zone_id: $assembly_zone_id, date: $date) {
                code
                message
            }
        }
    ';

    private const BULK = '
        mutation ($user_ids: [Int!]!, $shop_id: Int!, $assembly_zone_id: Int, $date: Date) {
            UserAssemblyZoneBulkAssign(user_ids: $user_ids, shop_id: $shop_id, assembly_zone_id: $assembly_zone_id, date: $date) {
                code
                message
            }
        }
    ';

    /** @test */
    public function assign_creates_permanent_binding(): void
    {
        $user = $this->makeUser();
        $shop = $this->makeShop();
        $zone = $this->makeZone();

        $this->actingAs($this->adminUser)
            ->graphQL(self::ASSIGN, [
                'user_id'          => $user->id,
                'shop_id'          => $shop->id,
                'assembly_zone_id' => $zone->id,
            ])
            ->assertJsonMissing(['errors']);

        $this->assertDatabaseHas('user_assembly_zones', [
            'user_id'          => $user->id,
            'shop_id'          => $shop->id,
            'assembly_zone_id' => $zone->id,
            'date'             => null,
        ]);
    }

    /** @test */
    public function assign_with_null_zone_detaches(): void
    {
        $user = $this->makeUser();
        $shop = $this->makeShop();
        $zone = $this->makeZone();

        UserAssemblyZone::create([
            'user_id'          => $user->id,
            'shop_id'          => $shop->id,
            'assembly_zone_id' => $zone->id,
            'date'             => null,
        ]);

        $this->actingAs($this->adminUser)
            ->graphQL(self::ASSIGN, [
                'user_id'          => $user->id,
                'shop_id'          => $shop->id,
                'assembly_zone_id' => null,
            ])
            ->assertJsonMissing(['errors']);

        $this->assertDatabaseMissing('user_assembly_zones', [
            'user_id' => $user->id,
            'shop_id' => $shop->id,
        ]);
    }

    /** @test */
    public function assign_updates_existing_binding_without_duplicate(): void
    {
        $user = $this->makeUser();
        $shop = $this->makeShop();
        $zoneA = $this->makeZone('A');
        $zoneB = $this->makeZone('B');

        $this->actingAs($this->adminUser)->graphQL(self::ASSIGN, [
            'user_id' => $user->id, 'shop_id' => $shop->id, 'assembly_zone_id' => $zoneA->id,
        ])->assertJsonMissing(['errors']);

        $this->actingAs($this->adminUser)->graphQL(self::ASSIGN, [
            'user_id' => $user->id, 'shop_id' => $shop->id, 'assembly_zone_id' => $zoneB->id,
        ])->assertJsonMissing(['errors']);

        $this->assertSame(1, UserAssemblyZone::where('user_id', $user->id)->where('shop_id', $shop->id)->count());
        $this->assertSame($zoneB->id, $user->fresh()->resolveAssemblyZoneId($shop->id));
    }

    /** @test */
    public function bulk_assign_binds_all_users(): void
    {
        $u1 = $this->makeUser();
        $u2 = $this->makeUser();
        $shop = $this->makeShop();
        $zone = $this->makeZone();

        $this->actingAs($this->adminUser)
            ->graphQL(self::BULK, [
                'user_ids'         => [$u1->id, $u2->id],
                'shop_id'          => $shop->id,
                'assembly_zone_id' => $zone->id,
            ])
            ->assertJsonMissing(['errors']);

        $this->assertSame($zone->id, $u1->fresh()->resolveAssemblyZoneId($shop->id));
        $this->assertSame($zone->id, $u2->fresh()->resolveAssemblyZoneId($shop->id));
    }

    /** @test */
    public function assembly_zone_create_persists_new_fields(): void
    {
        $mutation = '
            mutation ($title: String!, $color: String, $is_active: Boolean, $description: String) {
                AssemblyZoneCreate(title: $title, color: $color, is_active: $is_active, description: $description) {
                    code
                    message
                }
            }
        ';

        $title = 'Холодный цех ' . uniqid();

        $this->actingAs($this->adminUser)
            ->graphQL($mutation, [
                'title'       => $title,
                'color'       => '#FF0000',
                'is_active'   => false,
                'description' => 'Зона для салатов',
            ])
            ->assertJsonMissing(['errors']);

        $this->assertDatabaseHas('assembly_zones', [
            'title'       => $title,
            'color'       => '#FF0000',
            'is_active'   => 0,
            'description' => 'Зона для салатов',
        ]);
    }
}
