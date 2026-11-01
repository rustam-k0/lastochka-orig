<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ProductFillStatsTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();
        $this->shop = Shop::create([
            'title' => 'Stats Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    protected function createProduct(int $fillPercent): Product
    {
        $product = Product::create([
            'title' => 'Stats Product ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'publish_after_unlock' => false,
        ]);
        DB::table('products')->where('id', $product->id)->update(['fill_percent' => $fillPercent]);

        return $product;
    }

    /** @test */
    public function paginate_filters_by_fill_percent_lt(): void
    {
        $low = $this->createProduct(20);
        $this->createProduct(80);

        $response = $this->actingAs($this->user)->graphQL('
            query ($shop_id: Int, $fill_percent_lt: Int) {
                admin_products_paginate(shop_id: $shop_id, fill_percent_lt: $fill_percent_lt) {
                    data { id fill_percent }
                }
            }
        ', ['shop_id' => $this->shop->id, 'fill_percent_lt' => 50]);

        $ids = collect($response->json('data.admin_products_paginate.data'))->pluck('id');
        $this->assertCount(1, $ids);
        $this->assertEquals($low->id, $ids->first());
    }

    /** @test */
    public function fill_stats_returns_average_and_total(): void
    {
        $this->createProduct(20);
        $this->createProduct(80);

        $response = $this->actingAs($this->user)->graphQL('
            query ($shop_id: Int) {
                admin_products_fill_stats(shop_id: $shop_id) {
                    avg_percent
                    total
                }
            }
        ', ['shop_id' => $this->shop->id]);

        $response->assertJson([
            'data' => [
                'admin_products_fill_stats' => [
                    'avg_percent' => 50.0,
                    'total' => 2,
                ],
            ],
        ]);
    }

    /** @test */
    public function paginate_filters_by_fill_percent_range(): void
    {
        $p0 = $this->createProduct(0);
        $p24 = $this->createProduct(24);
        $p25 = $this->createProduct(25);
        $p49 = $this->createProduct(49);
        $p50 = $this->createProduct(50);
        $p74 = $this->createProduct(74);
        $p75 = $this->createProduct(75);
        $p99 = $this->createProduct(99);
        $p100 = $this->createProduct(100);

        $query = '
            query ($shop_id: Int, $fill_percent_gte: Int, $fill_percent_lt: Int) {
                admin_products_paginate(shop_id: $shop_id, fill_percent_gte: $fill_percent_gte, fill_percent_lt: $fill_percent_lt) {
                    data { id }
                }
            }
        ';

        $range = function (?int $gte, ?int $lt) use ($query) {
            $response = $this->actingAs($this->user)->graphQL($query, [
                'shop_id' => $this->shop->id,
                'fill_percent_gte' => $gte,
                'fill_percent_lt' => $lt,
            ]);

            return collect($response->json('data.admin_products_paginate.data'))
                ->pluck('id')
                ->sort()
                ->values()
                ->all();
        };

        $sortIds = fn (array $ids) => collect($ids)->sort()->values()->all();

        $this->assertEquals($sortIds([$p0->id, $p24->id]), $range(0, 25));
        $this->assertEquals($sortIds([$p25->id, $p49->id]), $range(25, 50));
        $this->assertEquals($sortIds([$p50->id, $p74->id]), $range(50, 75));
        $this->assertEquals($sortIds([$p75->id, $p99->id, $p100->id]), $range(75, null));
    }
}
