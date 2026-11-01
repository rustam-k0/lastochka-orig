<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\City;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MID-306: событие магазина от 1С при модуле onec_shops_master не сбрасывает
 * самовывоз, доставку, бонусы и прочие настройки, которых нет в событии.
 */
final class StoreOneCFieldsRuleTest extends TestCase
{
    use RefreshDatabase;

    private const GUID = 'e0f0bc6c-0000-4000-8000-000000000306';

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
        ], $overrides));

        $this->assertSame('ack', $result);
    }

    private function store(): Store
    {
        return Store::withTrashed()->where('code', self::GUID)->firstOrFail();
    }

    private function configureFromAdmin(): void
    {
        Store::withTrashed()->where('code', self::GUID)->update([
            'is_pickup_enabled' => true,
            'min_pickup_order_amount' => 500,
            'pickup_payment_methods' => json_encode(['card', 'cash']),
            'delivery_price' => 300,
            'min_order_amount' => 1000,
            'free_delivery_from' => 3000,
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => 5,
            'heavy_order_threshold' => 30000,
            'assembly_price' => 50,
            'description' => 'Описание из админки',
            'is_open_24_7' => true,
            'slot_duration' => 90,
        ]);
    }

    public function test_flag_on_missing_keys_do_not_overwrite_settings(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Разгуляй 1'], 'created');
        $this->configureFromAdmin();

        $this->send(['title' => 'Разгуляй 1 новый']);

        $store = $this->store();
        $this->assertSame('Разгуляй 1 новый', $store->name);
        $this->assertTrue((bool) $store->is_pickup_enabled);
        $this->assertSame(500, (int) $store->min_pickup_order_amount);
        $this->assertSame(['card', 'cash'], $store->pickup_payment_methods);
        $this->assertSame(300, (int) $store->delivery_price);
        $this->assertSame(1000, (int) $store->min_order_amount);
        $this->assertSame(3000, (int) $store->free_delivery_from);
        $this->assertTrue((bool) $store->is_bonus_enabled);
        $this->assertSame(5, (int) $store->bonus_earn_percent);
        $this->assertSame(30000, (int) $store->heavy_order_threshold);
        $this->assertSame(50, (int) $store->assembly_price);
        $this->assertSame('Описание из админки', $store->description);
        $this->assertTrue((bool) $store->is_open_24_7);
        $this->assertSame(90, (int) $store->slot_duration);
        $this->assertTrue((bool) $store->is_active);
    }

    public function test_flag_on_present_keys_overwrite_and_null_for_required_is_ignored(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Разгуляй 2'], 'created');
        $this->configureFromAdmin();

        $this->send(['is_pickup_enabled' => false, 'description' => null, 'active' => null, 'title' => null]);

        $store = $this->store();
        $this->assertFalse((bool) $store->is_pickup_enabled);
        $this->assertNull($store->description);
        $this->assertTrue((bool) $store->is_active);
        $this->assertSame('Разгуляй 2', $store->name);
    }

    public function test_flag_on_heavy_threshold_kg_and_type_always_shop(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Разгуляй 3', 'type' => 'restaurant', 'heavy_order_threshold_kg' => '12,5'], 'created');

        $this->assertSame('shop', $this->store()->type);
        $this->assertSame(12500, (int) $this->store()->heavy_order_threshold);

        $this->send(['heavy_order_threshold_kg' => 'много', 'type' => 'restaurant']);
        $this->assertSame(12500, (int) $this->store()->heavy_order_threshold);
        $this->assertSame('shop', $this->store()->type);

        $this->send(['heavy_order_threshold_kg' => null]);
        $this->assertNull($this->store()->heavy_order_threshold);
    }

    public function test_flag_on_empty_or_invalid_booleans_are_ignored(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Разгуляй булевы'], 'created');
        $this->configureFromAdmin();
        Store::withTrashed()->where('code', self::GUID)->update([
            'is_b2b' => true,
            'ignore_stock' => true,
            'pickup_auto_cancel_enabled' => false,
        ]);

        $this->send([
            'active' => 'может быть',
            'is_pickup_enabled' => 'да',
            'is_bonus_enabled' => '',
            'is_b2b' => 2,
            'ignore_stock' => ['нет'],
            'pickup_auto_cancel_enabled' => null,
        ]);

        $store = $this->store();
        $this->assertTrue($store->is_active);
        $this->assertTrue($store->is_pickup_enabled);
        $this->assertTrue($store->is_bonus_enabled);
        $this->assertTrue($store->is_b2b);
        $this->assertTrue($store->ignore_stock);
        $this->assertFalse($store->pickup_auto_cancel_enabled);

        $this->send(['active' => 'false', 'is_pickup_enabled' => 0, 'is_b2b' => '0', 'pickup_auto_cancel_enabled' => 'TRUE']);

        $store = $this->store();
        $this->assertFalse($store->is_active);
        $this->assertFalse($store->is_pickup_enabled);
        $this->assertFalse($store->is_b2b);
        $this->assertTrue($store->pickup_auto_cancel_enabled);
    }

    public function test_flag_on_empty_or_invalid_integers_are_ignored(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Разгуляй числа'], 'created');
        $this->configureFromAdmin();

        $this->send([
            'slot_time' => null,
            'bonus_earn_percent' => '',
            'delivery_price' => 'бесплатно',
            'min_order_price' => -5,
            'pickup_wait_regular_hours' => 300,
            'assembly_price' => '12.5',
        ]);
        $this->send([
            'slot_time' => 'час',
            'bonus_earn_percent' => 150,
            'pickup_wait_perishable_hours' => null,
        ]);
        $this->send(['slot_time' => '', 'pickup_grace_period_hours' => 'сутки']);

        $store = $this->store();
        $this->assertSame(90, $store->slot_duration);
        $this->assertSame(5, $store->bonus_earn_percent);
        $this->assertSame(300, $store->delivery_price);
        $this->assertSame(1000, $store->min_order_amount);
        $this->assertSame(3, $store->pickup_wait_regular_hours);
        $this->assertSame(1, $store->pickup_wait_perishable_hours);
        $this->assertSame(1, $store->pickup_grace_period_hours);
        $this->assertSame(50, $store->assembly_price);

        $this->send([
            'slot_time' => '45',
            'bonus_earn_percent' => '7',
            'delivery_price' => '',
            'min_order_price' => null,
            'free_delivery_from' => 2500,
            'assembly_price' => 0,
            'pickup_wait_regular_hours' => 12,
        ]);

        $store = $this->store();
        $this->assertSame(45, $store->slot_duration);
        $this->assertSame(7, $store->bonus_earn_percent);
        $this->assertNull($store->delivery_price);
        $this->assertNull($store->min_order_amount);
        $this->assertSame(2500, $store->free_delivery_from);
        $this->assertSame(0, $store->assembly_price);
        $this->assertSame(12, $store->pickup_wait_regular_hours);
    }

    public function test_flag_on_pickup_payment_methods_rule(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Разгуляй оплата'], 'created');
        $this->configureFromAdmin();

        $this->send(['pickup_payment_methods' => 'card']);
        $this->send(['pickup_payment_methods' => [1, 2]]);
        $this->send(['pickup_payment_methods' => null]);
        $this->send(['pickup_payment_methods' => []]);

        $this->assertSame(['card', 'cash'], $this->store()->pickup_payment_methods);

        $this->send(['pickup_payment_methods' => '["cash"]']);
        $this->assertSame(['cash'], $this->store()->pickup_payment_methods);

        $this->send(['pickup_payment_methods' => ['card', 'sbp']]);
        $this->assertSame(['card', 'sbp'], $this->store()->pickup_payment_methods);
    }

    public function test_flag_on_coordinates_rule(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Разгуляй координаты', 'latitude' => 56.0106, 'longitude' => 92.8526], 'created');

        $this->send(['latitude' => 'север', 'longitude' => 181]);
        $this->send(['latitude' => -91, 'longitude' => true]);

        $store = $this->store();
        $this->assertEqualsWithDelta(56.0106, (float) $store->latitude, 0.0000001);
        $this->assertEqualsWithDelta(92.8526, (float) $store->longitude, 0.0000001);

        $this->send(['latitude' => '55,75', 'longitude' => '37.61']);

        $store = $this->store();
        $this->assertEqualsWithDelta(55.75, (float) $store->latitude, 0.0000001);
        $this->assertEqualsWithDelta(37.61, (float) $store->longitude, 0.0000001);

        $this->send(['latitude' => '', 'longitude' => null]);

        $store = $this->store();
        $this->assertNull($store->latitude);
        $this->assertNull($store->longitude);
    }

    public function test_flag_on_blank_title_is_ignored(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Разгуляй название'], 'created');

        $this->send(['title' => '   ']);
        $this->send(['title' => ['Разгуляй']]);

        $this->assertSame('Разгуляй название', $this->store()->name);
    }

    public function test_flag_on_garbage_on_create_does_not_break_event(): void
    {
        $this->setOneCShopsMaster(true);

        $this->send([
            'title' => '  ',
            'active' => 'мусор',
            'slot_time' => 'час',
            'bonus_earn_percent' => 'много',
            'is_b2b' => 'может',
            'latitude' => 'север',
        ], 'created');

        $store = $this->store();
        $this->assertSame(self::GUID, $store->name);
        $this->assertTrue($store->is_active);
        $this->assertNull($store->slot_duration);
        $this->assertSame(0, $store->bonus_earn_percent);
        $this->assertFalse($store->is_b2b);
        $this->assertNull($store->latitude);
    }

    public function test_flag_on_is_default_without_city_is_ignored(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Разгуляй без города', 'is_default' => true], 'created');
        $this->assertFalse($this->store()->is_default);

        $this->send(['is_default' => true]);
        $this->assertFalse($this->store()->is_default);

        $city = City::create(['name' => 'Красноярск', 'is_active' => true]);
        Store::withTrashed()->where('code', self::GUID)->update(['city_id' => $city->id]);

        $this->send(['is_default' => true]);
        $this->assertTrue($this->store()->is_default);
    }

    public function test_flag_off_keeps_legacy_overwrite(): void
    {
        $this->setOneCShopsMaster(false);
        $this->send(['title' => 'Разгуляй 4'], 'created');
        $this->configureFromAdmin();

        $this->send(['title' => 'Разгуляй 4']);

        $store = $this->store();
        $this->assertFalse((bool) $store->is_pickup_enabled);
        $this->assertNull($store->delivery_price);
        $this->assertFalse((bool) $store->is_bonus_enabled);
    }

    public function test_flag_on_admin_event_keeps_full_overwrite(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Разгуляй 5'], 'created');
        $this->configureFromAdmin();

        // Событие курьерки несёт полный набор полей, поведение не меняется.
        $this->send(['title' => 'Разгуляй 5', 'is_pickup_enabled' => false], 'updated', 'admin', '77');

        $store = $this->store();
        $this->assertFalse((bool) $store->is_pickup_enabled);
        $this->assertNull($store->delivery_price);
    }
}
