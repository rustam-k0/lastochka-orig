<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\Shop;
use App\Models\ShopWorkingHour;
use App\Models\Type;
use App\Transformers\ShopEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MID-306: у круглосуточного дня нет времени — в событие уходит null,
 * а не текущее время сервера (Carbon::parse(null)).
 */
class ShopEventToEventAllDayTimeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_all_day_hours_are_published_without_time(): void
    {
        $shop = Shop::create(['title' => 'Круглосуточный', 'code' => 'h24-' . uniqid()]);
        ShopWorkingHour::create([
            'shop_id' => $shop->id,
            'day_of_week' => Type::MONDAY,
            'type_id' => Type::WORK_ALL_DAY,
            'opening_time' => null,
            'closing_time' => null,
        ]);
        ShopWorkingHour::create([
            'shop_id' => $shop->id,
            'day_of_week' => Type::TUESDAY,
            'type_id' => Type::WORK_SCHEDULE,
            'opening_time' => '09:00',
            'closing_time' => '21:00',
        ]);

        $payload = (new ShopEventTransformer())->toEvent($shop->fresh());

        $this->assertSame(['start' => null, 'end' => null], $payload['schedule']['monday']);
        $this->assertSame(['start' => '09:00', 'end' => '21:00'], $payload['schedule']['tuesday']);
        $this->assertTrue($payload['open_24_7']);
    }
}
