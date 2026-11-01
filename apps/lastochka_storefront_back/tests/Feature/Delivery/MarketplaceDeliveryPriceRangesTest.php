<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\TogglesDeliveryPriceRangesModule;
use Tests\TestCase;

/**
 * MID-280: маркетплейсная корзина и сводка доставки считают доставку магазина
 * одним расчётом (ступени зоны по адресу). Раньше сводка складывала базовые
 * цены зон без порога магазина и расходилась с корзиной.
 */
final class MarketplaceDeliveryPriceRangesTest extends TestCase
{
    use RefreshDatabase;
    use TogglesDeliveryPriceRangesModule;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'marketplace_mode' => ['type' => 'select', 'value' => 'marketplace'],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ],
        );
        app(ModuleConfigService::class)->invalidateCache();
        // Тест написан до тумблера (Task 10): модуль включён, поведение прежнее.
        // Мержится в тот же блоб project_modules, marketplace_mode не теряется.
        $this->setDeliveryPriceRangesModule(true);
    }

    private function seedTwoStores(): User
    {
        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        foreach ([['mp-1', 1500], ['mp-2', 700]] as [$code, $sum]) {
            $store = Store::factory()->create([
                'code' => $code,
                'min_order_amount' => 0,
                'delivery_price' => 0,
                'free_delivery_from' => null,
            ]);
            $category = Category::factory()->forStore($store)->create();
            $product = Product::factory()->for($store)->inCategory($category)->create([
                'price' => $sum,
                'quantity' => 10,
            ]);
            $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
            CartItem::factory()->for($cart)->for($product)->create([
                'store_id' => $store->id,
                'quantity' => 1,
                'price' => $sum,
            ]);
        }

        $ranges = [
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ];

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([
            ['id' => 1, 'code' => 'mp-1', 'delivery_price' => 200, 'min_order_price' => 0, 'free_delivery_from' => 3000, 'delivery_price_ranges' => $ranges],
            ['id' => 2, 'code' => 'mp-2', 'delivery_price' => 200, 'min_order_price' => 0, 'free_delivery_from' => 3000, 'delivery_price_ranges' => $ranges],
        ]);

        return $user;
    }

    public function test_cart_and_delivery_summary_use_zone_ranges(): void
    {
        $user = $this->seedTwoStores();

        // mp-1: 1500 → 100, mp-2: 700 → 200
        $this->actingAs($user)->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('totalShippingPrice', 300);

        $this->actingAs($user)->getJson('/api/v1/cart/delivery-summary')
            ->assertOk()
            ->assertJsonPath('totalShippingPrice', 300);
    }
}
