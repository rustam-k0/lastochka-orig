<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\City;
use App\RabbitMQ\Transformers\StoreEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Город витрины из shop.created/shop.updated: `city_name` либо населённый пункт
 * из `address`; неизвестный город создаётся, а не теряется (1С шлёт только адрес).
 */
final class StoreEventTransformerCityFromAddressTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_city_resolved_by_city_name_reuses_existing(): void
    {
        $city = City::create(['name' => 'Красноярск', 'is_active' => true]);

        $attrs = (new StoreEventTransformer())->fromEvent($this->basePayload([
            'city_name' => 'Красноярск',
        ]));

        $this->assertSame($city->id, $attrs['city_id']);
        $this->assertSame(1, City::count());
    }

    public function test_city_created_from_address_when_missing(): void
    {
        $attrs = (new StoreEventTransformer())->fromEvent($this->basePayload([
            'address' => 'пгт. Краснокаменск, ул. Центральная, 2, пом. 6',
        ]));

        $city = City::where('name', 'Краснокаменск')->first();
        $this->assertNotNull($city);
        $this->assertTrue($city->is_active);
        $this->assertSame($city->id, $attrs['city_id']);
    }

    public function test_city_name_with_prefix_is_normalized(): void
    {
        $city = City::create(['name' => 'Дивногорск', 'is_active' => true]);

        $attrs = (new StoreEventTransformer())->fromEvent($this->basePayload([
            'city_name' => 'г. Дивногорск',
            'address' => 'г.Красноярск, ул. Ленина, 1',
        ]));

        $this->assertSame($city->id, $attrs['city_id']);
        $this->assertSame(1, City::count());
    }

    public function test_city_not_touched_when_nothing_to_resolve(): void
    {
        $attrs = (new StoreEventTransformer())->fromEvent($this->basePayload([
            'address' => 'ул. Ленина, д. 5',
        ]));

        $this->assertArrayNotHasKey('city_id', $attrs);
        $this->assertSame(0, City::count());
    }

    public function test_trashed_city_is_restored_instead_of_duplicated(): void
    {
        $city = City::create(['name' => 'Ужур', 'is_active' => true]);
        $city->delete();

        $attrs = (new StoreEventTransformer())->fromEvent($this->basePayload([
            'address' => 'г. Ужур, ул. Ленина, 1',
        ]));

        $this->assertSame($city->id, $attrs['city_id']);
        $this->assertNull($city->fresh()->deleted_at);
        $this->assertSame(1, City::withTrashed()->count());
    }

    public function test_address_still_mapped(): void
    {
        $attrs = (new StoreEventTransformer())->fromEvent($this->basePayload([
            'address' => 'г.Красноярск, ул. Калинина, 2б, пом. 28',
        ]));

        $this->assertSame('г.Красноярск, ул. Калинина, 2б, пом. 28', $attrs['address']);
    }
}
