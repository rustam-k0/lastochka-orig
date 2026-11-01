<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Delivery;

use App\Services\Delivery\ZoneDeliveryLadder;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * MID-280: лесенка зоны из ступеней, а где ступеней нет, из прежнего порога.
 *
 * Класс — чистая функция без обращений к БД, но ZoneDeliveryLadder::make()
 * теперь гейтится модулем delivery_price_ranges (Task 10): расширяем базовый
 * класс до Laravel-теста, чтобы был facade root для Cache/app(), и включаем
 * флаг напрямую через кеш — без миграций и без RefreshDatabase.
 */
final class ZoneDeliveryLadderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Тесты написаны до тумблера (Task 10): модуль включён, поведение прежнее.
        Cache::put(ModuleConfigService::CACHE_KEY_DELIVERY_PRICE_RANGES, true, now()->addMinutes(10));
    }

    public function test_ranges_win_over_free_delivery_from(): void
    {
        $ladder = ZoneDeliveryLadder::make(200, [
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], 3000);

        $this->assertSame(200, $ladder->priceFor(999));
        $this->assertSame(100, $ladder->priceFor(1000));
        $this->assertSame(0, $ladder->priceFor(3000));
    }

    public function test_no_ranges_falls_back_to_free_delivery_from(): void
    {
        $ladder = ZoneDeliveryLadder::make(150, [], 1000);

        $this->assertSame([['from_amount' => 1000, 'price' => 0]], $ladder->ranges());
        $this->assertSame(150, $ladder->priceFor(999.99));
        $this->assertSame(0, $ladder->priceFor(1000));
    }

    public function test_null_and_broken_ranges_fall_back_to_free_delivery_from(): void
    {
        $this->assertSame(0, ZoneDeliveryLadder::make(150, null, 500)->priceFor(500));
        $this->assertSame(0, ZoneDeliveryLadder::make(150, [['from_amount' => -1, 'price' => 0]], 500)->priceFor(500));
        $this->assertSame(150, ZoneDeliveryLadder::make(150, null, null)->priceFor(100000));
    }

    public function test_json_string_ranges_are_accepted(): void
    {
        $ladder = ZoneDeliveryLadder::make(200, '[{"from_amount":1000,"price":100}]', null);

        $this->assertSame(100, $ladder->priceFor(1500));
    }
}
