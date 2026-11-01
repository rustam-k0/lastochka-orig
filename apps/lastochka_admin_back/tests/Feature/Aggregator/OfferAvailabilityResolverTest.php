<?php

namespace Tests\Feature\Aggregator;

use App\Aggregator\Services\OfferAvailabilityResolver;
use App\Models\PlatformProduct;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\Status;
use App\Models\Type;
use App\Models\ZoneCoordinate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тесты выборки офферов для витрины (FOODLINK-10874, §6.6): фильтр по зоне доставки
 * (point-in-polygon) + сортировка по цене.
 */
class OfferAvailabilityResolverTest extends TestCase
{
    use DatabaseTransactions;

    private OfferAvailabilityResolver $resolver;
    private PlatformProduct $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new OfferAvailabilityResolver();
        $this->platform = PlatformProduct::create(['canonical_title' => 'Молоко', 'is_active' => true]);
    }

    private function makeShop(string $code): Shop
    {
        return Shop::create(['code' => $code, 'title' => $code]);
    }

    private function makeOffer(Shop $shop, int $price, float $qty = 10, bool $active = true): ProductOffer
    {
        $product = Product::withoutEvents(fn () => Product::create([
            'title' => 'p' . uniqid(), 'articul' => uniqid('a_'),
            'shop_id' => $shop->id, 'status_id' => Status::CODE_ACTIVE, 'unit_type_id' => Type::UNIT_PIECE,
        ]));
        return ProductOffer::create([
            'platform_product_id' => $this->platform->id,
            'shop_id' => $shop->id,
            'local_product_id' => $product->id,
            'price' => $price,
            'qty' => $qty,
            'min_qty' => 1,
            'is_active' => $active,
        ]);
    }

    /** Квадратная зона вокруг (cx,cy) со стороной 2*size. */
    private function makeZone(Shop $shop, float $cx, float $cy, float $size = 1.0): void
    {
        $zone = ShopZone::create([
            'shop_id' => $shop->id,
            'name' => 'zone',
            'delivery_price' => 0,
            'min_order_price' => 0,
        ]);
        $corners = [[$cx - $size, $cy - $size], [$cx + $size, $cy - $size], [$cx + $size, $cy + $size], [$cx - $size, $cy + $size]];
        foreach ($corners as $pos => [$lat, $lng]) {
            ZoneCoordinate::create([
                'shop_zone_id' => $zone->id,
                'latitude' => $lat,
                'longitude' => $lng,
                'position' => $pos,
            ]);
        }
    }

    public function test_without_point_returns_active_in_stock_offers_sorted_by_price(): void
    {
        $cheap = $this->makeOffer($this->makeShop('a'), 1500);
        $expensive = $this->makeOffer($this->makeShop('b'), 2500);
        $this->makeOffer($this->makeShop('c'), 999, 0);          // qty=0 → исключён
        $this->makeOffer($this->makeShop('d'), 500, 10, false);  // неактивный → исключён

        $offers = $this->resolver->resolveOffersForPoint($this->platform, null);

        $this->assertSame([$cheap->id, $expensive->id], $offers->pluck('id')->all());
    }

    public function test_with_point_filters_by_zone_coverage(): void
    {
        $near = $this->makeShop('near');
        $far = $this->makeShop('far');
        $this->makeOffer($near, 2000);
        $this->makeOffer($far, 1000);

        $this->makeZone($near, 0.0, 0.0, 1.0);   // покрывает (0,0)
        $this->makeZone($far, 50.0, 50.0, 1.0);  // не покрывает (0,0)

        $offers = $this->resolver->resolveOffersForPoint($this->platform, [
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        $this->assertSame(1, $offers->count());
        $this->assertSame($near->id, $offers->first()->shop_id);
    }
}
