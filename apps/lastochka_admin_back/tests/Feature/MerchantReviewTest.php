<?php

namespace Tests\Feature;

use App\Models\MerchantReview;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class MerchantReviewTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;
    protected User $shopUser;
    protected Shop $shop;
    protected Shop $anotherShop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();

        $this->shop = Shop::create([
            'title' => 'Test Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $this->anotherShop = Shop::create([
            'title' => 'Another Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $this->shopUser = User::create([
            'name' => 'Shop User ' . uniqid(),
            'email' => uniqid() . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $this->shopUser->assignRole(Role::SHOP);

        UserShop::create([
            'user_id' => $this->shopUser->id,
            'shop_id' => $this->shop->id,
        ]);
    }

    protected function createReview(array $attributes = []): MerchantReview
    {
        return MerchantReview::create(array_merge([
            'shop_id' => $this->shop->id,
            'rating' => 5,
            'description' => 'Отличный магазин',
            'is_published' => false,
            'author_name' => 'Иван Иванов',
            'author_phone' => '+79001234567',
        ], $attributes));
    }

    /** @test */
    public function admin_can_create_merchant_review()
    {
        $mutation = '
            mutation CreateMerchantReview($input: CreateMerchantReviewInput!) {
                createMerchantReview(input: $input) {
                    id
                    shop_id
                    rating
                    description
                    is_published
                    author_name
                    author_phone
                    shop {
                        id
                    }
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'rating' => 5,
                    'description' => 'Отличный магазин!',
                    'is_published' => true,
                    'author_name' => 'Иван Иванов',
                    'author_phone' => '+7 (900) 123-45-67',
                ],
            ]);

        $response->assertJson([
            'data' => [
                'createMerchantReview' => [
                    'shop_id' => $this->shop->id,
                    'rating' => 5,
                    'description' => 'Отличный магазин!',
                    'is_published' => true,
                    'author_name' => 'Иван Иванов',
                    'author_phone' => '+79001234567',
                    'shop' => [
                        'id' => $this->shop->id,
                    ],
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_reviews', [
            'shop_id' => $this->shop->id,
            'rating' => 5,
            'author_phone' => '+79001234567',
        ]);
    }

    /** @test */
    public function admin_can_update_merchant_review()
    {
        $review = $this->createReview();

        $mutation = '
            mutation UpdateMerchantReview($id: Int!, $input: UpdateMerchantReviewInput!) {
                updateMerchantReview(id: $id, input: $input) {
                    id
                    rating
                    description
                    is_published
                    author_name
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($mutation, [
                'id' => $review->id,
                'input' => [
                    'rating' => 4,
                    'description' => 'Хороший магазин',
                    'is_published' => true,
                    'author_name' => 'Пётр Петров',
                ],
            ]);

        $response->assertJson([
            'data' => [
                'updateMerchantReview' => [
                    'id' => $review->id,
                    'rating' => 4,
                    'description' => 'Хороший магазин',
                    'is_published' => true,
                    'author_name' => 'Пётр Петров',
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_reviews', [
            'id' => $review->id,
            'rating' => 4,
            'description' => 'Хороший магазин',
        ]);
    }

    /** @test */
    public function admin_can_publish_merchant_review()
    {
        $review = $this->createReview(['is_published' => false]);

        $mutation = '
            mutation PublishMerchantReview($id: Int!, $is_published: Boolean!) {
                publishMerchantReview(id: $id, is_published: $is_published) {
                    id
                    is_published
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($mutation, [
                'id' => $review->id,
                'is_published' => true,
            ]);

        $response->assertJson([
            'data' => [
                'publishMerchantReview' => [
                    'id' => $review->id,
                    'is_published' => true,
                ],
            ],
        ]);

        $this->assertDatabaseHas('merchant_reviews', [
            'id' => $review->id,
            'is_published' => true,
        ]);
    }

    /** @test */
    public function admin_can_unpublish_merchant_review()
    {
        $review = $this->createReview(['is_published' => true]);

        $mutation = '
            mutation PublishMerchantReview($id: Int!, $is_published: Boolean!) {
                publishMerchantReview(id: $id, is_published: $is_published) {
                    id
                    is_published
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($mutation, [
                'id' => $review->id,
                'is_published' => false,
            ]);

        $response->assertJson([
            'data' => [
                'publishMerchantReview' => [
                    'id' => $review->id,
                    'is_published' => false,
                ],
            ],
        ]);
    }

    /** @test */
    public function admin_can_see_all_reviews()
    {
        $this->createReview(['shop_id' => $this->shop->id]);
        $this->createReview(['shop_id' => $this->anotherShop->id]);

        $query = '
            query MerchantReviews {
                merchantReviews {
                    data {
                        id
                        shop_id
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($query);

        $this->assertGreaterThanOrEqual(2, $response->json('data.merchantReviews.paginatorInfo.total'));
    }

    /** @test */
    public function seller_can_only_see_own_shop_reviews()
    {
        $this->createReview(['shop_id' => $this->shop->id]);
        $this->createReview(['shop_id' => $this->anotherShop->id]);

        $query = '
            query MerchantReviews {
                merchantReviews {
                    data {
                        id
                        shop_id
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ';

        $response = $this->actingAs($this->shopUser)
            ->graphQL($query);

        $data = $response->json('data.merchantReviews.data');
        foreach ($data as $review) {
            $this->assertEquals($this->shop->id, $review['shop_id']);
        }
    }

    /** @test */
    public function seller_cannot_create_review()
    {
        $mutation = '
            mutation CreateMerchantReview($input: CreateMerchantReviewInput!) {
                createMerchantReview(input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->shopUser)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'rating' => 5,
                ],
            ]);

        $response->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    /** @test */
    public function seller_cannot_update_review()
    {
        $review = $this->createReview();

        $mutation = '
            mutation UpdateMerchantReview($id: Int!, $input: UpdateMerchantReviewInput!) {
                updateMerchantReview(id: $id, input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->shopUser)
            ->graphQL($mutation, [
                'id' => $review->id,
                'input' => [
                    'rating' => 3,
                ],
            ]);

        $response->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    /** @test */
    public function seller_cannot_publish_review()
    {
        $review = $this->createReview();

        $mutation = '
            mutation PublishMerchantReview($id: Int!, $is_published: Boolean!) {
                publishMerchantReview(id: $id, is_published: $is_published) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->shopUser)
            ->graphQL($mutation, [
                'id' => $review->id,
                'is_published' => true,
            ]);

        $response->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    /** @test */
    public function it_validates_rating_range()
    {
        $mutation = '
            mutation CreateMerchantReview($input: CreateMerchantReviewInput!) {
                createMerchantReview(input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'rating' => 6,
                ],
            ]);

        $response->assertGraphQLValidationError('input.rating', 'Оценка не может быть больше 5');

        $response = $this->actingAs($this->adminUser)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'rating' => 0,
                ],
            ]);

        $response->assertGraphQLValidationError('input.rating', 'Оценка не может быть меньше 1');
    }

    /** @test */
    public function it_validates_shop_exists()
    {
        $mutation = '
            mutation CreateMerchantReview($input: CreateMerchantReviewInput!) {
                createMerchantReview(input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => 999999,
                    'rating' => 5,
                ],
            ]);

        $response->assertGraphQLValidationError('input.shop_id', 'Указанный магазин не найден');
    }

    /** @test */
    public function it_validates_description_max_length()
    {
        $mutation = '
            mutation CreateMerchantReview($input: CreateMerchantReviewInput!) {
                createMerchantReview(input: $input) {
                    id
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'rating' => 5,
                    'description' => str_repeat('a', 2001),
                ],
            ]);

        $response->assertGraphQLValidationError('input.description', 'Текст отзыва не должен превышать 2000 символов');
    }

    /** @test */
    public function it_can_filter_by_shop_id()
    {
        $this->createReview(['shop_id' => $this->shop->id]);
        $this->createReview(['shop_id' => $this->shop->id]);
        $this->createReview(['shop_id' => $this->anotherShop->id]);

        $query = '
            query MerchantReviews($shop_id: Int) {
                merchantReviews(shop_id: $shop_id) {
                    data {
                        id
                        shop_id
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
            ]);

        $response->assertJsonPath('data.merchantReviews.paginatorInfo.total', 2);
    }

    /** @test */
    public function it_can_filter_by_is_published()
    {
        $this->createReview(['is_published' => true]);
        $this->createReview(['is_published' => true]);
        $this->createReview(['is_published' => false]);

        $query = '
            query MerchantReviews($shop_id: Int, $is_published: Boolean) {
                merchantReviews(shop_id: $shop_id, is_published: $is_published) {
                    data {
                        id
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
                'is_published' => true,
            ]);

        $response->assertJsonPath('data.merchantReviews.paginatorInfo.total', 2);
    }

    /** @test */
    public function it_can_filter_by_rating()
    {
        $this->createReview(['rating' => 5]);
        $this->createReview(['rating' => 4]);
        $this->createReview(['rating' => 3]);

        $query = '
            query MerchantReviews($shop_id: Int, $rating: Int) {
                merchantReviews(shop_id: $shop_id, rating: $rating) {
                    data {
                        id
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
                'rating' => 5,
            ]);

        $response->assertJsonPath('data.merchantReviews.paginatorInfo.total', 1);
    }

    /** @test */
    public function it_can_filter_by_rating_range()
    {
        $this->createReview(['rating' => 5]);
        $this->createReview(['rating' => 4]);
        $this->createReview(['rating' => 3]);
        $this->createReview(['rating' => 2]);
        $this->createReview(['rating' => 1]);

        $query = '
            query MerchantReviews($shop_id: Int, $rating_min: Int, $rating_max: Int) {
                merchantReviews(shop_id: $shop_id, rating_min: $rating_min, rating_max: $rating_max) {
                    data {
                        id
                        rating
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
                'rating_min' => 3,
                'rating_max' => 4,
            ]);

        $response->assertJsonPath('data.merchantReviews.paginatorInfo.total', 2);
    }

    /** @test */
    public function it_can_search_by_description_and_author_name()
    {
        $this->createReview(['description' => 'Отличный магазин', 'author_name' => 'Иван']);
        $this->createReview(['description' => 'Плохой сервис', 'author_name' => 'Пётр']);
        $this->createReview(['description' => 'Нормально', 'author_name' => 'Сергей']);

        $query = '
            query MerchantReviews($shop_id: Int, $search: String) {
                merchantReviews(shop_id: $shop_id, search: $search) {
                    data {
                        id
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
                'search' => 'Отличный',
            ]);

        $response->assertJsonPath('data.merchantReviews.paginatorInfo.total', 1);

        $response = $this->actingAs($this->adminUser)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
                'search' => 'Пётр',
            ]);

        $response->assertJsonPath('data.merchantReviews.paginatorInfo.total', 1);
    }

    /** @test */
    public function it_sorts_by_created_at_desc_by_default()
    {
        $review1 = $this->createReview(['description' => 'First']);
        sleep(1);
        $review2 = $this->createReview(['description' => 'Second']);
        sleep(1);
        $review3 = $this->createReview(['description' => 'Third']);

        $query = '
            query MerchantReviews($shop_id: Int) {
                merchantReviews(shop_id: $shop_id) {
                    data {
                        id
                        description
                    }
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($query, [
                'shop_id' => $this->shop->id,
            ]);

        $data = $response->json('data.merchantReviews.data');
        $this->assertEquals('Third', $data[0]['description']);
        $this->assertEquals('Second', $data[1]['description']);
        $this->assertEquals('First', $data[2]['description']);
    }

    /** @test */
    public function it_can_get_single_review()
    {
        $review = $this->createReview(['description' => 'Тестовый отзыв']);

        $query = '
            query MerchantReview($id: Int) {
                merchantReview(id: $id) {
                    id
                    description
                    shop {
                        id
                        title
                    }
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($query, [
                'id' => $review->id,
            ]);

        $response->assertJson([
            'data' => [
                'merchantReview' => [
                    'id' => $review->id,
                    'description' => 'Тестовый отзыв',
                    'shop' => [
                        'id' => $this->shop->id,
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function soft_delete_works_via_entity_delete()
    {
        $review = $this->createReview();

        $mutation = '
            mutation EntityDelete($id: Int!, $table: String!) {
                EntityDelete(id: $id, table: $table) {
                    code
                    message
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($mutation, [
                'id' => $review->id,
                'table' => 'merchant_reviews',
            ]);

        $this->assertSoftDeleted('merchant_reviews', [
            'id' => $review->id,
        ]);
    }

    /** @test */
    public function phone_is_formatted_correctly()
    {
        $mutation = '
            mutation CreateMerchantReview($input: CreateMerchantReviewInput!) {
                createMerchantReview(input: $input) {
                    id
                    author_phone
                }
            }
        ';

        $response = $this->actingAs($this->adminUser)
            ->graphQL($mutation, [
                'input' => [
                    'shop_id' => $this->shop->id,
                    'rating' => 5,
                    'author_phone' => '+7 (900) 123-45-67',
                ],
            ]);

        $response->assertJson([
            'data' => [
                'createMerchantReview' => [
                    'author_phone' => '+79001234567',
                ],
            ],
        ]);
    }
}
