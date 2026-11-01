<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderReview;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class OrderReviewSortingTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;
    protected OrderReview $reviewA;
    protected OrderReview $reviewB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();

        $shop = Shop::create([
            'title' => 'OrderReview Sort Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $client = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'OrderReview Sort',
            'is_authorized' => true,
        ]);

        $now = now();

        foreach (['A', 'B'] as $suffix) {
            $order = Order::create([
                'uuid' => (string) Str::uuid(),
                'client_id' => $client->id,
                'shop_id' => $shop->id,
                'status_id' => 5,
                'type_id' => 2,
                'amount' => 100,
                'payment_type_id' => 1,
            ]);

            // одинаковые rating и created_at — сортировка обязана давать
            // стабильный порядок за счёт tie-break
            $review = OrderReview::create([
                'order_id' => $order->id,
                'rating' => 3,
                'author_name' => 'Стабильность ' . $suffix,
            ]);
            $review->timestamps = false;
            $review->forceFill(['created_at' => $now, 'updated_at' => $now])->save();

            $this->{'review' . $suffix} = $review;
        }
    }

    protected function fetchIds(array $ordering): array
    {
        $response = $this->actingAs($this->adminUser)
            ->graphQL('
                query ($ordering: [DefaultOrdering], $first: Int) {
                    orderReviews(ordering: $ordering, first: $first) {
                        data { id }
                    }
                }
            ', ['ordering' => $ordering, 'first' => 100]);

        $response->assertGraphQLErrorFree();

        return collect($response->json('data.orderReviews.data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @test */
    public function equal_ratings_have_stable_order_between_requests()
    {
        $first = $this->fetchIds([['orderColumn' => 'rating', 'orderBy' => 'ASC']]);
        $second = $this->fetchIds([['orderColumn' => 'rating', 'orderBy' => 'ASC']]);

        $this->assertSame($first, $second, 'Порядок при равных рейтингах должен быть стабильным между запросами');
        $this->assertContains($this->reviewA->id, $first);
        $this->assertContains($this->reviewB->id, $first);
    }

    /** @test */
    public function unknown_column_falls_back_to_default_order_without_error()
    {
        $first = $this->fetchIds([['orderColumn' => 'bogus_column', 'orderBy' => 'ASC']]);
        $second = $this->fetchIds([['orderColumn' => 'bogus_column', 'orderBy' => 'ASC']]);

        $this->assertSame($first, $second, 'При неизвестной колонке порядок должен оставаться стабильным (дефолт)');
        $this->assertContains($this->reviewA->id, $first);
    }

}
