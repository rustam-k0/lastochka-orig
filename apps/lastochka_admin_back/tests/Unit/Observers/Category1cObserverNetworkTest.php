<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Models\Category1c;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class Category1cObserverNetworkTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    private function makePendingProduct(Shop $shop, string $externalId): Product
    {
        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Pending product ' . uniqid();
        $product->pending_category_1c_external_id = $externalId;
        $product->save();

        return $product;
    }

    public function test_resolves_pending_products_of_all_shops_in_network_mode(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $shopB = $this->makeShop('shop-b-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $productA = $this->makePendingProduct($shopA, 'guid-obs-net-1');
        $productB = $this->makePendingProduct($shopB, 'guid-obs-net-1');

        $category = Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-obs-net-1',
        ]);

        $this->assertSame($category->id, $productA->fresh()->category_1c_id);
        $this->assertSame($category->id, $productB->fresh()->category_1c_id);
        $this->assertNull($productA->fresh()->pending_category_1c_external_id);
    }

    public function test_resolves_only_same_shop_products_when_network_mode_off(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $this->disableCategory1cNetworkMode();

        $productAnchor = $this->makePendingProduct($anchor, 'guid-obs-net-2');
        $productA = $this->makePendingProduct($shopA, 'guid-obs-net-2');

        $category = Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-obs-net-2',
        ]);

        $this->assertSame($category->id, $productAnchor->fresh()->category_1c_id);
        $this->assertNull($productA->fresh()->category_1c_id);
    }
}
