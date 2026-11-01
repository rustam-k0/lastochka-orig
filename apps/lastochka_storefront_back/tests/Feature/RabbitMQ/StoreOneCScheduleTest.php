<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Store;
use App\Models\StoreSchedule;
use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MID-306: режим работы магазина из 1С на витрине при модуле onec_shops_master — то же правило,
 * что в админке: open_24_7 = true — круглосуточно все дни; schedule без open_24_7 — работа
 * по графику; пустой schedule очищает график; невалидный schedule ничего не меняет.
 */
final class StoreOneCScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const GUID = 'e0f0bc6c-0000-4000-8000-000000000307';

    private const WEEKDAYS = [
        'monday' => ['start' => '09:00', 'end' => '21:00'],
        'tuesday' => ['start' => '09:00', 'end' => '21:00'],
        'wednesday' => ['start' => '09:00', 'end' => '21:00'],
        'thursday' => ['start' => '09:00', 'end' => '21:00'],
        'friday' => ['start' => '09:00', 'end' => '22:00'],
        'saturday' => ['start' => '10:00', 'end' => '22:00'],
        'sunday' => null,
    ];

    private int $minute = 30;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function setOneCShopsMaster(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'onec_shops_master' => ['label' => '1С ведёт магазины и сотрудников', 'enabled' => $enabled],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );
        app(ModuleConfigService::class)->invalidateCache();
    }

    private function send(array $overrides, string $eventType = 'updated', string $source = '1c', string $id = self::GUID): void
    {
        $this->minute--;
        $result = (new EventConsumer('shops', config('rabbitmq-events.entities.shops')))->processEvent(array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => $eventType,
            'event_timestamp' => now()->subMinutes($this->minute)->toIso8601String(),
            'id' => $id,
            'shop_code' => self::GUID,
            'source' => $source,
            'event_source' => $source,
            'title' => 'Разгуляй график',
        ], $overrides));

        $this->assertSame('ack', $result);
    }

    private function store(): Store
    {
        return Store::withTrashed()->where('code', self::GUID)->firstOrFail();
    }

    /**
     * @return array<int, array{0: int, 1: ?string, 2: ?string}>
     */
    private function schedule(): array
    {
        return StoreSchedule::query()
            ->where('store_id', $this->store()->id)
            ->orderBy('day_of_week')
            ->get()
            ->map(fn (StoreSchedule $row) => [
                $row->day_of_week,
                $row->opens_at?->format('H:i'),
                $row->closes_at?->format('H:i'),
            ])
            ->all();
    }

    public function test_schedule_without_open_24_7_turns_off_all_day_and_replaces_schedule(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['schedule' => self::WEEKDAYS], 'created');
        $this->assertCount(7, $this->schedule());
        $this->assertFalse($this->store()->is_open_24_7);

        $this->send(['open_24_7' => true]);
        $this->assertTrue($this->store()->is_open_24_7);

        $this->send(['schedule' => ['monday' => ['start' => '8:00', 'end' => '20:00:00'], 'sunday' => null]]);

        $this->assertFalse($this->store()->is_open_24_7);
        $this->assertSame([[1, '08:00', '20:00'], [7, null, null]], $this->schedule());
    }

    public function test_open_24_7_true_applies_to_all_days_and_ignores_schedule(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['schedule' => self::WEEKDAYS], 'created');

        $this->send(['open_24_7' => true, 'schedule' => ['monday' => ['start' => '00:00', 'end' => '23:59'], 'sunday' => null]]);

        $this->assertTrue($this->store()->is_open_24_7);
        $this->assertSame([], $this->schedule());
    }

    public function test_open_24_7_false_without_schedule_keeps_schedule(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['schedule' => self::WEEKDAYS], 'created');
        Store::withTrashed()->where('code', self::GUID)->update(['is_open_24_7' => true]);

        $this->send(['open_24_7' => false]);

        $this->assertFalse($this->store()->is_open_24_7);
        $this->assertCount(7, $this->schedule());
    }

    public function test_empty_schedule_clears_schedule(): void
    {
        $this->setOneCShopsMaster(true);

        foreach ([null, []] as $empty) {
            $this->send(['schedule' => self::WEEKDAYS]);
            $this->assertCount(7, $this->schedule());

            $this->send(['schedule' => $empty]);

            $this->assertSame([], $this->schedule());
            $this->assertFalse($this->store()->is_open_24_7);
        }
    }

    public function test_invalid_schedule_changes_nothing(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['schedule' => self::WEEKDAYS], 'created');

        $this->send(['open_24_7' => true, 'schedule' => ['monday' => ['start' => '22:00', 'end' => '06:00']]]);
        $this->send(['schedule' => 'пн-пт 9-21']);

        $this->assertFalse($this->store()->is_open_24_7);
        $this->assertCount(7, $this->schedule());

        Store::withTrashed()->where('code', self::GUID)->update(['is_open_24_7' => true]);
        $this->send(['schedule' => ['monday' => ['start' => 'утро', 'end' => '21:00']]]);

        $this->assertTrue($this->store()->is_open_24_7);
        $this->assertCount(7, $this->schedule());
    }

    public function test_empty_or_invalid_open_24_7_is_ignored(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['open_24_7' => true], 'created');

        $this->send(['open_24_7' => '']);
        $this->send(['open_24_7' => 'круглосуточно']);
        $this->send(['open_24_7' => null]);

        $this->assertTrue($this->store()->is_open_24_7);

        $this->send(['open_24_7' => '0']);
        $this->assertFalse($this->store()->is_open_24_7);
    }

    public function test_flag_off_keeps_legacy_schedule_sync(): void
    {
        $this->setOneCShopsMaster(false);
        $this->send(['schedule' => self::WEEKDAYS], 'created');

        $this->send(['schedule' => null]);
        $this->assertCount(7, $this->schedule());

        $this->send(['open_24_7' => true, 'schedule' => ['monday' => ['start' => '08:00', 'end' => '20:00']]]);

        $this->assertTrue($this->store()->is_open_24_7);
        $this->assertSame([[1, '08:00', '20:00']], $this->schedule());
    }

    public function test_flag_on_admin_event_keeps_legacy_schedule_sync(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['schedule' => self::WEEKDAYS], 'created');

        $this->send(['schedule' => null], 'updated', 'admin', '77');

        $this->assertCount(7, $this->schedule());
    }
}
