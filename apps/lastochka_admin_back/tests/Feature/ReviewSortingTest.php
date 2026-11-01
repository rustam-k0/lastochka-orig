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

class ReviewSortingTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;
    protected Shop $shopA;
    protected Shop $shopB;
    protected Product $productA;
    protected Product $productB;
    protected ProductReview $reviewA;
    protected ProductReview $reviewB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();

        $marker = uniqid();
        $this->shopA = $this->createShop('АААА Сортировка ' . $marker);
        $this->shopB = $this->createShop('ЯЯЯЯ Сортировка ' . $marker);

        $this->productA = $this->createProduct($this->shopA, 'АААА Товар ' . $marker);
        $this->productB = $this->createProduct($this->shopB, 'ЯЯЯЯ Товар ' . $marker);

        // A: низкий рейтинг, магазин/товар первые по алфавиту
        $this->reviewA = ProductReview::create([
            'shop_id' => $this->shopA->id,
            'product_id' => $this->productA->id,
            'rating' => 1,
            'is_published' => false,
        ]);

        // B: высокий рейтинг, магазин/товар последние по алфавиту
        $this->reviewB = ProductReview::create([
            'shop_id' => $this->shopB->id,
            'product_id' => $this->productB->id,
            'rating' => 5,
            'is_published' => false,
        ]);
    }

    protected function createShop(string $title): Shop
    {
        return Shop::create([
            'title' => $title,
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    protected function createProduct(Shop $shop, string $title): Product
    {
        return Product::create([
            'title' => $title,
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => Status::CODE_ACTIVE,
        ]);
    }

    protected function reviewsQuery(): string
    {
        return '
            query ($ordering: [DefaultOrdering], $first: Int) {
                productReviews(ordering: $ordering, first: $first) {
                    data {
                        id
                        rating
                    }
                }
            }
        ';
    }

    protected function fetchIds(array $ordering): array
    {
        $response = $this->actingAs($this->adminUser)
            ->graphQL($this->reviewsQuery(), [
                'ordering' => $ordering,
                'first' => 100,
            ]);

        $response->assertGraphQLErrorFree();

        return collect($response->json('data.productReviews.data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @test */
    public function sorting_by_shop_orders_by_shop_title_and_does_not_crash()
    {
        $ids = $this->fetchIds([['orderColumn' => 'shop', 'orderBy' => 'ASC']]);

        $posA = array_search($this->reviewA->id, $ids);
        $posB = array_search($this->reviewB->id, $ids);

        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posB, $posA, 'ASC по мерчанту: АААА должен быть раньше ЯЯЯЯ');

        $idsDesc = $this->fetchIds([['orderColumn' => 'shop', 'orderBy' => 'DESC']]);
        $this->assertLessThan(
            array_search($this->reviewA->id, $idsDesc),
            array_search($this->reviewB->id, $idsDesc),
            'DESC по мерчанту: ЯЯЯЯ должен быть раньше АААА'
        );
    }

    /** @test */
    public function sorting_by_product_orders_by_product_title_and_does_not_crash()
    {
        $ids = $this->fetchIds([['orderColumn' => 'product', 'orderBy' => 'ASC']]);

        $posA = array_search($this->reviewA->id, $ids);
        $posB = array_search($this->reviewB->id, $ids);

        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posB, $posA, 'ASC по товару: АААА должен быть раньше ЯЯЯЯ');
    }

    /** @test */
    public function sorting_by_relation_keeps_review_rating_intact()
    {
        $response = $this->actingAs($this->adminUser)
            ->graphQL($this->reviewsQuery(), [
                'ordering' => [['orderColumn' => 'shop', 'orderBy' => 'ASC']],
                'first' => 100,
            ]);

        $response->assertGraphQLErrorFree();

        $byId = collect($response->json('data.productReviews.data'))->keyBy('id');

        // JOIN не должен затирать колонки отзыва (select product_reviews.*)
        $this->assertSame(1, (int) $byId[$this->reviewA->id]['rating']);
        $this->assertSame(5, (int) $byId[$this->reviewB->id]['rating']);
    }

    /** @test */
    public function sorting_by_unknown_column_falls_back_to_default_without_error()
    {
        $ids = $this->fetchIds([['orderColumn' => 'bogus_column', 'orderBy' => 'ASC']]);

        $this->assertContains($this->reviewA->id, $ids);
        $this->assertContains($this->reviewB->id, $ids);
    }

    /** @test */
    public function equal_ratings_have_stable_order_between_requests()
    {
        $this->reviewB->update(['rating' => 1]); // оба отзыва rating=1

        $first = $this->fetchIds([['orderColumn' => 'rating', 'orderBy' => 'ASC']]);
        $second = $this->fetchIds([['orderColumn' => 'rating', 'orderBy' => 'ASC']]);

        $this->assertSame($first, $second, 'Порядок при равных рейтингах должен быть стабильным');
    }

    /** @test */
    public function merchant_reviews_sorting_by_shop_does_not_crash()
    {
        MerchantReview::create([
            'shop_id' => $this->shopA->id,
            'rating' => 3,
            'is_published' => false,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->graphQL('
                query ($ordering: [DefaultOrdering], $first: Int) {
                    merchantReviews(ordering: $ordering, first: $first) {
                        data { id rating }
                    }
                }
            ', [
                'ordering' => [['orderColumn' => 'shop', 'orderBy' => 'ASC']],
                'first' => 100,
            ]);

        $response->assertGraphQLErrorFree();
    }
}
