<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Catalog;

use App\Models\Category;
use App\Models\City;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductByCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        // Слоты доставки генерируются с 8:00 до 20:00 и только в будущее:
        // без фиксации времени delivery-тесты зеленеют днём и падают вечером.
        $this->travelTo(now()->startOfDay()->addHours(9));

        $this->store = Store::factory()->create();
        $this->category = Category::factory()->forStore($this->store)->create();
    }

    private function endpoint(): string
    {
        return "/api/v1/stores/{$this->store->id}/categories/{$this->category->id}/products";
    }

    // ==================== Sort ====================

    public function test_sort_by_price_asc(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 500, 'title' => 'Дорогой']);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 100, 'title' => 'Дешёвый']);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 300, 'title' => 'Средний']);

        $response = $this->getJson($this->endpoint().'?sort=price_asc');

        $response->assertOk();
        $prices = collect($response->json('data'))->pluck('price')->all();
        $this->assertEquals([100, 300, 500], $prices);
    }

    public function test_sort_by_price_desc(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 100]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 500]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 300]);

        $response = $this->getJson($this->endpoint().'?sort=price_desc');

        $response->assertOk();
        $prices = collect($response->json('data'))->pluck('price')->all();
        $this->assertEquals([500, 300, 100], $prices);
    }

    public function test_sort_by_newest(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Old', 'created_at' => now()->subDays(3)]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'New', 'created_at' => now()]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Mid', 'created_at' => now()->subDay()]);

        $response = $this->getJson($this->endpoint().'?sort=newest');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEquals(['New', 'Mid', 'Old'], $titles);
    }

    public function test_sort_by_discount(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'No discount', 'price' => 100, 'price_old' => null]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Big discount', 'price' => 50, 'price_old' => 200]);  // 75%
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Small discount', 'price' => 180, 'price_old' => 200]); // 10%

        $response = $this->getJson($this->endpoint().'?sort=discount');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEquals(['Big discount', 'Small discount', 'No discount'], $titles);
    }

    public function test_sort_by_popular(): void
    {
        $order = Order::factory()->create(['store_id' => $this->store->id]);

        $popular = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Popular']);
        $unpopular = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Unpopular']);

        OrderItem::factory()->count(5)->create(['order_id' => $order->id, 'product_id' => $popular->id]);
        OrderItem::factory()->count(1)->create(['order_id' => $order->id, 'product_id' => $unpopular->id]);

        $response = $this->getJson($this->endpoint().'?sort=popular');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEquals(['Popular', 'Unpopular'], $titles);
    }

    public function test_default_sort_is_by_title(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Bananas']);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Apples']);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Cherries']);

        $response = $this->getJson($this->endpoint());

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEquals(['Apples', 'Bananas', 'Cherries'], $titles);
    }

    // ==================== Sort tie-break (детерминированная пагинация) ====================

    /**
     * Регресс: у 128 из 129 товаров категории «Йогурты» на проде 0 заказов,
     * ORDER BY order_items_count DESC без вторичного ключа отдавал разные
     * окна на LIMIT/OFFSET — мобилка (sort=popular, perPage=12) показывала
     * один товар дважды, а другие не показывала вовсе.
     */
    public function test_sort_by_popular_breaks_ties_by_title_then_id(): void
    {
        $order = Order::factory()->create(['store_id' => $this->store->id]);

        // Порядок вставки намеренно не алфавитный: без tie-break SQLite
        // вернёт равные по популярности строки в порядке rowid.
        Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Бета']);
        Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Гамма']);
        Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Альфа']);
        $popular = Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Омега']);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $popular->id]);

        $response = $this->getJson($this->endpoint().'?sort=popular');

        $response->assertOk();
        $this->assertEquals(
            ['Омега', 'Альфа', 'Бета', 'Гамма'],
            collect($response->json('data'))->pluck('title')->all(),
        );
    }

    public function test_sort_by_price_breaks_ties_by_title_then_id(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create(['price' => 100, 'title' => 'Бета']);
        Product::factory()->for($this->store)->inCategory($this->category)->create(['price' => 100, 'title' => 'Альфа']);
        Product::factory()->for($this->store)->inCategory($this->category)->create(['price' => 50, 'title' => 'Гамма']);

        $asc = $this->getJson($this->endpoint().'?sort=price_asc');
        $asc->assertOk();
        $this->assertEquals(['Гамма', 'Альфа', 'Бета'], collect($asc->json('data'))->pluck('title')->all());

        $desc = $this->getJson($this->endpoint().'?sort=price_desc');
        $desc->assertOk();
        $this->assertEquals(['Альфа', 'Бета', 'Гамма'], collect($desc->json('data'))->pluck('title')->all());
    }

    public function test_sort_by_discount_breaks_ties_by_title_then_id(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create(['price' => 80, 'price_old' => 100, 'title' => 'Бета']);
        Product::factory()->for($this->store)->inCategory($this->category)->create(['price' => 80, 'price_old' => 100, 'title' => 'Альфа']);
        Product::factory()->for($this->store)->inCategory($this->category)->create(['price' => 100, 'price_old' => null, 'title' => 'Гамма']);

        $response = $this->getJson($this->endpoint().'?sort=discount');

        $response->assertOk();
        $this->assertEquals(['Альфа', 'Бета', 'Гамма'], collect($response->json('data'))->pluck('title')->all());
    }

    public function test_sort_by_newest_breaks_equal_created_at_by_id_desc(): void
    {
        // Массовый импорт из 1С создаёт сотни товаров в одну секунду.
        $sameSecond = now()->subDay();
        $first = Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Первый', 'created_at' => $sameSecond]);
        $second = Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Второй', 'created_at' => $sameSecond]);
        $newest = Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Свежий', 'created_at' => now()]);

        $response = $this->getJson($this->endpoint().'?sort=newest');

        $response->assertOk();
        $this->assertEquals(
            [$newest->id, $second->id, $first->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_default_sort_breaks_equal_titles_by_id(): void
    {
        $first = Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Йогурт']);
        $second = Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Йогурт']);
        $other = Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Кефир']);

        $response = $this->getJson($this->endpoint());

        $response->assertOk();
        $this->assertEquals(
            [$first->id, $second->id, $other->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_popular_pagination_has_no_duplicates_and_no_gaps(): void
    {
        $expected = collect();
        foreach (['Бета', 'Гамма', 'Альфа', 'Эпсилон', 'Дельта'] as $title) {
            $expected->push(Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => $title])->id);
        }

        $seen = collect();
        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson($this->endpoint()."?sort=popular&perPage=2&page={$page}");
            $response->assertOk();
            $seen = $seen->merge(collect($response->json('data'))->pluck('id'));
        }

        $this->assertCount(5, $seen);
        $this->assertEquals($expected->sort()->values()->all(), $seen->sort()->values()->all());
    }

    // ==================== Price filter ====================

    public function test_filter_by_price_min(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 50]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 200]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 500]);

        $response = $this->getJson($this->endpoint().'?price_min=200');

        $response->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_filter_by_price_max(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 50]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 200]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 500]);

        $response = $this->getJson($this->endpoint().'?price_max=200');

        $response->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_filter_by_price_range(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 50]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 200]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 500]);

        $response = $this->getJson($this->endpoint().'?price_min=100&price_max=300');

        $response->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_sort_and_filter_combined(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 50]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 300]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 200]);
        Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 500]);

        $response = $this->getJson($this->endpoint().'?price_min=100&price_max=400&sort=price_desc');

        $response->assertOk()
            ->assertJsonPath('total', 2);
        $prices = collect($response->json('data'))->pluck('price')->all();
        $this->assertEquals([300, 200], $prices);
    }

    // ==================== Attribute filters ====================

    private function attachSelectAttribute(Product $product, string $code, string $valueCode, int $valueId): void
    {
        ProductAttribute::create([
            'product_id' => $product->id,
            'code' => $code,
            'name' => ucfirst($code),
            'value' => $valueCode,
            'attribute_value_id' => $valueId,
            'attribute_value_code' => $valueCode,
            'position' => 0,
        ]);
    }

    public function test_filter_by_single_attribute_value(): void
    {
        $schneider = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Schneider']);
        $abb = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'ABB']);
        $legrand = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Legrand']);

        $this->attachSelectAttribute($schneider, 'manufacturer', 'schneider', 1);
        $this->attachSelectAttribute($abb, 'manufacturer', 'abb', 2);
        $this->attachSelectAttribute($legrand, 'manufacturer', 'legrand', 3);

        $response = $this->getJson($this->endpoint().'?filter[manufacturer]=schneider');

        $response->assertOk()->assertJsonPath('total', 1);
        $this->assertSame('Schneider', $response->json('data.0.title'));
    }

    public function test_filter_by_multiple_values_uses_or(): void
    {
        $schneider = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Schneider']);
        $abb = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'ABB']);
        $legrand = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Legrand']);

        $this->attachSelectAttribute($schneider, 'manufacturer', 'schneider', 1);
        $this->attachSelectAttribute($abb, 'manufacturer', 'abb', 2);
        $this->attachSelectAttribute($legrand, 'manufacturer', 'legrand', 3);

        $response = $this->getJson($this->endpoint().'?filter[manufacturer]=schneider,abb');

        $response->assertOk()->assertJsonPath('total', 2);
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Schneider', 'ABB'], $titles);
    }

    public function test_filter_by_multiple_codes_uses_and(): void
    {
        $match = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'Match']);
        $onlyBrand = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'OnlyBrand']);
        $onlyVoltage = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'title' => 'OnlyVoltage']);

        $this->attachSelectAttribute($match, 'manufacturer', 'schneider', 1);
        $this->attachSelectAttribute($match, 'voltage', '220v', 10);

        $this->attachSelectAttribute($onlyBrand, 'manufacturer', 'schneider', 1);

        $this->attachSelectAttribute($onlyVoltage, 'voltage', '220v', 10);

        $response = $this->getJson($this->endpoint().'?filter[manufacturer]=schneider&filter[voltage]=220v');

        $response->assertOk()->assertJsonPath('total', 1);
        $this->assertSame('Match', $response->json('data.0.title'));
    }

    public function test_filter_by_unknown_code_returns_empty(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create();

        $response = $this->getJson($this->endpoint().'?filter[manufacturer]=unknown');

        $response->assertOk()->assertJsonPath('total', 0);
    }

    public function test_empty_filter_is_ignored(): void
    {
        Product::factory()->count(3)->for($this->store)->inCategory($this->category)->create();

        $response = $this->getJson($this->endpoint().'?filter[manufacturer]=');

        $response->assertOk()->assertJsonPath('total', 3);
    }

    // ==================== count_only ====================

    public function test_count_only_returns_total_without_data(): void
    {
        Product::factory()->count(4)->for($this->store)->inCategory($this->category)->create([ 'price' => 300]);

        $response = $this->getJson($this->endpoint().'?count_only=1');

        $response->assertOk();
        $this->assertSame(4, $response->json('total'));
        $this->assertNull($response->json('data'));
        $this->assertNull($response->json('hasMore'));
    }

    public function test_count_only_respects_filters(): void
    {
        $schneider = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 300]);
        $abb = Product::factory()->for($this->store)->inCategory($this->category)->create([ 'price' => 300]);

        $this->attachSelectAttribute($schneider, 'manufacturer', 'schneider', 1);
        $this->attachSelectAttribute($abb, 'manufacturer', 'abb', 2);

        $response = $this->getJson($this->endpoint().'?count_only=1&filter[manufacturer]=schneider&price_min=100');

        $response->assertOk();
        $this->assertSame(1, $response->json('total'));
    }

    // ==================== Delivery dates ====================

    public function test_delivery_date_returned_with_city_id(): void
    {
        $city = City::create([
            'name' => 'Москва',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'is_active' => true,
        ]);

        Product::factory()->for($this->store)->inCategory($this->category)->create();

        $response = $this->getJson($this->endpoint()."?city_id={$city->id}");

        $response->assertOk();
        $product = $response->json('data.0');
        $this->assertArrayHasKey('deliveryDate', $product);
        $this->assertEquals(now()->format('Y-m-d'), $product['deliveryDate']);
    }

    public function test_pickup_date_returned_with_city_id(): void
    {
        $store = Store::factory()->withSchedule()->create(['is_pickup_enabled' => true]);
        $category = Category::factory()->forStore($store)->create();
        $city = City::create([
            'name' => 'Москва',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'is_active' => true,
        ]);

        Product::factory()->for($store)->inCategory($category)->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/{$category->id}/products?city_id={$city->id}");

        $response->assertOk();
        $product = $response->json('data.0');
        $this->assertArrayHasKey('pickupDate', $product);
        $this->assertNotNull($product['pickupDate']);
    }

    public function test_no_delivery_dates_without_city_id(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create();

        $response = $this->getJson($this->endpoint());

        $response->assertOk();
        $product = $response->json('data.0');
        $this->assertArrayNotHasKey('deliveryDate', $product);
        $this->assertArrayNotHasKey('pickupDate', $product);
    }

    // ==================== Sort: name / relevance (MID-304) ====================

    public function test_sort_by_name(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Bananas', 'price' => 100]);
        Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Apples', 'price' => 300]);
        Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Cherries', 'price' => 200]);

        $response = $this->getJson($this->endpoint() . '?sort=name');

        $response->assertOk();
        $this->assertSame(['Apples', 'Bananas', 'Cherries'], collect($response->json('data'))->pluck('title')->all());
    }

    public function test_sort_by_relevance_in_category_orders_by_name(): void
    {
        Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Bananas', 'price' => 100]);
        Product::factory()->for($this->store)->inCategory($this->category)->create(['title' => 'Apples', 'price' => 300]);

        $response = $this->getJson($this->endpoint() . '?sort=relevance');

        $response->assertOk();
        $this->assertSame(['Apples', 'Bananas'], collect($response->json('data'))->pluck('title')->all());
    }
}
