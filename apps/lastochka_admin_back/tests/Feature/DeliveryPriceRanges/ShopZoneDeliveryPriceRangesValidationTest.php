<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\User;
use App\Rules\DeliveryPriceRangesRule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * MID-280: GraphQL-поле и проверки ступеней стоимости доставки зоны.
 */
class ShopZoneDeliveryPriceRangesValidationTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private User $admin;

    private Shop $shop;

    private const MUTATION = '
        mutation (
            $id: Int
            $shop_id: Int
            $delivery_price: Int
            $min_order_price: Int
            $delivery_type: String
            $delivery_price_ranges: [ShopZoneDeliveryPriceRangeInput!]
        ) {
            ShopZoneCreteOrUpdate(
                id: $id
                shop_id: $shop_id
                delivery_price: $delivery_price
                min_order_price: $min_order_price
                delivery_type: $delivery_type
                delivery_price_ranges: $delivery_price_ranges
            ) {
                code
                message
            }
        }
    ';

    private const QUERY = '
        query ($id: Int) {
            shop_zone(id: $id) {
                id
                free_delivery_from
                delivery_price_ranges {
                    from_amount
                    price
                }
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminUser();
        $this->shop = Shop::firstOrFail();
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

    private function save(array $ranges, array $extra = [])
    {
        $zone = $this->zone();

        return $this->actingAs($this->admin)->graphQL(self::MUTATION, array_merge([
            'id' => $zone->id,
            'shop_id' => $this->shop->id,
            'delivery_price' => 200,
            'min_order_price' => 500,
            'delivery_type' => 'own',
            'delivery_price_ranges' => $ranges,
        ], $extra));
    }

    /**
     * Мутация прошла: ни ошибок GraphQL, ни ошибок валидации, код 200.
     * assertJsonMissing(['errors']) для этого не годится: ключ errors он не ищет.
     */
    private function assertSaved($response): void
    {
        $this->assertNull($response->json('errors'), json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));
        $response->assertJsonPath('data.ShopZoneCreteOrUpdate.code', 200);
    }

    public function test_accepts_task_example(): void
    {
        $this->assertSaved($this->save([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ]));
    }

    public function test_accepts_range_equal_to_zone_price_and_unsorted_input(): void
    {
        $this->assertSaved($this->save([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 500, 'price' => 200],
        ]));
    }

    public function test_accepts_empty_list_and_zero_threshold(): void
    {
        $this->assertSaved($this->save([]));
        $this->assertSaved($this->save([['from_amount' => 0, 'price' => 0]]));
    }

    public function test_accepts_single_free_range_for_yandex(): void
    {
        $this->assertSaved($this->save([['from_amount' => 2000, 'price' => 0]], ['delivery_type' => 'yandex']));
    }

    public function test_rejects_price_growing_with_amount(): void
    {
        $this->save([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 150],
        ])->assertGraphQLValidationError('delivery_price_ranges', DeliveryPriceRangesRule::MESSAGE_NOT_DECREASING);
    }

    public function test_rejects_first_range_more_expensive_than_zone_price(): void
    {
        $this->save([['from_amount' => 1000, 'price' => 250]])
            ->assertGraphQLValidationError('delivery_price_ranges', DeliveryPriceRangesRule::MESSAGE_NOT_DECREASING);
    }

    public function test_rejects_free_range_that_is_not_last(): void
    {
        $response = $this->save([
            ['from_amount' => 1000, 'price' => 0],
            ['from_amount' => 3000, 'price' => 0],
        ]);

        $response->assertGraphQLValidationKeys(['delivery_price_ranges']);
        $response->assertGraphQLValidationError('delivery_price_ranges', DeliveryPriceRangesRule::MESSAGE_FREE_NOT_LAST);
    }

    public function test_rejects_paid_range_for_yandex(): void
    {
        $this->save([['from_amount' => 1000, 'price' => 100]], ['delivery_type' => 'yandex'])
            ->assertGraphQLValidationError('delivery_price_ranges', DeliveryPriceRangesRule::MESSAGE_YANDEX_PAID);

        $this->save([
            ['from_amount' => 1000, 'price' => 0],
            ['from_amount' => 3000, 'price' => 0],
        ], ['delivery_type' => 'yandex'])
            ->assertGraphQLValidationKeys(['delivery_price_ranges'])
            ->assertGraphQLValidationError('delivery_price_ranges', DeliveryPriceRangesRule::MESSAGE_YANDEX_PAID);
    }

    public function test_rejects_duplicate_amounts_on_rows(): void
    {
        $this->save([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 1000, 'price' => 50],
        ])
            ->assertGraphQLValidationKeys(['delivery_price_ranges.0.from_amount', 'delivery_price_ranges.1.from_amount'])
            ->assertGraphQLValidationError('delivery_price_ranges.1.from_amount', 'Суммы диапазонов не должны повторяться.');
    }

    public function test_rejects_negative_values_on_rows(): void
    {
        $this->save([
            ['from_amount' => -1, 'price' => 100],
            ['from_amount' => 1000, 'price' => -5],
        ])->assertGraphQLValidationKeys(['delivery_price_ranges.0.from_amount', 'delivery_price_ranges.1.price']);
    }

    public function test_rejects_more_than_twenty_ranges(): void
    {
        $ranges = array_map(fn (int $i) => ['from_amount' => $i * 100, 'price' => 100], range(1, 21));

        $this->save($ranges)
            ->assertGraphQLValidationError('delivery_price_ranges', 'Не больше 20 диапазонов.');
    }

    public function test_query_returns_empty_list_for_zone_without_ranges(): void
    {
        $zone = $this->zone();

        $this->actingAs($this->admin)
            ->graphQL(self::QUERY, ['id' => $zone->id])
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.shop_zone.delivery_price_ranges', []);
    }

    public function test_query_returns_ranges_sorted_by_amount(): void
    {
        $zone = $this->zone([
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => [
                ['from_amount' => 3000, 'price' => 0],
                ['from_amount' => 1000, 'price' => 100],
            ],
        ]);

        $this->actingAs($this->admin)
            ->graphQL(self::QUERY, ['id' => $zone->id])
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.shop_zone.delivery_price_ranges', [
                ['from_amount' => 1000, 'price' => 100],
                ['from_amount' => 3000, 'price' => 0],
            ]);
    }
}
