<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\User;
use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\Support\InteractsWithDeliveryPriceRanges;
use Tests\TestCase;

/**
 * MID-280: сохранение ступеней стоимости доставки зоны и производный порог
 * бесплатной доставки; переходный режим для админки без поля ступеней.
 *
 * Модуль delivery_price_ranges (Task 8) по умолчанию выключен — включаем его
 * явно, поведение при выключенном модуле покрыто DeliveryPriceRangesModuleTest.
 */
class ShopZoneDeliveryPriceRangesSaveTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests, InteractsWithDeliveryPriceRanges;

    private User $admin;

    private Shop $shop;

    /** Новая админка: всегда передаёт полный список ступеней */
    private const MUTATION = '
        mutation (
            $id: Int
            $shop_id: Int
            $delivery_price: Int
            $min_order_price: Int
            $free_delivery_from: Int
            $delivery_price_ranges: [ShopZoneDeliveryPriceRangeInput!]
        ) {
            ShopZoneCreteOrUpdate(
                id: $id
                shop_id: $shop_id
                delivery_price: $delivery_price
                min_order_price: $min_order_price
                free_delivery_from: $free_delivery_from
                delivery_price_ranges: $delivery_price_ranges
            ) {
                code
            }
        }
    ';

    /** Админка до выкладки MID-280: поля ступеней в документе нет */
    private const LEGACY_MUTATION = '
        mutation ($id: Int, $shop_id: Int, $delivery_price: Int, $min_order_price: Int, $free_delivery_from: Int) {
            ShopZoneCreteOrUpdate(
                id: $id
                shop_id: $shop_id
                delivery_price: $delivery_price
                min_order_price: $min_order_price
                free_delivery_from: $free_delivery_from
            ) {
                code
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminUser();
        $this->shop = Shop::firstOrFail();
        $this->setDeliveryPriceRangesModule(true);
    }

    protected function tearDown(): void
    {
        ModuleConfigService::invalidateCache();
        parent::tearDown();
    }

    private function zone(array $extra = []): ShopZone
    {
        return ShopZone::create(array_merge([
            'shop_id' => $this->shop->id,
            'name' => 'MID-280 ' . uniqid(),
            'delivery_price' => 200,
            'min_order_price' => 500,
            'delivery_type' => 'own',
        ], $extra));
    }

    private function send(string $mutation, ShopZone $zone, array $variables): ShopZone
    {
        $response = $this->actingAs($this->admin)->graphQL($mutation, array_merge([
            'id' => $zone->id,
            'shop_id' => $this->shop->id,
            'delivery_price' => 200,
            'min_order_price' => 500,
        ], $variables));

        $this->assertNull($response->json('errors'), json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));
        $response->assertJsonPath('data.ShopZoneCreteOrUpdate.code', 200);

        return $zone->fresh();
    }

    private function zoneWithRanges(): ShopZone
    {
        return $this->zone([
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => [
                ['from_amount' => 1000, 'price' => 100],
                ['from_amount' => 3000, 'price' => 0],
            ],
        ]);
    }

    public function test_saves_sorted_ranges_and_derives_free_delivery_from(): void
    {
        $zone = $this->send(self::MUTATION, $this->zone(), [
            // аргумент порога игнорируется: порог вычисляется из ступеней
            'free_delivery_from' => 5000,
            'delivery_price_ranges' => [
                ['from_amount' => 3000, 'price' => 0],
                ['from_amount' => 1000, 'price' => 100],
            ],
        ]);

        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $zone->deliveryPriceRangesList());
        $this->assertSame(3000, $zone->free_delivery_from);
    }

    public function test_ranges_without_free_range_clear_threshold(): void
    {
        $zone = $this->send(self::MUTATION, $this->zoneWithRanges(), [
            'delivery_price_ranges' => [['from_amount' => 1000, 'price' => 100]],
        ]);

        $this->assertSame([['from_amount' => 1000, 'price' => 100]], $zone->deliveryPriceRangesList());
        $this->assertNull($zone->free_delivery_from);
    }

    public function test_empty_list_removes_ranges_and_threshold(): void
    {
        $zone = $this->send(self::MUTATION, $this->zoneWithRanges(), [
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => [],
        ]);

        $this->assertSame([], $zone->deliveryPriceRangesList());
        $this->assertNull($zone->free_delivery_from);
    }

    public function test_zero_threshold_range_means_always_free(): void
    {
        $zone = $this->send(self::MUTATION, $this->zone(), [
            'delivery_price_ranges' => [['from_amount' => 0, 'price' => 0]],
        ]);

        $this->assertSame(0, $zone->free_delivery_from);
    }

    public function test_legacy_mutation_replaces_free_range_and_keeps_paid_ranges_below_it(): void
    {
        $zone = $this->send(self::LEGACY_MUTATION, $this->zoneWithRanges(), ['free_delivery_from' => 2500]);

        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 2500, 'price' => 0],
        ], $zone->deliveryPriceRangesList());
        $this->assertSame(2500, $zone->free_delivery_from);
    }

    public function test_legacy_mutation_drops_paid_ranges_at_or_above_new_threshold(): void
    {
        $zone = $this->send(self::LEGACY_MUTATION, $this->zoneWithRanges(), ['free_delivery_from' => 1000]);

        $this->assertSame([['from_amount' => 1000, 'price' => 0]], $zone->deliveryPriceRangesList());
        $this->assertSame(1000, $zone->free_delivery_from);
    }

    public function test_legacy_mutation_without_threshold_keeps_paid_ranges_only(): void
    {
        $zone = $this->send(self::LEGACY_MUTATION, $this->zoneWithRanges(), []);

        $this->assertSame([['from_amount' => 1000, 'price' => 100]], $zone->deliveryPriceRangesList());
        $this->assertNull($zone->free_delivery_from);
    }

    public function test_legacy_mutation_on_zone_without_ranges_creates_free_range(): void
    {
        $zone = $this->send(self::LEGACY_MUTATION, $this->zone(), ['free_delivery_from' => 1500]);

        $this->assertSame([['from_amount' => 1500, 'price' => 0]], $zone->deliveryPriceRangesList());
        $this->assertSame(1500, $zone->free_delivery_from);
    }

    public function test_explicit_null_ranges_behave_like_legacy_mutation(): void
    {
        // Apollo шлёт null для незаполненной переменной: null = «поле не передано»
        $zone = $this->send(self::MUTATION, $this->zoneWithRanges(), [
            'free_delivery_from' => 2500,
            'delivery_price_ranges' => null,
        ]);

        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 2500, 'price' => 0],
        ], $zone->deliveryPriceRangesList());
        $this->assertSame(2500, $zone->free_delivery_from);
    }
}
