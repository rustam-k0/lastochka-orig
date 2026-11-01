<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\Shop;
use App\Models\ShopWorkingHour;
use App\Models\Type;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithOneCShopsMaster;
use Tests\TestCase;

/**
 * MID-306: график магазина из 1С заменяется целиком, open_24_7 = true делает круглосуточными все 7 дней.
 */
class ShopOneCScheduleTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithOneCShopsMaster;

    private const WEEKDAYS = [
        'monday' => ['start' => '09:00', 'end' => '21:00'],
        'tuesday' => ['start' => '09:00', 'end' => '21:00'],
        'wednesday' => ['start' => '09:00', 'end' => '21:00'],
        'thursday' => ['start' => '09:00', 'end' => '21:00'],
        'friday' => ['start' => '09:00', 'end' => '22:00'],
        'saturday' => ['start' => '10:00', 'end' => '22:00'],
        'sunday' => null,
    ];

    private string $guid;

    private int $minute = 30;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guid = (string) Str::uuid();
    }

    private function send(array $overrides, string $eventType = 'updated'): void
    {
        $this->minute--;
        $result = (new EventConsumer('shops', config('rabbitmq-events.entities.shops')))->processEvent(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'event_timestamp' => now()->subMinutes($this->minute)->toIso8601String(),
            'id' => $this->guid,
            'shop_code' => $this->guid,
            'source' => '1c',
            'event_source' => '1c',
            'title' => 'График',
        ], $overrides));

        $this->assertSame('ack', $result);
    }

    /**
     * @return array<int, array{0: int, 1: int, 2: ?string, 3: ?string}>
     */
    private function hours(): array
    {
        $shopId = Shop::query()->where('code', $this->guid)->value('id');

        return ShopWorkingHour::query()
            ->where('shop_id', $shopId)
            ->orderBy('day_of_week')
            ->get()
            ->map(fn (ShopWorkingHour $hour) => [
                (int) $hour->day_of_week,
                (int) $hour->type_id,
                $hour->opening_time !== null ? mb_substr((string) $hour->opening_time, 0, 5) : null,
                $hour->closing_time !== null ? mb_substr((string) $hour->closing_time, 0, 5) : null,
            ])
            ->all();
    }

    public function test_schedule_is_replaced_with_days_off_removed(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['schedule' => self::WEEKDAYS], 'created');

        $this->assertCount(6, $this->hours());
        $this->assertSame([Type::FRIDAY, Type::WORK_SCHEDULE, '09:00', '22:00'], $this->hours()[4]);

        $this->send(['schedule' => ['monday' => ['start' => '08:00', 'end' => '20:00'], 'tuesday' => null]]);

        $this->assertSame([[Type::MONDAY, Type::WORK_SCHEDULE, '08:00', '20:00']], $this->hours());
    }

    public function test_open_24_7_without_schedule_makes_all_days_all_day(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['schedule' => self::WEEKDAYS], 'created');

        $this->send(['open_24_7' => true]);

        $hours = $this->hours();
        $this->assertCount(7, $hours);
        foreach ($hours as $hour) {
            $this->assertSame(Type::WORK_ALL_DAY, $hour[1]);
            $this->assertNull($hour[2]);
            $this->assertNull($hour[3]);
        }
    }

    public function test_open_24_7_with_schedule_makes_all_seven_days_all_day(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send([
            'open_24_7' => true,
            'schedule' => [
                'monday' => ['start' => '00:00', 'end' => '23:59'],
                'tuesday' => ['start' => '00:00', 'end' => '23:59'],
                'sunday' => null,
            ],
        ], 'created');

        $this->assertSame(array_map(
            static fn (int $day) => [$day, Type::WORK_ALL_DAY, null, null],
            Type::getWeekDays()
        ), $this->hours());
    }

    public function test_empty_or_invalid_open_24_7_is_ignored(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['open_24_7' => true], 'created');

        $this->send(['open_24_7' => '']);
        $this->send(['open_24_7' => 'круглосуточно']);
        $this->send(['open_24_7' => null]);

        $hours = $this->hours();
        $this->assertCount(7, $hours);
        $this->assertSame(Type::WORK_ALL_DAY, $hours[0][1]);
    }

    public function test_invalid_open_24_7_with_schedule_uses_schedule(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['open_24_7' => true], 'created');

        $this->send(['open_24_7' => 'да', 'schedule' => ['monday' => ['start' => '08:00', 'end' => '20:00']]]);

        $this->assertSame([[Type::MONDAY, Type::WORK_SCHEDULE, '08:00', '20:00']], $this->hours());
    }

    public function test_empty_schedule_clears_hours(): void
    {
        $this->setOneCShopsMaster(true);

        foreach ([null, []] as $empty) {
            $this->send(['schedule' => self::WEEKDAYS]);
            $this->assertCount(6, $this->hours());

            $this->send(['schedule' => $empty]);

            $this->assertSame([], $this->hours());
        }
    }

    public function test_invalid_schedule_with_open_24_7_keeps_hours(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['schedule' => self::WEEKDAYS], 'created');

        $this->send(['open_24_7' => true, 'schedule' => ['monday' => ['start' => '22:00', 'end' => '06:00']]]);

        $hours = $this->hours();
        $this->assertCount(6, $hours);
        $this->assertSame(Type::WORK_SCHEDULE, $hours[0][1]);
    }

    public function test_open_24_7_false_without_schedule_removes_all_day_hours(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['open_24_7' => true], 'created');
        $this->assertCount(7, $this->hours());

        $this->send(['open_24_7' => false]);

        $this->assertSame([], $this->hours());
    }

    public function test_invalid_schedule_keeps_hours(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['schedule' => self::WEEKDAYS], 'created');

        $this->send(['schedule' => ['monday' => ['start' => '22:00', 'end' => '06:00']]]);
        $this->send(['schedule' => 'пн-пт 9-21']);

        $this->assertCount(6, $this->hours());
    }

    public function test_event_without_schedule_keys_keeps_hours(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['schedule' => self::WEEKDAYS], 'created');

        $this->send(['address' => 'г. Красноярск, ул. Ленина, 1']);

        $this->assertCount(6, $this->hours());
    }

    public function test_flag_off_keeps_legacy_partial_sync(): void
    {
        $this->setOneCShopsMaster(false);
        $this->send(['schedule' => self::WEEKDAYS], 'created');

        $this->send(['schedule' => ['monday' => ['start' => '08:00', 'end' => '20:00']], 'open_24_7' => true]);

        $hours = $this->hours();
        $this->assertCount(6, $hours);
        $this->assertSame([Type::MONDAY, Type::WORK_SCHEDULE, '08:00', '20:00'], $hours[0]);
    }
}
