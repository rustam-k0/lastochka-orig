<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ProductsHasReviewsFilterTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;
    protected Shop $shop;
    protected Product $productWithReview;
    protected Product $productWithoutReview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();

        $this->shop = Shop::create([
            'title' => 'HasReviews Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $this->productWithReview = $this->createProduct('С отзывом');
        $this->productWithoutReview = $this->createProduct('Без отзыва');

        ProductReview::create([
            'shop_id' => $this->shop->id,
            'product_id' => $this->productWithReview->id,
            'rating' => 4,
            'is_published' => true,
        ]);
    }

    protected function createProduct(string $prefix): Product
    {
        return Product::create([
            'title' => $prefix . ' ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => Status::CODE_ACTIVE,
        ]);
    }

    protected function fetchIds(array $variables): array
    {
        $response = $this->actingAs($this->adminUser)
            ->graphQL('
                query ($shop_id: Int, $has_reviews: Boolean, $first: Int) {
                    admin_products_paginate(shop_id: $shop_id, has_reviews: $has_reviews, first: $first) {
                        data { id }
                    }
                }
            ', array_merge(['shop_id' => $this->shop->id, 'first' => 100], $variables));

        $response->assertGraphQLErrorFree();

        return collect($response->json('data.admin_products_paginate.data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @test */
    public function has_reviews_true_returns_only_products_with_reviews()
    {
        $ids = $this->fetchIds(['has_reviews' => true]);

        $this->assertContains($this->productWithReview->id, $ids);
        $this->assertNotContains($this->productWithoutReview->id, $ids);
    }

    /** @test */
    public function without_has_reviews_returns_all_products()
    {
        $ids = $this->fetchIds([]);

        $this->assertContains($this->productWithReview->id, $ids);
        $this->assertContains($this->productWithoutReview->id, $ids);
    }

    /** @test */
    public function has_reviews_false_returns_only_products_without_reviews()
    {
        $ids = $this->fetchIds(['has_reviews' => false]);

        $this->assertNotContains($this->productWithReview->id, $ids);
        $this->assertContains($this->productWithoutReview->id, $ids);
    }
}
