<?php

declare(strict_types=1);

namespace Tests\Unit\Category1c;

use App\Models\Category1c;
use App\Models\Shop;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class ProductEventTransformerNetworkCategoryTest extends TestCase
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

    public function test_resolves_category_from_anchor_for_foreign_shop_product(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $cat1c = Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-prod-net-1',
        ]);

        $attrs = (new ProductEventTransformer())->fromEvent([
            'shop_code' => $other->code,
            'sku' => 'sku-prod-net-1',
            'title' => 'fixture-product-1',
            'unit_type' => 'piece',
            'category_1c_external_id' => 'guid-prod-net-1',
        ]);

        $this->assertSame($cat1c->id, $attrs['category_1c_id']);
        $this->assertNull($attrs['pending_category_1c_external_id']);
    }

    public function test_stores_pending_when_anchor_category_missing(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $attrs = (new ProductEventTransformer())->fromEvent([
            'shop_code' => $other->code,
            'sku' => 'sku-prod-net-missing',
            'title' => 'fixture-product-missing',
            'unit_type' => 'piece',
            'category_1c_external_id' => 'guid-prod-net-missing',
        ]);

        $this->assertNull($attrs['category_1c_id']);
        $this->assertSame('guid-prod-net-missing', $attrs['pending_category_1c_external_id']);
    }

    public function test_shop_scoped_resolution_when_network_mode_off(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->disableCategory1cNetworkMode();

        Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-prod-net-2',
        ]);

        $attrs = (new ProductEventTransformer())->fromEvent([
            'shop_code' => $other->code,
            'sku' => 'sku-prod-net-2',
            'title' => 'fixture-product-2',
            'unit_type' => 'piece',
            'category_1c_external_id' => 'guid-prod-net-2',
        ]);

        $this->assertNull($attrs['category_1c_id']);
        $this->assertSame('guid-prod-net-2', $attrs['pending_category_1c_external_id']);
    }
}
