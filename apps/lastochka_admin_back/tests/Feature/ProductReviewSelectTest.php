<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ProductReviewSelectTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;
    protected Shop $shop;
    protected Shop $anotherShop;
    protected Product $activeProduct;
    protected Product $unpublishedProduct;
    protected Product $anotherShopProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();

        $this->shop = $this->createShop();
        $this->anotherShop = $this->createShop();

        $this->activeProduct = $this->createProduct($this->shop, Status::CODE_ACTIVE);
        $this->unpublishedProduct = $this->createProduct($this->shop, Status::CODE_NOT_PUBLISHED);
        $this->anotherShopProduct = $this->createProduct($this->anotherShop, Status::CODE_ACTIVE);
    }

    protected function createShop(): Shop
    {
        return Shop::create([
            'title' => 'Review Select Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    protected function createProduct(Shop $shop, int $statusId): Product
    {
        return Product::create([
            'title' => 'Review Select Product ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => $statusId,
        ]);
    }

    /** @test */
    public function products_paginate_filters_by_status_id()
    {
        $query = '
            query ($shop_id: Int, $status_id: Int, $first: Int) {
                admin_products_paginate(shop_id: $shop_id, status_id: $status_id, first: $first) {
                    data {
                        id
                        status_id
                    }
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
                'status_id' => Status::CODE_ACTIVE,
                'first' => 100,
            ]);

        $response->assertGraphQLErrorFree();

        $ids = collect($response->json('data.admin_products_paginate.data'))->pluck('id')->map(fn ($id) => (int) $id);

        $this->assertTrue($ids->contains($this->activeProduct->id));
        $this->assertFalse($ids->contains($this->unpublishedProduct->id));
        $this->assertFalse($ids->contains($this->anotherShopProduct->id));
    }

    /** @test */
    public function products_paginate_searches_by_title_with_status_filter()
    {
        $needle = 'НужныйТовар' . uniqid();
        $target = Product::create([
            'title' => 'Мясо организации ' . $needle,
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => Status::CODE_ACTIVE,
        ]);

        $query = '
            query ($shop_id: Int, $status_id: Int, $title: String, $first: Int) {
                admin_products_paginate(shop_id: $shop_id, status_id: $status_id, title: $title, first: $first) {
                    data {
                        id
                    }
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
                'status_id' => Status::CODE_ACTIVE,
                'title' => $needle,
                'first' => 100,
            ]);

        $response->assertGraphQLErrorFree();

        $ids = collect($response->json('data.admin_products_paginate.data'))->pluck('id')->map(fn ($id) => (int) $id);

        $this->assertTrue($ids->contains($target->id));
        $this->assertFalse($ids->contains($this->activeProduct->id));
    }

    protected function createReviewMutation(): string
    {
        return '
            mutation CreateProductReview($input: CreateProductReviewInput!) {
                createProductReview(input: $input) {
                    id
                    shop_id
                    product_id
                }
            }
        ';
    }

    /** @test */
    public function review_can_be_created_for_active_product_of_shop()
    {
        $response = $this->actingAs($this->adminUser)
            ->graphQL($this->createReviewMutation(), [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'product_id' => $this->activeProduct->id,
                    'rating' => 5,
                ],
            ]);

        $response->assertJson([
            'data' => [
                'createProductReview' => [
                    'shop_id' => $this->shop->id,
                    'product_id' => $this->activeProduct->id,
                ],
            ],
        ]);

        $this->assertDatabaseHas('product_reviews', [
            'shop_id' => $this->shop->id,
            'product_id' => $this->activeProduct->id,
            'rating' => 5,
        ]);
    }

    /** @test */
    public function review_cannot_be_created_for_product_of_another_shop()
    {
        $response = $this->actingAs($this->adminUser)
            ->graphQL($this->createReviewMutation(), [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'product_id' => $this->anotherShopProduct->id,
                    'rating' => 5,
                ],
            ]);

        $this->assertNotEmpty($response->json('errors'), 'Ожидалась ошибка валидации: товар другого магазина');

        $this->assertDatabaseMissing('product_reviews', [
            'shop_id' => $this->shop->id,
            'product_id' => $this->anotherShopProduct->id,
        ]);
    }

    /** @test */
    public function review_cannot_be_created_for_unpublished_product()
    {
        $response = $this->actingAs($this->adminUser)
            ->graphQL($this->createReviewMutation(), [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'product_id' => $this->unpublishedProduct->id,
                    'rating' => 5,
                ],
            ]);

        $this->assertNotEmpty($response->json('errors'), 'Ожидалась ошибка валидации: товар не опубликован');

        $this->assertDatabaseMissing('product_reviews', [
            'shop_id' => $this->shop->id,
            'product_id' => $this->unpublishedProduct->id,
        ]);
    }
}
