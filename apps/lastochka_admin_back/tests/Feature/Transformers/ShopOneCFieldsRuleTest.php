<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\Shop;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithOneCShopsMaster;
use Tests\TestCase;

/**
 * MID-306: событие магазина от 1С при включённом модуле не затирает поля,
 * которых нет в событии (бонусы, слот, координаты), а NOT NULL поля не очищаются null.
 */
class ShopOneCFieldsRuleTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithOneCShopsMaster;

    private string $guid;

    private int $minute = 30;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guid = (string) Str::uuid();
    }

    private function send(array $overrides, string $eventType = 'updated', string $source = '1c'): void
    {
        $this->minute--;
        $result = (new EventConsumer('shops', config('rabbitmq-events.entities.shops')))->processEvent(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'event_timestamp' => now()->subMinutes($this->minute)->toIso8601String(),
            'id' => $this->guid,
            'shop_code' => $this->guid,
            'source' => $source,
            'event_source' => $source,
        ], $overrides));

        $this->assertSame('ack', $result);
    }

    private function shop(): Shop
    {
        return Shop::query()->where('code', $this->guid)->firstOrFail();
    }

    private function configureInAdmin(): void
    {
        Shop::query()->where('code', $this->guid)->update([
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => 5,
            'bonus_spend_percent' => 30,
            'slot_time' => 90,
            'latitude' => '56.01',
            'longitude' => '92.85',
        ]);
    }

    public function test_flag_on_missing_keys_do_not_overwrite_admin_settings(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Магазин 1'], 'created');
        $this->configureInAdmin();

        $this->send(['title' => 'Магазин 1 новый']);

        $shop = $this->shop();
        $this->assertSame('Магазин 1 новый', $shop->title);
        $this->assertTrue((bool) $shop->is_bonus_enabled);
        $this->assertSame(5, (int) $shop->bonus_earn_percent);
        $this->assertSame(30, (int) $shop->bonus_spend_percent);
        $this->assertSame(90, (int) $shop->slot_time);
        $this->assertSame('56.01', (string) $shop->latitude);
        $this->assertSame('92.85', (string) $shop->longitude);
    }

    public function test_flag_on_present_keys_overwrite(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Магазин 2'], 'created');
        $this->configureInAdmin();

        $this->send(['slot_time' => 30, 'latitude' => null, 'is_bonus_enabled' => false]);

        $shop = $this->shop();
        $this->assertSame(30, (int) $shop->slot_time);
        $this->assertNull($shop->latitude);
        $this->assertFalse((bool) $shop->is_bonus_enabled);
        $this->assertSame('92.85', (string) $shop->longitude);
        $this->assertSame('Магазин 2', $shop->title);
    }

    public function test_flag_on_empty_title_and_invalid_slot_are_ignored(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Магазин 3', 'slot_time' => 45], 'created');

        $this->send(['title' => null, 'slot_time' => 'час']);
        $this->send(['title' => '   ', 'slot_time' => null]);

        $shop = $this->shop();
        $this->assertSame('Магазин 3', $shop->title);
        $this->assertSame(45, (int) $shop->slot_time);
    }

    public function test_flag_on_empty_or_invalid_bonuses_are_ignored(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Бонусы'], 'created');
        $this->configureInAdmin();

        $this->send(['is_bonus_enabled' => null, 'bonus_earn_percent' => null, 'bonus_spend_percent' => '']);
        $this->send(['is_bonus_enabled' => '', 'bonus_earn_percent' => 'пять', 'bonus_spend_percent' => 150]);
        $this->send(['is_bonus_enabled' => 'возможно', 'bonus_earn_percent' => -1, 'bonus_spend_percent' => '12.5']);

        $shop = $this->shop();
        $this->assertTrue((bool) $shop->is_bonus_enabled);
        $this->assertSame(5, (int) $shop->bonus_earn_percent);
        $this->assertSame(30, (int) $shop->bonus_spend_percent);

        $this->send(['is_bonus_enabled' => '0', 'bonus_earn_percent' => '7', 'bonus_spend_percent' => 100]);

        $shop = $this->shop();
        $this->assertFalse((bool) $shop->is_bonus_enabled);
        $this->assertSame(7, (int) $shop->bonus_earn_percent);
        $this->assertSame(100, (int) $shop->bonus_spend_percent);
    }

    public function test_flag_on_coordinates_rule(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Координаты'], 'created');
        $this->configureInAdmin();

        $this->send(['latitude' => 'север', 'longitude' => 200]);
        $this->send(['latitude' => 91, 'longitude' => true]);

        $shop = $this->shop();
        $this->assertSame('56.01', (string) $shop->latitude);
        $this->assertSame('92.85', (string) $shop->longitude);

        $this->send(['latitude' => '55,75', 'longitude' => -37.61]);

        $shop = $this->shop();
        $this->assertEqualsWithDelta(55.75, (float) $shop->latitude, 0.000001);
        $this->assertEqualsWithDelta(-37.61, (float) $shop->longitude, 0.000001);

        $this->send(['latitude' => '', 'longitude' => null]);

        $shop = $this->shop();
        $this->assertNull($shop->latitude);
        $this->assertNull($shop->longitude);
    }

    public function test_flag_off_keeps_legacy_overwrite(): void
    {
        $this->setOneCShopsMaster(false);
        $this->send(['title' => 'Магазин 4'], 'created');
        $this->configureInAdmin();

        $this->send(['title' => 'Магазин 4']);

        $shop = $this->shop();
        $this->assertFalse((bool) $shop->is_bonus_enabled);
        $this->assertSame(60, (int) $shop->slot_time);
        $this->assertNull($shop->latitude);
    }

    public function test_flag_on_event_not_from_1c_keeps_legacy_overwrite(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Магазин 5'], 'created');
        $this->configureInAdmin();

        // Другой источник: новая метадата, магазин находится по shop_code → beforeUpdate.
        $this->send(['title' => 'Магазин 5'], 'updated', 'retailbridge');

        $shop = $this->shop();
        $this->assertFalse((bool) $shop->is_bonus_enabled);
        $this->assertSame(60, (int) $shop->slot_time);
    }
}
