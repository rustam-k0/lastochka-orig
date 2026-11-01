<?php

namespace Tests\Feature\Transformers;

use App\Models\City;
use App\Models\Shop;
use App\Transformers\ShopEventTransformer;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Адрес и город магазина из shop.created/shop.updated (1С публикует напрямую в RabbitMQ).
 *
 * До фикса fromEvent() игнорировал `address` и ставил `city_id` из payload как есть
 * (1С его не шлёт) — магазины из 1С висели в админке без адреса и города.
 */
class ShopEventTransformerAddressCityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        // Observers outbox регистрируются один раз на процесс (static-флаг) — сбросить,
        // чтобы событие city.created попадало в event_outbox и в этом тесте.
        (new ReflectionProperty(RabbitMQEventsServiceProvider::class, 'observersRegistered'))->setValue(null, false);

        parent::setUp();
    }

    private function transformer(): ShopEventTransformer
    {
        return new ShopEventTransformer();
    }

    private function basePayload(array $extra = []): array
    {
        return array_merge([
            'event_id' => 'e1',
            'id' => 'guid-1',
            'shop_code' => 'guid-1',
            'source' => '1c',
            'title' => 'РАЗГУЛЯЙ ООО 1',
        ], $extra);
    }

    public function test_address_is_mapped_from_event(): void
    {
        $attributes = $this->transformer()->fromEvent($this->basePayload([
            'address' => 'г.Красноярск, ул. Калинина, 2б, пом. 28',
        ]));

        $this->assertSame('г.Красноярск, ул. Калинина, 2б, пом. 28', $attributes['address']);
    }

    public function test_address_absent_does_not_touch_attribute(): void
    {
        $attributes = $this->transformer()->fromEvent($this->basePayload());

        $this->assertArrayNotHasKey('address', $attributes);
    }

    public function test_address_null_clears_attribute(): void
    {
        $attributes = $this->transformer()->fromEvent($this->basePayload(['address' => null]));

        $this->assertArrayHasKey('address', $attributes);
        $this->assertNull($attributes['address']);
    }

    public function test_city_is_resolved_from_address_and_existing_city_reused(): void
    {
        $city = City::create(['name' => 'Краснотуранск', 'is_active' => true]);

        $attributes = $this->transformer()->fromEvent($this->basePayload([
            'address' => 'с.Краснотуранск, ул. Ленина, 35',
        ]));

        $this->assertSame($city->id, $attributes['city_id']);
        $this->assertSame(1, City::where('name', 'Краснотуранск')->count());
    }

    public function test_city_is_created_from_address_when_missing_and_published(): void
    {
        $this->assertNull(City::where('name', 'Краснокаменск')->first());

        $attributes = $this->transformer()->fromEvent($this->basePayload([
            'address' => 'пгт. Краснокаменск, ул. Центральная, 2, пом. 6',
        ]));

        $city = City::where('name', 'Краснокаменск')->first();
        $this->assertNotNull($city);
        // Laravel 9: casts() у City не применяется, is_active приходит как int.
        $this->assertTrue((bool) $city->is_active);
        $this->assertSame($city->id, $attributes['city_id']);

        // Город — справочник курьерки; витрина узнаёт о нём через city.created.
        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'cities',
            'entity_id' => $city->id,
            'event_type' => 'created',
        ]);
    }

    public function test_city_name_has_priority_over_address(): void
    {
        $attributes = $this->transformer()->fromEvent($this->basePayload([
            'city_name' => 'г. Дивногорск',
            'address' => 'г.Красноярск, ул. Ленина, 1',
        ]));

        $city = City::find($attributes['city_id']);
        $this->assertSame('Дивногорск', $city->name);
    }

    public function test_explicit_existing_city_id_is_applied_as_is(): void
    {
        $city = City::create(['name' => 'Кодинск', 'is_active' => true]);

        $attributes = $this->transformer()->fromEvent($this->basePayload([
            'city_id' => $city->id,
            'address' => 'г.Красноярск, ул. Ленина, 1',
        ]));

        $this->assertSame($city->id, $attributes['city_id']);
    }

    public function test_unknown_city_id_falls_back_to_address(): void
    {
        $attributes = $this->transformer()->fromEvent($this->basePayload([
            'city_id' => 999999999,
            'address' => 'г.Красноярск, ул. Ленина, 1',
        ]));

        $this->assertSame('Красноярск', City::find($attributes['city_id'])->name);
    }

    public function test_city_not_touched_when_nothing_to_resolve(): void
    {
        $attributes = $this->transformer()->fromEvent($this->basePayload([
            'address' => 'ул. Ленина, д. 5',
        ]));

        $this->assertArrayNotHasKey('city_id', $attributes);
    }

    public function test_trashed_city_is_restored_instead_of_duplicated(): void
    {
        $city = City::create(['name' => 'Ужур', 'is_active' => true]);
        $city->delete();

        $attributes = $this->transformer()->fromEvent($this->basePayload([
            'address' => 'г. Ужур, ул. Ленина, 1',
        ]));

        $this->assertSame($city->id, $attributes['city_id']);
        $this->assertNull($city->fresh()->deleted_at);
        $this->assertSame(1, City::withTrashed()->where('name', 'Ужур')->count());
    }

    public function test_to_event_still_publishes_address(): void
    {
        $shop = Shop::create([
            'code' => 'addr-shop-' . uniqid(),
            'title' => 'С адресом',
            'address' => 'г. Красноярск, ул. Ленина, 74',
        ]);

        $payload = $this->transformer()->toEvent($shop->fresh());

        $this->assertSame('г. Красноярск, ул. Ленина, 74', $payload['address']);
    }
}
