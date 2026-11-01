<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ShopZoneActiveAssemblyPriceTest extends TestCase
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

    private const UPDATE_MUTATION = '
        mutation ($id: Int, $shop_id: Int, $delivery_price: Int, $min_order_price: Int, $assembly_price: Int, $is_active: Boolean) {
            ShopZoneCreteOrUpdate(
                id: $id,
                shop_id: $shop_id,
                delivery_price: $delivery_price,
                min_order_price: $min_order_price,
                assembly_price: $assembly_price,
                is_active: $is_active
            ) {
                code
                message
            }
        }
    ';

    private const SET_ACTIVE_MUTATION = '
        mutation ($id: Int!, $is_active: Boolean!) {
            ShopZoneSetActive(id: $id, is_active: $is_active) {
                code
                message
            }
        }
    ';

    private function createZone(array $attributes = []): ShopZone
    {
        return ShopZone::create(array_merge([
            'shop_id' => $this->shop->id,
            'name' => 'Зона ' . uniqid(),
            'delivery_price' => 200,
            'min_order_price' => 600,
            'delivery_type' => 'own',
        ], $attributes));
    }

    /** @test */
    public function it_updates_assembly_price_and_is_active_via_mutation()
    {
        $zone = $this->createZone();

        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 200,
                'min_order_price' => 600,
                'assembly_price' => 150,
                'is_active' => false,
            ])
            ->assertJsonMissing(['errors']);

        $fresh = $zone->fresh();
        $this->assertSame(150, (int) $fresh->assembly_price);
        $this->assertFalse((bool) $fresh->is_active);
    }

    /** @test */
    public function it_does_not_overwrite_assembly_price_when_key_absent()
    {
        $zone = $this->createZone(['assembly_price' => 300]);

        // Мутация БЕЗ ключа assembly_price — значение должно сохраниться.
        $this->actingAs($this->adminUser)
            ->graphQL('
                mutation ($id: Int, $shop_id: Int, $delivery_price: Int, $min_order_price: Int) {
                    ShopZoneCreteOrUpdate(
                        id: $id,
                        shop_id: $shop_id,
                        delivery_price: $delivery_price,
                        min_order_price: $min_order_price
                    ) { code message }
                }
            ', [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 250,
                'min_order_price' => 700,
            ])
            ->assertJsonMissing(['errors']);

        $fresh = $zone->fresh();
        $this->assertSame(300, (int) $fresh->assembly_price);
        $this->assertSame(250, (int) $fresh->delivery_price);
    }

    /** @test */
    public function it_does_not_overwrite_is_active_when_key_absent()
    {
        $zone = $this->createZone(['is_active' => false]);

        $this->actingAs($this->adminUser)
            ->graphQL('
                mutation ($id: Int, $shop_id: Int, $delivery_price: Int, $min_order_price: Int) {
                    ShopZoneCreteOrUpdate(
                        id: $id,
                        shop_id: $shop_id,
                        delivery_price: $delivery_price,
                        min_order_price: $min_order_price
                    ) { code message }
                }
            ', [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 250,
                'min_order_price' => 700,
            ])
            ->assertJsonMissing(['errors']);

        $this->assertFalse((bool) $zone->fresh()->is_active);
    }

    /** @test */
    public function explicit_is_active_null_does_not_change_is_active()
    {
        $zone = $this->createZone(['is_active' => true]);

        // GraphQL-клиент шлёт is_active: null (все переменные с null) —
        // это НЕ должно деактивировать зону.
        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 250,
                'min_order_price' => 700,
                'is_active' => null,
            ])
            ->assertJsonMissing(['errors']);

        $this->assertTrue((bool) $zone->fresh()->is_active);
    }

    /** @test */
    public function assembly_price_can_be_cleared_to_null()
    {
        $zone = $this->createZone(['assembly_price' => 300]);

        $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 200,
                'min_order_price' => 600,
                'assembly_price' => null,
            ])
            ->assertJsonMissing(['errors']);

        $this->assertNull($zone->fresh()->assembly_price);
    }

    /** @test */
    public function it_rejects_negative_assembly_price()
    {
        $zone = $this->createZone();

        $response = $this->actingAs($this->adminUser)
            ->graphQL(self::UPDATE_MUTATION, [
                'id' => $zone->id,
                'shop_id' => $this->shop->id,
                'delivery_price' => 200,
                'min_order_price' => 600,
                'assembly_price' => -5,
            ]);

        $response->assertGraphQLValidationKeys(['assembly_price']);
    }

    /** @test */
    public function set_active_toggles_zone()
    {
        $zone = $this->createZone(['is_active' => true]);

        $this->actingAs($this->adminUser)
            ->graphQL(self::SET_ACTIVE_MUTATION, ['id' => $zone->id, 'is_active' => false])
            ->assertJsonMissing(['errors'])
            ->assertJson(['data' => ['ShopZoneSetActive' => ['code' => 200]]]);

        $this->assertFalse((bool) $zone->fresh()->is_active);

        $this->actingAs($this->adminUser)
            ->graphQL(self::SET_ACTIVE_MUTATION, ['id' => $zone->id, 'is_active' => true])
            ->assertJsonMissing(['errors']);

        $this->assertTrue((bool) $zone->fresh()->is_active);
    }

    /** @test */
    public function set_active_publishes_shop_updated_event()
    {
        $zone = $this->createZone(['is_active' => true]);

        $outboxBefore = \DB::table('event_outbox')
            ->where('entity_type', 'shops')
            ->where('entity_id', $this->shop->id)
            ->where('event_type', 'updated')
            ->count();

        $this->actingAs($this->adminUser)
            ->graphQL(self::SET_ACTIVE_MUTATION, ['id' => $zone->id, 'is_active' => false])
            ->assertJsonMissing(['errors']);

        $outboxAfter = \DB::table('event_outbox')
            ->where('entity_type', 'shops')
            ->where('entity_id', $this->shop->id)
            ->where('event_type', 'updated')
            ->count();

        $this->assertGreaterThan($outboxBefore, $outboxAfter);
    }

    /** @test */
    public function shop_zone_query_returns_new_fields()
    {
        $zone = $this->createZone(['assembly_price' => 175, 'is_active' => false]);

        $response = $this->actingAs($this->adminUser)->graphQL('
            query ($id: Int, $shop_id: Int) {
                shop_zone(id: $id, shop_id: $shop_id) {
                    assembly_price
                    is_active
                }
            }
        ', ['id' => $zone->id, 'shop_id' => $this->shop->id]);

        $response->assertJson([
            'data' => ['shop_zone' => ['assembly_price' => 175, 'is_active' => false]],
        ]);
    }
}
