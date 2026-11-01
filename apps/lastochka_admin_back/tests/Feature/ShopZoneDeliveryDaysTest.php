<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ShopZoneDeliveryDaysTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;
    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();

        $this->shop = Shop::firstOrFail();
    }

    private const UPDATE_MUTATION = '
        mutation ($id: Int, $shop_id: Int, $delivery_price: Int, $min_order_price: Int, $delivery_days: [Int!]) {
            ShopZoneCreteOrUpdate(
                id: $id,
                shop_id: $shop_id,
                delivery_price: $delivery_price,
                min_order_price: $min_order_price,
                delivery_days: $delivery_days
            ) {
                code
                message
            }
        }
    ';

    private function createZone(): ShopZone
    {
        return ShopZone::create([
            'shop_id' => $this->shop->id,
            'name' => 'Зона ' . uniqid(),
            'delivery_price' => 200,
            'min_order_price' => 600,
            'delivery_type' => 'own',
        ]);
    }

    /** @test */
    public function it_saves_normalized_delivery_days()
    {
        $zone = $this->createZone();

        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 200,
                'min_order_price' => 600,
                'delivery_days' => [5, 1, 1, 3], // дубли и не по порядку
            ])
            ->assertJsonMissing(['errors']);

        // Сохранено как unique + sorted
        $this->assertEquals([1, 3, 5], $zone->fresh()->delivery_days);
    }

    /** @test */
    public function empty_delivery_days_is_stored_as_null()
    {
        $zone = $this->createZone();
        $zone->delivery_days = [1, 2];
        $zone->save();

        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 200,
                'min_order_price' => 600,
                'delivery_days' => [],
            ])
            ->assertJsonMissing(['errors']);

        $this->assertNull($zone->fresh()->delivery_days);
    }

    /** @test */
    public function it_rejects_day_out_of_range()
    {
        $zone = $this->createZone();

        $response = $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 200,
                'min_order_price' => 600,
                'delivery_days' => [0, 8],
            ]);

        $response->assertGraphQLValidationKeys(['delivery_days.0', 'delivery_days.1']);
    }

    /** @test */
    public function shop_zone_query_returns_delivery_days()
    {
        $zone = $this->createZone();
        $zone->delivery_days = [2, 4];
        $zone->save();

        $response = $this->actingAs($this->adminUser)->graphQL('
            query ($id: Int, $shop_id: Int) {
                shop_zone(id: $id, shop_id: $shop_id) {
                    delivery_days
                }
            }
        ', ['id' => $zone->id, 'shop_id' => $this->shop->id]);

        $response->assertJson([
            'data' => ['shop_zone' => ['delivery_days' => [2, 4]]],
        ]);
    }
}
