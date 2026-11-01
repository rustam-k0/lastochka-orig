<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Store;

use App\Models\Store;
use App\Models\StoreSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class StoreTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Get Stores ====================

    public function test_get_stores(): void
    {
        $store1 = Store::factory()->create([
            'name' => 'Альфа Маркет',
            'delivery_price' => null,
            'min_order_amount' => null,
        ]);
        $store2 = Store::factory()->create([
            'name' => 'Бета Маркет',
            'delivery_price' => null,
            'min_order_amount' => null,
        ]);

        $response = $this->getJson('/api/v1/stores');

        $response->assertOk()
            ->assertJsonPath('hasMore', false)
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $store1->id)
            ->assertJsonPath('data.0.name', 'Альфа Маркет')
            ->assertJsonPath('data.0.deliveryInfo.deliveryPrice', 0)
            ->assertJsonPath('data.0.deliveryInfo.minOrderAmount', 0)
            ->assertJsonPath('data.1.id', $store2->id)
            ->assertJsonPath('data.1.name', 'Бета Маркет')
            ->assertJsonPath('data.1.deliveryInfo.deliveryPrice', 0)
            ->assertJsonPath('data.1.deliveryInfo.minOrderAmount', 0);
    }

    public function test_get_stores_does_not_return_inactive_stores(): void
    {
        Store::factory()->create(['name' => 'Активный']);
        Store::factory()->inactive()->create(['name' => 'Неактивный']);

        $response = $this->getJson('/api/v1/stores');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Активный');
    }

    public function test_get_stores_pagination(): void
    {
        Store::factory()->count(25)->create();

        $response = $this->getJson('/api/v1/stores?page=1&perPage=10');

        $response->assertOk()
            ->assertJsonPath('hasMore', true)
            ->assertJsonPath('total', 25)
            ->assertJsonCount(10, 'data');

        $response2 = $this->getJson('/api/v1/stores?page=3&perPage=10');

        $response2->assertOk()
            ->assertJsonPath('hasMore', false)
            ->assertJsonCount(5, 'data');
    }

    public function test_get_stores_limits_per_page_to_100(): void
    {
        Store::factory()->count(150)->create();

        $response = $this->getJson('/api/v1/stores?perPage=200');

        $response->assertOk()
            ->assertJsonCount(100, 'data');
    }

    // ==================== Get Store ====================

    public function test_get_store(): void
    {
        $store = Store::factory()->create([
            'name' => 'Тестовый Магазин',
            'min_order_amount' => 500,
            'delivery_price' => 200,
            'image_path' => 'https://cdn.example.com/store.jpg',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $store->id)
            ->assertJsonPath('data.name', 'Тестовый Магазин')
            ->assertJsonPath('data.deliveryInfo.deliveryPrice', 200)
            ->assertJsonPath('data.deliveryInfo.minOrderAmount', 500)
            ->assertJsonPath('data.isActive', true)
            ->assertJsonPath('data.image.id', $store->id)
            ->assertJsonPath('data.image.path', 'https://cdn.example.com/store.jpg');
    }

    public function test_get_store_returns_description(): void
    {
        $store = Store::factory()->create([
            'name' => 'Аладдин',
            'description' => 'Продукты, Своя Кухня, Бытовая Химия',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}");

        $response->assertOk()
            ->assertJsonPath('data.description', 'Продукты, Своя Кухня, Бытовая Химия');
    }

    public function test_get_store_returns_null_description_when_empty(): void
    {
        $store = Store::factory()->create(['description' => null]);

        $response = $this->getJson("/api/v1/stores/{$store->id}");

        $response->assertOk()
            ->assertJsonPath('data.description', null);
    }

    public function test_get_store_defaults_type_to_shop(): void
    {
        $store = Store::factory()->create(['name' => 'Обычный магазин']);

        $response = $this->getJson("/api/v1/stores/{$store->id}");

        $response->assertOk()
            ->assertJsonPath('data.type', 'shop');
    }

    public function test_get_store_returns_restaurant_type(): void
    {
        $store = Store::factory()->create(['name' => 'Кафе', 'type' => 'restaurant']);

        $response = $this->getJson("/api/v1/stores/{$store->id}");

        $response->assertOk()
            ->assertJsonPath('data.type', 'restaurant');
    }

    public function test_get_store_not_found(): void
    {
        $response = $this->getJson('/api/v1/stores/999999');

        $response->assertNotFound();
    }

    public function test_get_store_returns_404_for_inactive_store(): void
    {
        $store = Store::factory()->inactive()->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}");

        $response->assertNotFound();
    }

    public function test_get_store_without_image_returns_null_image(): void
    {
        $store = Store::factory()->create(['image_path' => null]);

        $response = $this->getJson("/api/v1/stores/{$store->id}");

        $response->assertOk()
            ->assertJsonPath('data.image', null);
    }

    // ==================== Working Hours ====================

    public function test_working_hours_for_24_7_store(): void
    {
        $store = Store::factory()->open247()->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}");

        $response->assertOk()
            ->assertJsonPath('data.workingHours.openingHour', 0)
            ->assertJsonPath('data.workingHours.openingMinute', 0)
            ->assertJsonPath('data.workingHours.closingHour', 24)
            ->assertJsonPath('data.workingHours.closingMinute', 0);
    }

    public function test_working_hours_from_schedule(): void
    {
        // Замораживаем время на вторник (dayOfWeekIso = 2)
        Carbon::setTestNow(Carbon::create(2026, 1, 27, 12, 0, 0)); // Вторник

        $store = Store::factory()->create(['is_open_24_7' => false]);
        StoreSchedule::create([
            'store_id' => $store->id,
            'day_of_week' => 2, // Вторник
            'opens_at' => '09:30',
            'closes_at' => '21:00',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}");

        $response->assertOk()
            ->assertJsonPath('data.workingHours.openingHour', 9)
            ->assertJsonPath('data.workingHours.openingMinute', 30)
            ->assertJsonPath('data.workingHours.closingHour', 21)
            ->assertJsonPath('data.workingHours.closingMinute', 0);

        Carbon::setTestNow(); // Сбрасываем
    }

    public function test_working_hours_returns_null_on_day_off(): void
    {
        // Замораживаем время на воскресенье (dayOfWeekIso = 7)
        Carbon::setTestNow(Carbon::create(2026, 2, 1, 12, 0, 0)); // Воскресенье

        $store = Store::factory()->create(['is_open_24_7' => false]);
        // Расписание только на понедельник
        StoreSchedule::create([
            'store_id' => $store->id,
            'day_of_week' => 1, // Понедельник
            'opens_at' => '09:00',
            'closes_at' => '18:00',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}");

        $response->assertOk()
            ->assertJsonPath('data.workingHours', null);

        Carbon::setTestNow();
    }
}
