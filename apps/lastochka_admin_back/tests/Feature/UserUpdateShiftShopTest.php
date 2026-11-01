<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * ALADDIN-13554: открытая смена хранит снапшот shop_id с момента селфи.
 * После смены магазинов сотрудника через UserUpdate открытая смена должна
 * переехать на актуальный магазин — иначе профиль в приложении, ShiftCouriers
 * и фильтр зон сборки продолжают жить в старом магазине.
 */
class UserUpdateShiftShopTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private const MUTATION = /** @lang GraphQL */ '
        mutation ($id: Int, $name: String, $surname: String, $phone: String, $shops: [Int]) {
            UserUpdate(id: $id, name: $name, surname: $surname, phone: $phone, shops: $shops) {
                code
                message
            }
        }
    ';

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
        return Shop::create(['title' => 'Магазин ' . uniqid()]);
    }

    private function openShift(User $user, Shop $shop): int
    {
        return DB::table('shifts')->insertGetId([
            'user_id'    => $user->id,
            'shop_id'    => $shop->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function updateShops(User $user, array $shopIds): void
    {
        $this->actingAs($this->adminUser)
            ->graphQL(self::MUTATION, [
                'id'      => $user->id,
                'name'    => $user->name,
                'surname' => $user->surname,
                'phone'   => $user->phone,
                'shops'   => $shopIds,
            ])
            ->assertJson(['data' => ['UserUpdate' => ['code' => 200]]]);
    }

    public function test_open_shift_follows_shop_change(): void
    {
        $oldShop = $this->makeShop();
        $newShop = $this->makeShop();

        $employee = $this->makeUser();
        $employee->shops()->create(['shop_id' => $oldShop->id]);
        $shiftId = $this->openShift($employee, $oldShop);

        $this->updateShops($employee, [$newShop->id]);

        $this->assertDatabaseHas('shifts', [
            'id'          => $shiftId,
            'shop_id'     => $newShop->id,
            'finished_at' => null,
        ]);
    }

    public function test_open_shift_untouched_when_its_shop_still_attached(): void
    {
        $shopA = $this->makeShop();
        $shopB = $this->makeShop();

        $employee = $this->makeUser();
        $employee->shops()->create(['shop_id' => $shopA->id]);
        $shiftId = $this->openShift($employee, $shopA);

        $this->updateShops($employee, [$shopA->id, $shopB->id]);

        $this->assertDatabaseHas('shifts', [
            'id'          => $shiftId,
            'shop_id'     => $shopA->id,
            'finished_at' => null,
        ]);
    }

    public function test_open_shift_finished_when_all_shops_removed(): void
    {
        $shop = $this->makeShop();

        $employee = $this->makeUser();
        $employee->shops()->create(['shop_id' => $shop->id]);
        $shiftId = $this->openShift($employee, $shop);

        $this->updateShops($employee, []);

        $shift = DB::table('shifts')->find($shiftId);
        $this->assertNotNull($shift->finished_at, 'Смена без единого магазина должна закрываться');
        $this->assertEquals($shop->id, $shift->shop_id);
    }

    public function test_finished_shift_not_touched(): void
    {
        $oldShop = $this->makeShop();
        $newShop = $this->makeShop();

        $employee = $this->makeUser();
        $employee->shops()->create(['shop_id' => $oldShop->id]);
        $shiftId = $this->openShift($employee, $oldShop);
        $finishedAt = now()->subDay()->startOfSecond();
        DB::table('shifts')->where('id', $shiftId)->update(['finished_at' => $finishedAt]);

        $this->updateShops($employee, [$newShop->id]);

        $this->assertDatabaseHas('shifts', [
            'id'      => $shiftId,
            'shop_id' => $oldShop->id,
        ]);
    }

    public function test_no_shift_no_error(): void
    {
        $shop = $this->makeShop();

        $employee = $this->makeUser();

        $this->updateShops($employee, [$shop->id]);

        $this->assertDatabaseCount('shifts', DB::table('shifts')->count());
    }
}
