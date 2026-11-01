<?php

declare(strict_types=1);

namespace Tests\Feature\OneC;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShop;
use App\Models\UserWorkingHour;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithOneCShopsMaster;
use Tests\TestCase;

/**
 * MID-306: магазины и графики сотрудника из 1С.
 */
class OneCEmployeeShopsSyncTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithOneCShopsMaster;

    private Shop $shopA;

    private Shop $shopB;

    private string $guid;

    private string $phone;

    private int $minute = 30;

    protected function setUp(): void
    {
        parent::setUp();
        Role::updateOrCreate(['name' => Role::COLLECTOR, 'guard_name' => 'api'], ['name_ru' => 'Сборщик']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->shopA = Shop::create(['title' => 'Магазин А', 'code' => 'emp-a-' . uniqid()]);
        $this->shopB = Shop::create(['title' => 'Магазин Б', 'code' => 'emp-b-' . uniqid()]);
        $this->guid = (string) Str::uuid();
        $this->phone = '+7999' . random_int(1000000, 9999999);
        $this->setOneCShopsMaster(true);
    }

    private function send(array $overrides): void
    {
        $this->minute--;
        $result = (new EventConsumer('employees', config('rabbitmq-events.entities.employees')))->processEvent(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'updated',
            'event_timestamp' => now()->subMinutes($this->minute)->toIso8601String(),
            'id' => $this->guid,
            'source' => '1c',
            'event_source' => '1c',
            'surname' => 'Сборщиков',
            'name' => 'Иван',
            'phone' => $this->phone,
            'role' => 'collector',
        ], $overrides));

        $this->assertSame('ack', $result);
    }

    private function employee(): User
    {
        $entityId = EventSyncMetadata::query()
            ->where('entity_type', 'employees')->where('source', '1c')->where('external_id', $this->guid)
            ->value('entity_id');

        return User::query()->findOrFail($entityId);
    }

    /**
     * @return array<int, array{0: int, 1: string, 2: string}>|null null — привязки нет
     */
    private function hoursIn(Shop $shop): ?array
    {
        $userShop = UserShop::query()->where('user_id', $this->employee()->id)->where('shop_id', $shop->id)->first();

        if ($userShop === null) {
            return null;
        }

        return UserWorkingHour::query()
            ->where('user_shop_id', $userShop->id)
            ->orderBy('day_of_week')
            ->get()
            ->map(fn (UserWorkingHour $hour) => [
                (int) $hour->day_of_week,
                mb_substr((string) $hour->opening_time, 0, 5),
                mb_substr((string) $hour->closing_time, 0, 5),
            ])
            ->all();
    }

    private function twoShops(): array
    {
        return [
            ['shop_code' => $this->shopA->code, 'schedule' => [
                'monday' => ['start' => '09:00', 'end' => '18:00'],
                'tuesday' => ['start' => '09:00', 'end' => '18:00'],
                'sunday' => null,
            ]],
            ['shop_code' => $this->shopB->code, 'schedule' => [
                'saturday' => ['start' => '10:00', 'end' => '16:00'],
            ]],
        ];
    }

    public function test_binds_several_shops_with_own_schedules(): void
    {
        $this->send(['shops' => $this->twoShops()]);

        $this->assertSame([[1, '09:00', '18:00'], [2, '09:00', '18:00']], $this->hoursIn($this->shopA));
        $this->assertSame([[6, '10:00', '16:00']], $this->hoursIn($this->shopB));
    }

    public function test_new_list_unbinds_missing_shop_with_its_schedule(): void
    {
        $this->send(['shops' => $this->twoShops()]);
        $userShopB = UserShop::query()->where('user_id', $this->employee()->id)->where('shop_id', $this->shopB->id)->firstOrFail();

        $this->send(['shops' => [['shop_code' => $this->shopA->code]]]);

        $this->assertNull($this->hoursIn($this->shopB));
        $this->assertSame(0, UserWorkingHour::query()->where('user_shop_id', $userShopB->id)->count());
        $this->assertSame([[1, '09:00', '18:00'], [2, '09:00', '18:00']], $this->hoursIn($this->shopA));
    }

    public function test_unknown_shop_code_is_skipped_without_creating_shop(): void
    {
        $unknown = 'unknown-shop-' . uniqid();

        $this->send(['shops' => [['shop_code' => $unknown], ['shop_code' => $this->shopA->code]]]);

        $this->assertFalse(Shop::withTrashed()->where('code', $unknown)->exists());
        $this->assertSame([$this->shopA->id], $this->employee()->shops()->pluck('shop_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_empty_list_unbinds_all_shops(): void
    {
        $this->send(['shops' => $this->twoShops()]);

        $this->send(['shops' => []]);

        $this->assertSame(0, $this->employee()->shops()->count());
    }

    public function test_missing_or_invalid_schedule_keeps_hours_and_missing_shops_keeps_bindings(): void
    {
        $this->send(['shops' => $this->twoShops()]);

        $this->send(['shops' => [
            ['shop_code' => $this->shopA->code],
            ['shop_code' => $this->shopB->code, 'schedule' => ['saturday' => ['start' => '18:00', 'end' => '10:00']]],
        ]]);
        $this->send(['surname' => 'Без магазинов в событии']);

        $this->assertSame([[1, '09:00', '18:00'], [2, '09:00', '18:00']], $this->hoursIn($this->shopA));
        $this->assertSame([[6, '10:00', '16:00']], $this->hoursIn($this->shopB));
    }

    public function test_schedule_replaces_days(): void
    {
        $this->send(['shops' => $this->twoShops()]);

        $this->send(['shops' => [
            ['shop_code' => $this->shopA->code, 'schedule' => ['friday' => ['start' => '12:00', 'end' => '20:00']]],
            ['shop_code' => $this->shopB->code],
        ]]);

        $this->assertSame([[5, '12:00', '20:00']], $this->hoursIn($this->shopA));
    }

    public function test_open_shift_moves_to_remaining_shop(): void
    {
        $this->send(['shops' => $this->twoShops()]);
        $shiftId = DB::table('shifts')->insertGetId([
            'user_id' => $this->employee()->id,
            'shop_id' => $this->shopB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->send(['shops' => [['shop_code' => $this->shopA->code]]]);

        $this->assertDatabaseHas('shifts', ['id' => $shiftId, 'shop_id' => $this->shopA->id, 'finished_at' => null]);
    }
}
