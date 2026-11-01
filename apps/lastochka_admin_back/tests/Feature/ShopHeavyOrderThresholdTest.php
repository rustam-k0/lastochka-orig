<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Type;
use App\Models\User;
use App\Transformers\ShopEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ShopHeavyOrderThresholdTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->adminUser();
    }

    // slot_time/slot_max_orders/type_id обязательны в ShopUpdateValidator для
    // глобального админа (adminRequiredRule()) — без них любой вызов мутации
    // падает на "обязательно для заполнения" раньше, чем успевает дойти до
    // проверки heavy_order_threshold. pickup_wait_* передаём равными DB-дефолтам
    // колонок (1/3/1) — ShopService::fillInfo пишет `$args[...] ?? null` без
    // fallback на текущее значение, а сами колонки NOT NULL (unsignedTinyInteger
    // ->default()), так что при их отсутствии в args update() падает на
    // integrity constraint violation ещё до heavy_order_threshold. Оба нюанса —
    // существующее поведение вне scope этой задачи, тесты просто их обходят.
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
            $heavy_order_threshold: Int
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
                heavy_order_threshold: $heavy_order_threshold
            ) {
                code
                message
            }
        }
    ';

    // ShopUpdate возвращает DefaultResponse (code/message), а не Shop — полей
    // id/heavy_order_threshold в теле мутации нет ни у одной другой мутации
    // магазина. Персист проверяем через $shop->fresh() и через существующий
    // query `shop(id:)`, который отдаёт тип Shop с полем heavy_order_threshold.
    private const SHOP_QUERY = '
        query Shop($id: Int) {
            shop(id: $id) {
                id
                heavy_order_threshold
            }
        }
    ';

    private function createShop(): Shop
    {
        return Shop::create(['title' => 'Heavy test shop ' . uniqid()]);
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

    /** @test */
    public function shop_update_persists_heavy_order_threshold()
    {
        $shop = $this->createShop();

        $response = $this->actingAs($this->user)->graphQL(self::UPDATE_MUTATION, array_merge(
            $this->baseVariables($shop),
            ['heavy_order_threshold' => 30000] // 30 кг в граммах
        ));

        $response->assertJson(['data' => ['ShopUpdate' => ['code' => 200]]]);
        $this->assertSame(30000, (int) $shop->fresh()->heavy_order_threshold);

        $shopQuery = $this->actingAs($this->user)->graphQL(self::SHOP_QUERY, ['id' => $shop->id]);
        $shopQuery->assertJson(['data' => ['shop' => ['heavy_order_threshold' => 30000]]]);
    }

    /** @test */
    public function shop_update_resets_threshold_to_null_when_omitted()
    {
        $shop = $this->createShop();
        $shop->update(['heavy_order_threshold' => 30000]);

        $this->actingAs($this->user)->graphQL(self::UPDATE_MUTATION, $this->baseVariables($shop));
        // heavy_order_threshold не передан — паттерн "?? null", как у min_pickup_order_amount

        $this->assertNull($shop->fresh()->heavy_order_threshold);
    }

    /** @test */
    public function shop_update_rejects_negative_threshold()
    {
        $shop = $this->createShop();

        $response = $this->actingAs($this->user)->graphQL(self::UPDATE_MUTATION, array_merge(
            $this->baseVariables($shop),
            ['heavy_order_threshold' => -5]
        ));

        $response->assertGraphQLErrorMessage('Validation failed for the field [ShopUpdate].');
        $response->assertJsonFragment(['heavy_order_threshold' => ['Значение поля Порог тяжёлого заказа должно быть не меньше 0.']]);
        $this->assertNull($shop->fresh()->heavy_order_threshold);
    }

    /** @test */
    public function shop_event_contains_heavy_order_threshold()
    {
        $shop = $this->createShop();
        $shop->update(['heavy_order_threshold' => 25000]);

        $event = app(ShopEventTransformer::class)->toEvent($shop->fresh());

        $this->assertSame(25000, $event['heavy_order_threshold']);
    }
}
