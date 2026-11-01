<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ShopZoneDeliveryLeadMinutesTest extends TestCase
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
        mutation ($id: Int, $shop_id: Int, $delivery_price: Int, $min_order_price: Int, $delivery_lead_minutes: Int) {
            ShopZoneCreteOrUpdate(
                id: $id,
                shop_id: $shop_id,
                delivery_price: $delivery_price,
                min_order_price: $min_order_price,
                delivery_lead_minutes: $delivery_lead_minutes
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
    public function it_saves_delivery_lead_minutes()
    {
        $zone = $this->createZone();

        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 200,
                'min_order_price' => 600,
                'delivery_lead_minutes' => 30,
            ])
            ->assertJsonMissing(['errors']);

        $this->assertSame(30, $zone->fresh()->delivery_lead_minutes);
    }

    /** @test */
    public function zero_delivery_lead_minutes_is_stored_as_null()
    {
        $zone = $this->createZone();
        $zone->delivery_lead_minutes = 90;
        $zone->save();

        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 200,
                'min_order_price' => 600,
                'delivery_lead_minutes' => 0,
            ])
            ->assertJsonMissing(['errors']);

        $this->assertNull($zone->fresh()->delivery_lead_minutes);
    }

    /** @test */
    public function missing_delivery_lead_minutes_is_stored_as_null()
    {
        $zone = $this->createZone();
        $zone->delivery_lead_minutes = 90;
        $zone->save();

        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 200,
                'min_order_price' => 600,
                // delivery_lead_minutes не передан
            ])
            ->assertJsonMissing(['errors']);

        $this->assertNull($zone->fresh()->delivery_lead_minutes);
    }

    /** @test */
    public function it_rejects_negative_delivery_lead_minutes()
    {
        $zone = $this->createZone();

        $response = $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 200,
                'min_order_price' => 600,
                'delivery_lead_minutes' => -1,
            ]);

        $response->assertGraphQLValidationKeys(['delivery_lead_minutes']);
    }

    /** @test */
    public function it_rejects_delivery_lead_minutes_above_limit()
    {
        $zone = $this->createZone();

        $response = $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 200,
                'min_order_price' => 600,
                // 7 суток = 10080 минут — верхняя граница
                'delivery_lead_minutes' => 10081,
            ]);

        $response->assertGraphQLValidationKeys(['delivery_lead_minutes']);
    }

    /** @test */
    public function shop_zone_query_returns_delivery_lead_minutes()
    {
        $zone = $this->createZone();
        $zone->delivery_lead_minutes = 45;
        $zone->save();

        $response = $this->actingAs($this->adminUser)->graphQL('
            query ($id: Int, $shop_id: Int) {
                shop_zone(id: $id, shop_id: $shop_id) {
                    delivery_lead_minutes
                }
            }
        ', ['id' => $zone->id, 'shop_id' => $this->shop->id]);

        $response->assertJson([
            'data' => ['shop_zone' => ['delivery_lead_minutes' => 45]],
        ]);
    }
}
