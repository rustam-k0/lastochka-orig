<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesDeliveryPriceRangesModule;
use Tests\TestCase;

/**
 * MID-280: стоимость доставки в корзине по ступеням зоны (по адресу клиента)
 * или ступеням магазина (без адреса). Сумма: товары до промокода и бонусов.
 */
final class CartDeliveryPriceRangesTest extends TestCase
{
    use RefreshDatabase;
    use TogglesDeliveryPriceRangesModule;

    protected function setUp(): void
    {
        parent::setUp();
        // Тесты написаны до тумблера (Task 10): модуль включён, поведение прежнее.
        $this->setDeliveryPriceRangesModule(true);
    }

    private const RANGES = [
        ['from_amount' => 1000, 'price' => 100],
        ['from_amount' => 3000, 'price' => 0],
    ];

    /**
     * @return array{0: User, 1: Store}
     */
    private function cartWithSum(float $sum, bool $withAddress, array $storeAttributes = []): array
    {
        $user = User::factory()->create();

        if ($withAddress) {
            Address::factory()->active()->for($user)->create([
                'latitude' => 55.7558,
                'longitude' => 37.6173,
            ]);
        }

        $store = Store::factory()->create(array_merge([
            'code' => 'shop-ranges',
            'delivery_price' => 200,
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => self::RANGES,
            'min_order_amount' => 0,
            'is_pickup_enabled' => true,
        ], $storeAttributes));

        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => $sum,
            'quantity' => 10,
        ]);

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->for($store)->create([
            'quantity' => 1,
            'price' => $sum,
        ]);

        return [$user, $store];
    }

    private function zoneRanges(array $ranges, int $deliveryPrice = 200, ?int $freeDeliveryFrom = 3000): void
    {
        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([[
            'id' => 1,
            'code' => 'shop-ranges',
            'delivery_price' => $deliveryPrice,
            'min_order_price' => 0,
            'free_delivery_from' => $freeDeliveryFrom,
            'delivery_price_ranges' => $ranges,
        ]]);
    }

    public function test_zone_ranges_by_address(): void
    {
        [$user] = $this->cartWithSum(700, true, ['delivery_price_ranges' => null]);
        $this->zoneRanges(self::RANGES);

        foreach ([[700, 200], [999.99, 200], [1000, 100], [1500, 100], [3000, 0]] as [$sum, $expected]) {
            // Корзина берёт цену позиции из товара: меняем обе
            Product::query()->update(['price' => $sum]);
            CartItem::query()->update(['price' => $sum]);

            $this->actingAs($user)->getJson('/api/v1/cart')
                ->assertOk()
                ->assertJsonPath('storeGroups.0.deliveryCost', $expected);
        }
    }

    public function test_zone_without_ranges_keeps_free_threshold(): void
    {
        [$user] = $this->cartWithSum(1500, true, ['delivery_price_ranges' => null]);
        $this->zoneRanges([], 200, 1000);

        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 0);
    }

    /**
     * Фикс-раунд 1 (MID-280): зона без ступеней и без своего порога
     * (freeDeliveryFrom === null) — запасной порог берётся из
     * stores.free_delivery_from, ровно как в CreateOrderAction::deliveryLadder().
     */
    public function test_zone_without_ranges_or_threshold_falls_back_to_store_threshold(): void
    {
        [$user] = $this->cartWithSum(3000, true, ['delivery_price_ranges' => null, 'free_delivery_from' => 3000]);
        $this->zoneRanges([], 200, null);

        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 0);
    }

    /**
     * Ступени зоны по-прежнему приоритетнее магазинного порога (фикс-раунд 1
     * не должен затронуть этот случай): даже когда store.free_delivery_from
     * выше суммы, бесплатная ступень зоны действует.
     */
    public function test_zone_ranges_ignore_store_threshold(): void
    {
        [$user] = $this->cartWithSum(3000, true, ['delivery_price_ranges' => null, 'free_delivery_from' => 10000]);
        $this->zoneRanges(self::RANGES);

        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 0);
    }

    public function test_store_ranges_without_address(): void
    {
        [$user] = $this->cartWithSum(1500, false);

        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 100);
    }

    public function test_pickup_has_no_delivery_cost(): void
    {
        [$user] = $this->cartWithSum(700, false);

        $this->actingAs($user)->getJson('/api/v1/cart?shippingMethod=pickup')
            ->assertOk()
            ->assertJsonPath('storeGroups.0.deliveryCost', 0);
    }
}
