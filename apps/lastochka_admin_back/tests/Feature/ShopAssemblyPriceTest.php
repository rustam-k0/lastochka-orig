<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ShopAssemblyPriceTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->adminUser();
    }

    // slot_time/slot_max_orders/type_id обязательны в ShopUpdateValidator для
    // глобального админа (adminRequiredRule()); pickup_wait_* передаём равными
    // DB-дефолтам (1/3/1) — см. подробный комментарий в ShopHeavyOrderThresholdTest.
    private const UPDATE_MUTATION = '
        mutation ShopUpdate(
            $id: Int!
            $title: String!
            $slot_time: Int!
            $slot_max_orders: Int!
            $type_id: Int!
            $pickup_wait_perishable_hours: Int!
            $pickup_wait_regular_hours: Int!
            $pickup_grace_period_hours: Int!
            $assembly_price: Int
        ) {
            ShopUpdate(
                id: $id
                title: $title
                slot_time: $slot_time
                slot_max_orders: $slot_max_orders
                type_id: $type_id
                pickup_wait_perishable_hours: $pickup_wait_perishable_hours
                pickup_wait_regular_hours: $pickup_wait_regular_hours
                pickup_grace_period_hours: $pickup_grace_period_hours
                assembly_price: $assembly_price
            ) {
                code
                message
            }
        }
    ';

    // ShopUpdate возвращает DefaultResponse (code/message), не Shop. Персист
    // читаем через query shop(id:), который отдаёт тип Shop с assembly_price.
    private const SHOP_QUERY = '
        query Shop($id: Int) {
            shop(id: $id) {
                id
                assembly_price
            }
        }
    ';

    private function createShop(): Shop
    {
        return Shop::create(['title' => 'Assembly test shop ' . uniqid()]);
    }

    private function baseVariables(Shop $shop): array
    {
        return [
            'id' => $shop->id,
            'title' => $shop->title,
            'slot_time' => 60,
            'slot_max_orders' => 10,
            'type_id' => Type::SHOP_TYPE_STORE,
            'pickup_wait_perishable_hours' => 1,
            'pickup_wait_regular_hours' => 3,
            'pickup_grace_period_hours' => 1,
        ];
    }

    public function test_shops_table_has_assembly_price_column(): void
    {
        $this->assertTrue(Schema::hasColumn('shops', 'assembly_price'));
    }

    public function test_assembly_price_is_mass_assignable(): void
    {
        $shop = new Shop(['assembly_price' => 150]);
        $this->assertSame(150, (int) $shop->assembly_price);
    }

    /** @test */
    public function test_shop_update_persists_assembly_price(): void
    {
        $shop = $this->createShop();

        $response = $this->actingAs($this->user)->graphQL(self::UPDATE_MUTATION, array_merge(
            $this->baseVariables($shop),
            ['assembly_price' => 200] // 200 рублей
        ));

        $response->assertJson(['data' => ['ShopUpdate' => ['code' => 200]]]);
        $this->assertSame(200, (int) $shop->fresh()->assembly_price);

        $shopQuery = $this->actingAs($this->user)->graphQL(self::SHOP_QUERY, ['id' => $shop->id]);
        $shopQuery->assertJson(['data' => ['shop' => ['assembly_price' => 200]]]);
    }

    /** @test */
    public function test_shop_update_resets_assembly_price_to_null_when_omitted(): void
    {
        $shop = $this->createShop();
        $shop->update(['assembly_price' => 200]);

        $this->actingAs($this->user)->graphQL(self::UPDATE_MUTATION, $this->baseVariables($shop));
        // assembly_price не передан — паттерн "?? null", как у heavy_order_threshold

        $this->assertNull($shop->fresh()->assembly_price);
    }

    /** @test */
    public function test_shop_update_rejects_negative_assembly_price(): void
    {
        $shop = $this->createShop();

        $response = $this->actingAs($this->user)->graphQL(self::UPDATE_MUTATION, array_merge(
            $this->baseVariables($shop),
            ['assembly_price' => -5]
        ));

        $response->assertGraphQLErrorMessage('Validation failed for the field [ShopUpdate].');
        $response->assertJsonFragment(['assembly_price' => ['Значение поля Стоимость сборки должно быть не меньше 0.']]);
        $this->assertNull($shop->fresh()->assembly_price);
    }

    public function test_shop_event_payload_contains_assembly_price(): void
    {
        $shop = new Shop(['assembly_price' => 175]);
        $shop->setRelation('zones', collect());
        $shop->setRelation('workingHours', collect());

        $payload = (new \App\Transformers\ShopEventTransformer())->toEvent($shop);

        $this->assertArrayHasKey('assembly_price', $payload);
        $this->assertSame(175, (int) $payload['assembly_price']);
    }
}
