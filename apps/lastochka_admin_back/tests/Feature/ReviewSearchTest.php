<?php

namespace Tests\Feature;

use App\Models\MerchantReview;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ReviewSearchTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;
    protected string $marker;
    protected Shop $shop;
    protected Product $product;
    protected ProductReview $review;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();

        $this->marker = uniqid();

        $this->shop = Shop::create([
            'title' => 'МерчантПоиск' . $this->marker,
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $this->product = Product::create([
            'title' => 'СтержниПоиск' . $this->marker,
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => Status::CODE_ACTIVE,
        ]);

        $this->review = ProductReview::create([
            'shop_id' => $this->shop->id,
            'product_id' => $this->product->id,
            'rating' => 5,
            'description' => 'ОписаниеПоиск' . $this->marker,
            'author_name' => 'АвторПоиск' . $this->marker,
            'is_published' => true,
        ]);
    }

    protected function searchIds(string $search): array
    {
        $response = $this->actingAs($this->adminUser)
            ->graphQL('
                query ($search: String, $first: Int) {
                    productReviews(search: $search, first: $first) {
                        data { id }
                    }
                }
            ', ['search' => $search, 'first' => 100]);

        $response->assertGraphQLErrorFree();

        return collect($response->json('data.productReviews.data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @test */
    public function search_finds_review_by_product_title()
    {
        $this->assertContains($this->review->id, $this->searchIds('СтержниПоиск' . $this->marker));
    }

    /** @test */
    public function search_finds_review_by_shop_title()
    {
        $this->assertContains($this->review->id, $this->searchIds('МерчантПоиск' . $this->marker));
    }

    /** @test */
    public function search_still_finds_review_by_description_and_author()
    {
        $this->assertContains($this->review->id, $this->searchIds('ОписаниеПоиск' . $this->marker));
        $this->assertContains($this->review->id, $this->searchIds('АвторПоиск' . $this->marker));
    }

    /** @test */
    public function search_returns_nothing_for_unmatched_query()
    {
        $this->assertNotContains($this->review->id, $this->searchIds('НетТакогоТовара' . $this->marker));
    }

    /** @test */
    public function merchant_reviews_search_finds_by_shop_title()
    {
        $merchantReview = MerchantReview::create([
            'shop_id' => $this->shop->id,
            'rating' => 4,
            'is_published' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->graphQL('
                query ($search: String, $first: Int) {
                    merchantReviews(search: $search, first: $first) {
                        data { id }
                    }
                }
            ', ['search' => 'МерчантПоиск' . $this->marker, 'first' => 100]);

        $response->assertGraphQLErrorFree();

        $ids = collect($response->json('data.merchantReviews.data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($merchantReview->id, $ids);
    }
}
