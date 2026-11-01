<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Store;

use App\Models\City;
use App\Models\Store;
use App\RabbitMQ\Transformers\StoreEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Магазин по умолчанию в городе на стороне витрины (MENDEV-10).
 */
final class DefaultStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_resource_exposes_is_default(): void
    {
        $store = Store::factory()->create(['is_default' => true]);

        $this->getJson("/api/v1/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.isDefault', true);
    }

    public function test_store_resource_is_default_false_by_default(): void
    {
        $store = Store::factory()->create();

        $this->getJson("/api/v1/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.isDefault', false);
    }

    public function test_cities_endpoint_returns_default_store_id(): void
    {
        $city = City::factory()->create();
        Store::factory()->create(['city_id' => $city->id, 'is_default' => false]);
        $default = Store::factory()->create(['city_id' => $city->id, 'is_default' => true]);

        $this->getJson('/api/v1/cities')
            ->assertOk()
            ->assertJsonPath('data.0.id', $city->id)
            ->assertJsonPath('data.0.defaultStoreId', $default->id);
    }

    public function test_cities_endpoint_returns_null_when_no_default_store(): void
    {
        $city = City::factory()->create();
        Store::factory()->create(['city_id' => $city->id]);

        $this->getJson('/api/v1/cities')
            ->assertOk()
            ->assertJsonPath('data.0.defaultStoreId', null);
    }

    public function test_inactive_default_store_is_not_returned_for_city(): void
    {
        $city = City::factory()->create();
        Store::factory()->inactive()->create(['city_id' => $city->id, 'is_default' => true]);

        $this->getJson('/api/v1/cities')
            ->assertOk()
            ->assertJsonPath('data.0.defaultStoreId', null);
    }

    public function test_from_event_maps_is_default(): void
    {
        $attributes = app(StoreEventTransformer::class)->fromEvent([
            'id' => '1',
            'shop_code' => 'mendev',
            'source' => 'admin',
            'title' => 'Магазин',
            'active' => true,
            'is_default' => true,
        ]);

        $this->assertTrue($attributes['is_default']);
    }

    public function test_from_event_without_key_does_not_touch_flag(): void
    {
        $attributes = app(StoreEventTransformer::class)->fromEvent([
            'id' => '1',
            'shop_code' => 'mendev',
            'source' => 'admin',
            'title' => 'Магазин',
            'active' => true,
        ]);

        $this->assertArrayNotHasKey('is_default', $attributes);
    }

    public function test_from_event_can_reset_flag(): void
    {
        $attributes = app(StoreEventTransformer::class)->fromEvent([
            'id' => '1',
            'shop_code' => 'mendev',
            'source' => 'admin',
            'title' => 'Магазин',
            'active' => true,
            'is_default' => false,
        ]);

        $this->assertFalse($attributes['is_default']);
    }
}
