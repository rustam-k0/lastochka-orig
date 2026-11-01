<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\City;
use App\Models\Shop;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\Support\InteractsWithOneCShopsMaster;
use Tests\TestCase;

/**
 * MID-306: 1С назначает магазин по умолчанию → прежний дефолт города снимается
 * поштучно через Eloquent, его событие уходит в outbox (флаг снимется и на витрине).
 */
class ShopOneCDefaultInCityTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithOneCShopsMaster;

    protected function setUp(): void
    {
        // Observers outbox регистрируются один раз на процесс (static-флаг) — сбросить,
        // чтобы событие shop.updated прежнего дефолта попадало в event_outbox.
        (new ReflectionProperty(RabbitMQEventsServiceProvider::class, 'observersRegistered'))->setValue(null, false);

        parent::setUp();
    }

    private function send(string $guid, array $overrides): void
    {
        $result = (new EventConsumer('shops', config('rabbitmq-events.entities.shops')))->processEvent(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => $guid,
            'shop_code' => $guid,
            'source' => '1c',
            'event_source' => '1c',
        ], $overrides));

        $this->assertSame('ack', $result);
    }

    private function city(): City
    {
        return City::create(['name' => 'Дефолтград ' . Str::random(6), 'is_active' => true]);
    }

    private function defaultShop(City $city): Shop
    {
        $shop = Shop::create([
            'title' => 'Старый дефолт',
            'code' => 'old-default-' . uniqid(),
            'city_id' => $city->id,
            'is_default' => true,
        ]);
        DB::table('event_outbox')->where('entity_type', 'shops')->where('entity_id', $shop->id)->delete();

        return $shop;
    }

    public function test_flag_on_resets_previous_default_of_city_with_event(): void
    {
        $this->setOneCShopsMaster(true);
        $city = $this->city();
        $old = $this->defaultShop($city);
        $guid = (string) Str::uuid();

        $this->send($guid, ['title' => 'Новый дефолт', 'city_id' => $city->id, 'is_default' => true]);

        $new = Shop::query()->where('code', $guid)->firstOrFail();
        $this->assertTrue((bool) $new->is_default);
        $this->assertFalse((bool) $old->fresh()->is_default);
        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'shops',
            'entity_id' => $old->id,
            'event_type' => 'updated',
        ]);
    }

    public function test_flag_on_default_of_other_city_is_kept(): void
    {
        $this->setOneCShopsMaster(true);
        $old = $this->defaultShop($this->city());
        $guid = (string) Str::uuid();

        $this->send($guid, ['title' => 'Дефолт другого города', 'city_id' => $this->city()->id, 'is_default' => true]);

        $this->assertTrue((bool) $old->fresh()->is_default);
    }

    public function test_flag_on_event_without_is_default_does_not_reset(): void
    {
        $this->setOneCShopsMaster(true);
        $city = $this->city();
        $old = $this->defaultShop($city);
        $guid = (string) Str::uuid();

        $this->send($guid, ['title' => 'Обычный магазин', 'city_id' => $city->id]);

        $this->assertTrue((bool) $old->fresh()->is_default);
    }

    public function test_flag_off_keeps_legacy_behaviour(): void
    {
        $this->setOneCShopsMaster(false);
        $city = $this->city();
        $old = $this->defaultShop($city);
        $guid = (string) Str::uuid();

        $this->send($guid, ['title' => 'Второй дефолт', 'city_id' => $city->id, 'is_default' => true]);

        $this->assertTrue((bool) $old->fresh()->is_default);
    }
}
