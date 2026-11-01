<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Concerns\TogglesDeliveryPriceRangesModule;
use Tests\TestCase;

/**
 * MID-280: ступени стоимости доставки основной зоны приходят в событии магазина
 * админки ключом delivery_price_ranges. Ключ ведёт только админка: от 1С он не читается.
 */
final class StoreDeliveryPriceRangesEventTest extends TestCase
{
    use RefreshDatabase;
    use TogglesDeliveryPriceRangesModule;

    private const CODE = 'MID280_SHOP';

    private int $minute = 30;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Тест написан до тумблера (Task 10): модуль включён, поведение прежнее.
        // test_one_c_event_with_shops_master_does_not_touch_ranges переписывает
        // project_modules своим блобом (onec_shops_master) уже ПОСЛЕ этого setUp —
        // тот тест ступени лесенки не проверяет, поэтому это не важно.
        $this->setDeliveryPriceRangesModule(true);
    }

    private function send(array $overrides, string $eventType = 'updated', string $source = 'admin'): void
    {
        $this->minute--;
        $result = (new EventConsumer('shops', config('rabbitmq-events.entities.shops')))->processEvent(array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => $eventType,
            'event_timestamp' => now()->subMinutes($this->minute)->toIso8601String(),
            'id' => '280',
            'shop_code' => self::CODE,
            'source' => $source,
            'event_source' => $source,
            'title' => 'Магазин MID-280',
            'active' => true,
            'delivery_price' => 200,
            'min_order_price' => 500,
            'free_delivery_from' => 3000,
        ], $overrides));

        $this->assertSame('ack', $result);
    }

    private function store(): Store
    {
        return Store::query()->where('code', self::CODE)->firstOrFail();
    }

    public function test_admin_event_stores_normalized_ranges(): void
    {
        $this->send([
            'delivery_price_ranges' => [
                ['from_amount' => 3000, 'price' => 0],
                ['from_amount' => '1000', 'price' => 100],
            ],
        ], 'created');

        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $this->store()->delivery_price_ranges);
        $this->assertSame(100, $this->store()->deliveryLadder()->priceFor(1500));
    }

    public function test_event_without_key_keeps_ranges(): void
    {
        $this->send(['delivery_price_ranges' => [['from_amount' => 1000, 'price' => 100]]], 'created');

        $this->send(['title' => 'Переименован']);

        $this->assertSame('Переименован', $this->store()->name);
        $this->assertSame([['from_amount' => 1000, 'price' => 100]], $this->store()->delivery_price_ranges);
    }

    public function test_empty_ranges_clear_column(): void
    {
        $this->send(['delivery_price_ranges' => [['from_amount' => 1000, 'price' => 100]]], 'created');

        $this->send(['delivery_price_ranges' => []]);

        $this->assertSame([], $this->store()->delivery_price_ranges);
        // Без ступеней действует прежний порог бесплатной доставки
        $this->assertSame(0, $this->store()->deliveryLadder()->priceFor(3000));
    }

    public function test_broken_ranges_are_dropped(): void
    {
        $this->send(['delivery_price_ranges' => [['from_amount' => 1000, 'price' => 100]]], 'created');

        $this->send(['delivery_price_ranges' => [['from_amount' => -5, 'price' => 100]]]);

        $this->assertSame([], $this->store()->delivery_price_ranges);
    }

    public function test_one_c_event_does_not_touch_ranges(): void
    {
        $this->send(['delivery_price_ranges' => [['from_amount' => 1000, 'price' => 100]]], 'created');

        $this->send(['title' => 'Из 1С', 'delivery_price_ranges' => [['from_amount' => 500, 'price' => 50]]], 'updated', '1c');

        // Событие 1С дошло до того же магазина (название сменилось), а ступени остались
        $this->assertSame(1, Store::query()->where('code', self::CODE)->count());
        $this->assertSame('Из 1С', $this->store()->name);
        $this->assertSame([['from_amount' => 1000, 'price' => 100]], $this->store()->delivery_price_ranges);
    }

    public function test_one_c_event_with_shops_master_does_not_touch_ranges(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'onec_shops_master' => ['label' => '1С ведёт магазины и сотрудников', 'enabled' => true],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );
        app(ModuleConfigService::class)->invalidateCache();

        $this->send(['delivery_price_ranges' => [['from_amount' => 1000, 'price' => 100]]], 'created');

        $this->send(['title' => 'Из 1С', 'delivery_price_ranges' => [['from_amount' => 500, 'price' => 50]]], 'updated', '1c');

        // Событие 1С дошло до того же магазина (название сменилось), а ступени остались
        $this->assertSame(1, Store::query()->where('code', self::CODE)->count());
        $this->assertSame('Из 1С', $this->store()->name);
        $this->assertSame([['from_amount' => 1000, 'price' => 100]], $this->store()->delivery_price_ranges);
    }
}
