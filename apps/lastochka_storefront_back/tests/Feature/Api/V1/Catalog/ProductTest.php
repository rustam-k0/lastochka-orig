<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Catalog;

use App\Models\Category;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductImage;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_products_by_category(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product1 = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Молоко 3.2%',
        ]);
        $product2 = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Молоко 2.5%',
        ]);
        Product::factory()->inactive()->for($store)->inCategory($category)->create([
            'title' => 'Неактивный',
        ]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/{$category->id}/products");

        $response->assertOk()
            ->assertJsonStructure([
                'hasMore',
                'data' => [
                    '*' => [
                        'id', 'sku', 'title', 'description', 'price', 'priceOld',
                        'currency', 'images', 'isFavorite', 'composition',
                        'proteins', 'fats', 'carbohydrates', 'calories', 'attributes',
                        'rating', 'reviewsCount',
                    ],
                ],
                'total',
            ])
            ->assertJsonPath('total', 2);
    }

    public function test_get_products_pagination(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        Product::factory()->count(25)->for($store)->inCategory($category)->create([]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories/{$category->id}/products?perPage=10");

        $response->assertOk()
            ->assertJsonPath('hasMore', true)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 25);
    }

    public function test_get_product(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Молоко 3.2%',
            'description' => 'Вкусное молоко',
            'price' => 89,
            'price_old' => 99,
        ]);
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'https://cdn.example.com/milk.jpg',
            'position' => 0,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.title', 'Молоко 3.2%')
            ->assertJsonPath('data.description', 'Вкусное молоко')
            ->assertJsonPath('data.price', 89)
            ->assertJsonPath('data.priceOld', 99)
            ->assertJsonPath('data.currency', 'RUB')
            ->assertJsonPath('data.images.0.path', 'https://cdn.example.com/milk.jpg')
            ->assertJsonPath('data.isFavorite', false);
    }

    public function test_get_product_returns_additional_info(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'additional_info' => 'Гарантия производителя 12 месяцев',
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.additionalInfo', 'Гарантия производителя 12 месяцев');
    }

    public function test_get_product_returns_null_additional_info_by_default(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.additionalInfo', null);
    }

    public function test_get_product_returns_category(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Молочные продукты',
            'slug' => 'dairy',
        ]);
        $product = Product::factory()->for($store)->inCategory($category)->create([]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.category.name', 'Молочные продукты')
            ->assertJsonPath('data.category.slug', 'dairy');
    }

    public function test_get_product_returns_primary_category_by_pivot_position(): void
    {
        $store = Store::factory()->create();
        $first = Category::factory()->forStore($store)->create(['name' => 'Первая', 'slug' => 'first']);
        $second = Category::factory()->forStore($store)->create(['name' => 'Вторая', 'slug' => 'second']);

        // Привязываем во "втором" порядке, но с меньшей позицией — она должна стать primary.
        $product = Product::factory()->for($store)
            ->inCategory($second, position: 5)
            ->inCategory($first, position: 0)
            ->create([]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.category.id', $first->id)
            ->assertJsonPath('data.category.slug', 'first');
    }

    public function test_get_product_returns_null_category_when_uncategorized(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create([]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.category', null);
    }

    public function test_get_product_with_attributes(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([]);
        ProductAttribute::create([
            'product_id' => $product->id,
            'code' => 'shelf_life',
            'name' => 'Срок хранения',
            'value' => '14 дней',
            'position' => 0,
        ]);
        ProductAttribute::create([
            'product_id' => $product->id,
            'code' => 'volume',
            'name' => 'Объём',
            'value' => '1 л',
            'position' => 1,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}?includeAttributes=true");

        $response->assertOk()
            ->assertJsonCount(2, 'data.attributes')
            ->assertJsonPath('data.attributes.0.key', 'Срок хранения')
            ->assertJsonPath('data.attributes.0.value', '14 дней')
            ->assertJsonPath('data.attributes.0.sortOrder', 0)
            ->assertJsonPath('data.attributes.1.key', 'Объём')
            ->assertJsonPath('data.attributes.1.value', '1 л');
    }

    public function test_get_similar_products(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([]);
        Product::factory()->count(5)->for($store)->inCategory($category)->create([]);

        $response = $this->getJson("/api/v1/products/{$product->id}/similar");

        $response->assertOk()
            ->assertJsonStructure([
                'hasMore',
                'data' => [
                    '*' => ['id', 'sku', 'title', 'price', 'images'],
                ],
                'total',
            ])
            ->assertJsonPath('total', 5);

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($product->id, $ids);
    }

    public function test_is_favorite_for_auth_user(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([]);
        Favorite::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.isFavorite', true);
    }

    public function test_is_favorite_false_for_guest(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.isFavorite', false);
    }

    public function test_product_not_found(): void
    {
        $response = $this->getJson('/api/v1/products/999');

        $response->assertNotFound();
    }

    public function test_product_with_active_promotion(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'price_old' => null,
        ]);

        $promotion = Promotion::factory()
            ->for($store)
            ->withDiscountPercent(20)
            ->create();
        $promotion->products()->attach($product);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.price', 80)           // 100 - 20% = 80
            ->assertJsonPath('data.priceOld', 100)
            ->assertJsonPath('data.discountPercent', 20);
    }

    public function test_product_promotion_discount_greater_than_manual(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 90,          // Ручная скидка 10%
            'price_old' => 100,
        ]);

        // Акция 30% — больше ручной
        $promotion = Promotion::factory()
            ->for($store)
            ->withDiscountPercent(30)
            ->create();
        $promotion->products()->attach($product);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.price', 70)           // 100 - 30% = 70
            ->assertJsonPath('data.priceOld', 100)
            ->assertJsonPath('data.discountPercent', 30);
    }

    public function test_product_manual_discount_greater_than_promotion(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 60,          // Ручная скидка 40%
            'price_old' => 100,
        ]);

        // Акция 10% — меньше ручной
        $promotion = Promotion::factory()
            ->for($store)
            ->withDiscountPercent(10)
            ->create();
        $promotion->products()->attach($product);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.price', 60)           // Ручная скидка больше — оставляем 60
            ->assertJsonPath('data.priceOld', 100)
            ->assertJsonPath('data.discountPercent', 40);
    }

    public function test_product_without_promotion_no_discount(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'price_old' => null,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.price', 100)
            ->assertJsonPath('data.priceOld', null)
            ->assertJsonPath('data.discountPercent', null);
    }

    public function test_product_with_expired_promotion(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'price_old' => null,
        ]);

        // Истёкшая акция
        $promotion = Promotion::factory()
            ->for($store)
            ->withDiscountPercent(20)
            ->expired()
            ->create();
        $promotion->products()->attach($product);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.price', 100)          // Акция истекла — скидка не применяется
            ->assertJsonPath('data.priceOld', null)
            ->assertJsonPath('data.discountPercent', null);
    }

    public function test_product_returns_rating_and_reviews_count(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'rating' => 4.5,
            'reviews_count' => 12,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.rating', 4.5)
            ->assertJsonPath('data.reviewsCount', 12);
    }

    public function test_product_returns_null_rating_when_no_reviews(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'rating' => null,
            'reviews_count' => 0,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.rating', null)
            ->assertJsonPath('data.reviewsCount', 0);
    }
}
