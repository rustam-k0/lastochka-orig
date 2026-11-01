<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ShopZoneDeleteTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;

    protected Shop $shop;

    protected function setUp(): void
    {
        // static-флаг пакета переживает пересоздание app между тестами —
        // без сброса до создания приложения observers регистрируются только
        // в первом тесте процесса и события не пишутся в outbox.
        $prop = new \ReflectionProperty(
            \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class,
            'observersRegistered'
        );
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();

        $this->shop = Shop::firstOrFail();
    }

    private const DELETE_MUTATION = '
        mutation ($id: Int) {
            ShopZoneDelete(id: $id) {
                code
                message
            }
        }
    ';

    private const UPDATE_MUTATION = '
        mutation ($id: Int, $shop_id: Int, $delivery_price: Int, $min_order_price: Int) {
            ShopZoneCreteOrUpdate(
                id: $id,
                shop_id: $shop_id,
                delivery_price: $delivery_price,
                min_order_price: $min_order_price
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
            'free_delivery_from' => 750,
            'delivery_type' => 'own',
        ]);
    }

    /** @test */
    public function admin_can_delete_shop_zone()
    {
        $zone = $this->createZone();

        $response = $this->actingAs($this->adminUser)
            ->graphQL(self::DELETE_MUTATION, ['id' => $zone->id]);

        $response->assertJsonMissing(['errors']);
        $response->assertJson([
            'data' => [
                'ShopZoneDelete' => [
                    'code' => 200,
                ],
            ],
        ]);

        $this->assertSoftDeleted('shop_zones', ['id' => $zone->id]);
    }

    /** @test */
    public function deleting_zone_publishes_shop_updated_event()
    {
        $zone = $this->createZone();

        $outboxBefore = \DB::table('event_outbox')
            ->where('entity_type', 'shops')
            ->where('entity_id', $this->shop->id)
            ->where('event_type', 'updated')
            ->count();

        $this->actingAs($this->adminUser)
            ->graphQL(self::DELETE_MUTATION, ['id' => $zone->id])
            ->assertJsonMissing(['errors']);

        // Регрессия LASTOCHKI-JAMI-11131: storefront хранит условия доставки
        // (delivery_price/min_order_price/free_delivery_from) из payload магазина
        // и сбрасывает кеш доступных магазинов только по shop.updated.
        $outboxAfter = \DB::table('event_outbox')
            ->where('entity_type', 'shops')
            ->where('entity_id', $this->shop->id)
            ->where('event_type', 'updated')
            ->count();

        $this->assertGreaterThan($outboxBefore, $outboxAfter);
    }

    /** @test */
    public function updating_zone_publishes_shop_updated_event()
    {
        $zone = $this->createZone();

        $outboxBefore = \DB::table('event_outbox')
            ->where('entity_type', 'shops')
            ->where('entity_id', $this->shop->id)
            ->where('event_type', 'updated')
            ->count();

        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 300,
                'min_order_price' => 900,
            ])
            ->assertJsonMissing(['errors']);

        $this->assertEquals(300, $zone->fresh()->delivery_price);

        $outboxAfter = \DB::table('event_outbox')
            ->where('entity_type', 'shops')
            ->where('entity_id', $this->shop->id)
            ->where('event_type', 'updated')
            ->count();

        $this->assertGreaterThan($outboxBefore, $outboxAfter);
    }

    /** @test */
    public function deleting_unknown_zone_fails_validation()
    {
        $response = $this->actingAs($this->adminUser)
            ->graphQL(self::DELETE_MUTATION, ['id' => 99999999]);

        $response->assertGraphQLValidationKeys(['id']);
    }
}
