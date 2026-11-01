<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Promotion;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PromotionTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Get Promotions ====================

    public function test_get_promotions(): void
    {
        $store = Store::factory()->create();
        $promotion1 = Promotion::factory()->for($store)->create(['position' => 1]);
        $promotion2 = Promotion::factory()->for($store)->create(['position' => 2]);

        // Неактивная акция — не должна попасть в список
        Promotion::factory()->for($store)->inactive()->create();

        // Истёкшая акция — не должна попасть в список
        Promotion::factory()->for($store)->expired()->create();

        $response = $this->getJson('/api/v1/promotions');

        $response->assertOk()
            ->assertJsonStructure([
                'hasMore',
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'description',
                        'image',
                        'store' => ['id', 'name', 'description'],
                        'discountPercent',
                        'startsAt',
                        'endsAt',
                        'productsCount',
                    ],
                ],
                'total',
            ])
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $promotion1->id)
            ->assertJsonPath('data.1.id', $promotion2->id);
    }

    public function test_get_promotions_by_store(): void
    {
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        Promotion::factory()->for($store1)->create();
        Promotion::factory()->for($store1)->create();
        Promotion::factory()->for($store2)->create();

        $response = $this->getJson('/api/v1/promotions?storeId='.$store1->id);

        $response->assertOk()
            ->assertJsonPath('total', 2);

        // Проверяем, что все акции принадлежат store1
        $data = $response->json('data');
        foreach ($data as $promotion) {
            $this->assertEquals($store1->id, $promotion['store']['id']);
        }
    }

    public function test_get_promotions_pagination(): void
    {
        $store = Store::factory()->create();
        Promotion::factory()->for($store)->count(25)->create();

        $response = $this->getJson('/api/v1/promotions?perPage=10');

        $response->assertOk()
            ->assertJsonPath('hasMore', true)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 25);
    }

    public function test_get_promotions_sorted_by_position(): void
    {
        $store = Store::factory()->create();
        $promo3 = Promotion::factory()->for($store)->create(['position' => 3]);
        $promo1 = Promotion::factory()->for($store)->create(['position' => 1]);
        $promo2 = Promotion::factory()->for($store)->create(['position' => 2]);

        $response = $this->getJson('/api/v1/promotions');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals($promo1->id, $data[0]['id']);
        $this->assertEquals($promo2->id, $data[1]['id']);
        $this->assertEquals($promo3->id, $data[2]['id']);
    }

    // ==================== Get Promotion ====================

    public function test_get_promotion(): void
    {
        $store = Store::factory()->create();
        $promotion = Promotion::factory()->for($store)->create();

        $response = $this->getJson('/api/v1/promotions/'.$promotion->slug);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'description',
                    'image',
                    'store',
                    'discountPercent',
                    'startsAt',
                    'endsAt',
                    'productsCount',
                ],
            ])
            ->assertJsonPath('data.id', $promotion->id)
            ->assertJsonPath('data.store.id', $store->id);
    }

    public function test_get_promotion_not_found(): void
    {
        $response = $this->getJson('/api/v1/promotions/99999');

        $response->assertNotFound();
    }

    public function test_get_inactive_promotion_returns_404(): void
    {
        $store = Store::factory()->create();
        $promotion = Promotion::factory()->for($store)->inactive()->create();

        $response = $this->getJson('/api/v1/promotions/'.$promotion->slug);

        $response->assertNotFound();
    }

    public function test_get_expired_promotion_returns_404(): void
    {
        $store = Store::factory()->create();
        $promotion = Promotion::factory()->for($store)->expired()->create();

        $response = $this->getJson('/api/v1/promotions/'.$promotion->slug);

        $response->assertNotFound();
    }

    // ==================== Get Promotion Products ====================

    public function test_get_promotion_products(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $promotion = Promotion::factory()
            ->for($store)
            ->withDiscountPercent(15)
            ->create();

        // Товар с ценой 100, скидка 15% = 85
        $product = Product::factory()->for($store)->create([
            'price' => 100,
            'price_old' => null,
            'weight' => 750,
            'measurement_unit' => 'liter',
            'quantity' => 10000, // остаток витрины объёмного — в мл (10 л)
        ]);
        $promotion->products()->attach($product);

        $response = $this->actingAs($user)->getJson('/api/v1/promotions/'.$promotion->slug.'/products');

        $response->assertOk()
            ->assertJsonStructure([
                'hasMore',
                'data' => [
                    '*' => [
                        'id',
                        'sku',
                        'title',
                        'price',
                        'priceOld',
                        'discountPercent',
                        'currency',
                        'weight',
                        'measurementUnit',
                        'measurementUnitLabel',
                        'images',
                        'isFavorite',
                    ],
                ],
                'total',
            ])
            ->assertJsonPath('data.0.price', 85)
            ->assertJsonPath('data.0.priceOld', 100)
            ->assertJsonPath('data.0.discountPercent', 15)
            ->assertJsonPath('data.0.currency', 'RUB')
            ->assertJsonPath('data.0.weight', 750)
            ->assertJsonPath('data.0.measurementUnit', 'liter')
            ->assertJsonPath('data.0.measurementUnitLabel', 'л')
            ->assertJsonPath('data.0.isFavorite', false);
    }

    public function test_get_promotion_products_ordered_by_title_then_id(): void
    {
        $store = Store::factory()->create();
        // {promotion} в роуте резолвится по slug (Promotion::getRouteKeyName)
        $promotion = Promotion::factory()->for($store)->withDiscountPercent(10)->create(['slug' => 'sale-hits']);

        // Порядок привязки намеренно не алфавитный: без ORDER BY страницы
        // при LIMIT/OFFSET могли содержать дубли и пропуски.
        $beta = Product::factory()->for($store)->create(['title' => 'Бета']);
        $gamma = Product::factory()->for($store)->create(['title' => 'Гамма']);
        $alpha = Product::factory()->for($store)->create(['title' => 'Альфа']);
        $alphaTwin = Product::factory()->for($store)->create(['title' => 'Альфа']);
        $promotion->products()->attach([$gamma->id, $beta->id, $alphaTwin->id, $alpha->id]);

        $response = $this->getJson('/api/v1/promotions/sale-hits/products');

        $response->assertOk();
        $this->assertEquals(
            [$alpha->id, $alphaTwin->id, $beta->id, $gamma->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_get_promotion_products_excludes_inactive(): void
    {
        $store = Store::factory()->create();
        $promotion = Promotion::factory()->for($store)->create();

        $activeProduct = Product::factory()->for($store)->create();
        $inactiveProduct = Product::factory()->for($store)->inactive()->create();

        $promotion->products()->attach([$activeProduct->id, $inactiveProduct->id]);

        $response = $this->getJson('/api/v1/promotions/'.$promotion->slug.'/products');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $activeProduct->id);
    }

    public function test_get_promotion_products_excludes_out_of_stock(): void
    {
        $store = Store::factory()->create();
        $promotion = Promotion::factory()->for($store)->create();

        $inStockProduct = Product::factory()->for($store)->create(['quantity' => 10]);
        $outOfStockProduct = Product::factory()->for($store)->outOfStock()->create();

        $promotion->products()->attach([$inStockProduct->id, $outOfStockProduct->id]);

        $response = $this->getJson('/api/v1/promotions/'.$promotion->slug.'/products');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $inStockProduct->id);
    }

    // ==================== Price Calculation ====================

    public function test_promotion_price_calculation(): void
    {
        $store = Store::factory()->create();
        $promotion = Promotion::factory()
            ->for($store)
            ->withDiscountPercent(20)
            ->create();

        // Товар без ручной скидки, price=1000, скидка 20% = 800
        $product = Product::factory()->for($store)->create([
            'price' => 1000,
            'price_old' => null,
        ]);
        $promotion->products()->attach($product);

        $response = $this->getJson('/api/v1/promotions/'.$promotion->slug.'/products');

        $response->assertOk()
            ->assertJsonPath('data.0.price', 800)
            ->assertJsonPath('data.0.priceOld', 1000)
            ->assertJsonPath('data.0.discountPercent', 20);
    }

    public function test_promotion_price_preserves_kopecks(): void
    {
        $store = Store::factory()->create();
        $promotion = Promotion::factory()
            ->for($store)
            ->withDiscountPercent(20)
            ->create();

        // Дробная цена: price=100.50, скидка 20% => 100.50 * 0.8 = 80.40 (копейки не должны теряться)
        $product = Product::factory()->for($store)->create([
            'price' => 100.50,
            'price_old' => null,
        ]);
        $promotion->products()->attach($product);

        $response = $this->getJson('/api/v1/promotions/'.$promotion->slug.'/products');

        $response->assertOk()
            ->assertJsonPath('data.0.price', 80.4)
            ->assertJsonPath('data.0.priceOld', 100.5)
            ->assertJsonPath('data.0.discountPercent', 20);
    }

    public function test_manual_discount_vs_promotion(): void
    {
        $store = Store::factory()->create();

        // Акция с 10% скидкой
        $promotion = Promotion::factory()
            ->for($store)
            ->withDiscountPercent(10)
            ->create();

        // Товар с ручной скидкой 25% (price_old=1000, price=750 = 25% скидка)
        // Ручная скидка (250) > акционная скидка (100), применяется ручная
        $product = Product::factory()->for($store)->create([
            'price' => 750,
            'price_old' => 1000,
        ]);
        $promotion->products()->attach($product);

        // Проверяем через модель
        $product->refresh();
        $effectivePrice = $product->getEffectivePrice();

        // Ручная скидка 250, акционная 100 (10% от 1000) — берётся большая = 250
        // Итого: 1000 - 250 = 750
        $this->assertEquals(750, $effectivePrice);
    }

    public function test_promotion_discount_greater_than_manual(): void
    {
        $store = Store::factory()->create();

        // Акция с 30% скидкой
        $promotion = Promotion::factory()
            ->for($store)
            ->withDiscountPercent(30)
            ->create();

        // Товар с ручной скидкой 10% (price_old=1000, price=900)
        // Акционная скидка (300) > ручная скидка (100), применяется акционная
        $product = Product::factory()->for($store)->create([
            'price' => 900,
            'price_old' => 1000,
        ]);
        $promotion->products()->attach($product);

        // Проверяем через модель
        $product->refresh();
        $effectivePrice = $product->getEffectivePrice();

        // Ручная скидка 100, акционная 300 (30% от 1000) — берётся большая = 300
        // Итого: 1000 - 300 = 700
        $this->assertEquals(700, $effectivePrice);
    }

    public function test_products_count(): void
    {
        $store = Store::factory()->create();
        $promotion = Promotion::factory()->for($store)->create();

        $products = Product::factory()->for($store)->count(5)->create();
        $promotion->products()->attach($products->pluck('id'));

        $response = $this->getJson('/api/v1/promotions/'.$promotion->slug);

        $response->assertOk()
            ->assertJsonPath('data.productsCount', 5);
    }
}
