<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Product;

use App\Models\Category;
use App\Models\City;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Строки «Доставка: сегодня» / «Самовывоз: сегодня» на карточке товара.
 *
 * Те же поля deliveryDate/pickupDate, что и в листинге каталога, но на
 * endpoint'ах карточки: показ товара, похожие, сопутствующие, поиск по
 * штрихкоду. Наполняются только при переданном cityId.
 */
final class ProductCardDeliveryDatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Слоты доставки генерируются с 8:00 до 20:00 и только в будущее:
        // без фиксации времени тест зеленеет днём и падает вечером, когда
        // ближайшей датой становится завтра.
        $this->travelTo(now()->startOfDay()->addHours(9));
    }

    private function city(): City
    {
        return City::create([
            'name' => 'Москва',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'is_active' => true,
        ]);
    }

    public function test_delivery_date_returned_on_show_with_city_id(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['quantity' => 10]);
        $city = $this->city();

        $response = $this->getJson("/api/v1/products/{$product->id}?cityId={$city->id}");

        $response->assertOk()
            ->assertJsonPath('data.deliveryDate', now()->format('Y-m-d'));
    }

    public function test_pickup_date_returned_on_show_with_city_id(): void
    {
        $store = Store::factory()->withSchedule()->create(['is_pickup_enabled' => true]);
        $product = Product::factory()->for($store)->create(['quantity' => 10]);
        $city = $this->city();

        $response = $this->getJson("/api/v1/products/{$product->id}?cityId={$city->id}");

        $response->assertOk();
        $this->assertNotNull($response->json('data.pickupDate'));
    }

    public function test_pickup_date_is_null_when_pickup_disabled(): void
    {
        $store = Store::factory()->create(['is_pickup_enabled' => false]);
        $product = Product::factory()->for($store)->create(['quantity' => 10]);
        $city = $this->city();

        $response = $this->getJson("/api/v1/products/{$product->id}?cityId={$city->id}");

        $response->assertOk();
        $data = $response->json('data');
        // Ключ присутствует (cityId передан), но значения нет — самовывоз выключен.
        $this->assertArrayHasKey('pickupDate', $data);
        $this->assertNull($data['pickupDate']);
    }

    public function test_no_dates_on_show_without_city_id(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['quantity' => 10]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayNotHasKey('deliveryDate', $data);
        $this->assertArrayNotHasKey('pickupDate', $data);
    }

    public function test_snake_case_city_id_alias_accepted_on_show(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['quantity' => 10]);
        $city = $this->city();

        $this->getJson("/api/v1/products/{$product->id}?city_id={$city->id}")
            ->assertOk()
            ->assertJsonPath('data.deliveryDate', now()->format('Y-m-d'));
    }

    public function test_unknown_city_id_does_not_break_response(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['quantity' => 10]);

        $response = $this->getJson("/api/v1/products/{$product->id}?cityId=999999");

        $response->assertOk();
        $this->assertArrayNotHasKey('deliveryDate', $response->json('data'));
    }

    public function test_delivery_date_returned_for_selected_variant_parent(): void
    {
        Cache::flush();
        StorefrontSetting::updateOrCreate(
            ['key' => 'project_modules'],
            ['value' => json_encode(['configurable_products' => ['enabled' => true]]), 'type' => 'json'],
        );

        $store = Store::factory()->create();
        $parent = Product::factory()->for($store)->create([
            'is_configurable' => true,
            'quantity' => 0,
        ]);
        $child = Product::factory()->for($store)->create([
            'parent_id' => $parent->id,
            'quantity' => 5,
        ]);
        $city = $this->city();

        // Запрос вариации отдаёт parent'а — даты должны быть и там.
        $this->getJson("/api/v1/products/{$child->id}?cityId={$city->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $parent->id)
            ->assertJsonPath('data.deliveryDate', now()->format('Y-m-d'));
    }

    public function test_delivery_dates_returned_in_similar_products(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);
        Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);
        $city = $this->city();

        $this->getJson("/api/v1/products/{$product->id}/similar?cityId={$city->id}")
            ->assertOk()
            ->assertJsonPath('data.0.deliveryDate', now()->format('Y-m-d'));
    }

    public function test_delivery_dates_returned_in_related_products(): void
    {
        $store = Store::factory()->create();
        $parentCategory = Category::factory()->forStore($store)->create();
        $category = Category::factory()->childOf($parentCategory)->forStore($store)->create();
        $sibling = Category::factory()->childOf($parentCategory)->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create(['quantity' => 10]);
        Product::factory()->for($store)->inCategory($sibling)->create(['quantity' => 10]);
        $city = $this->city();

        $this->getJson("/api/v1/products/{$product->id}/related?cityId={$city->id}")
            ->assertOk()
            ->assertJsonPath('data.0.products.0.deliveryDate', now()->format('Y-m-d'));
    }

    public function test_delivery_dates_returned_in_barcode_search(): void
    {
        $store = Store::factory()->create();
        Product::factory()->for($store)->create([
            'barcodes' => ['1234567890'],
            'quantity' => 10,
        ]);
        $city = $this->city();

        $this->getJson("/api/v1/products/search/barcode/1234567890?cityId={$city->id}")
            ->assertOk()
            ->assertJsonPath('data.deliveryDate', now()->format('Y-m-d'));
    }
}
