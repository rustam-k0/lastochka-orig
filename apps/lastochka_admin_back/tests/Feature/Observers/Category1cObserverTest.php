<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Events\ProductUpdated;
use App\Models\Category1c;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Покрывает Category1cObserver: дозаполнение pending_category_1c_external_id
 * при появлении Category1c (товар приехал раньше своей 1С-категории).
 */
class Category1cObserverTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): Shop
    {
        return Shop::query()->create([
            'title' => 'obs-shop-' . uniqid(),
            'code' => 'obs-' . uniqid(),
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    private function makePendingProduct(Shop $shop, string $externalId): Product
    {
        return Product::query()->create([
            'title' => 'pending-' . uniqid(),
            'articul' => 'P-' . uniqid(),
            'shop_id' => $shop->id,
            'category_1c_id' => null,
            'pending_category_1c_external_id' => $externalId,
        ]);
    }

    /** @test */
    public function created_observer_links_pending_products(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $p1 = $this->makePendingProduct($shop, 'cat-uuid-A');
        $p2 = $this->makePendingProduct($shop, 'cat-uuid-A');
        $other = $this->makePendingProduct($shop, 'cat-uuid-B');

        $cat = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'cat-uuid-A',
        ]);

        $p1->refresh();
        $p2->refresh();
        $other->refresh();

        $this->assertSame($cat->id, $p1->category_1c_id);
        $this->assertNull($p1->pending_category_1c_external_id);
        $this->assertSame($cat->id, $p2->category_1c_id);
        $this->assertNull($p2->pending_category_1c_external_id);

        $this->assertNull($other->category_1c_id);
        $this->assertSame('cat-uuid-B', $other->pending_category_1c_external_id);

        Event::assertDispatched(ProductUpdated::class, 2);
    }

    /** @test */
    public function observer_isolates_by_shop_id(): void
    {
        $shopA = $this->makeShop();
        $shopB = $this->makeShop();
        $pending = $this->makePendingProduct($shopB, 'cat-uuid-X');

        Category1c::factory()->create([
            'shop_id' => $shopA->id,
            'external_id' => 'cat-uuid-X',
        ]);

        $pending->refresh();
        $this->assertNull($pending->category_1c_id);
        $this->assertSame('cat-uuid-X', $pending->pending_category_1c_external_id);
    }

    /** @test */
    public function observer_is_idempotent_on_repeated_resolution(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $product = $this->makePendingProduct($shop, 'cat-uuid-R');

        $cat = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'cat-uuid-R',
        ]);

        Event::assertDispatched(ProductUpdated::class, 1);

        $cat->update(['name' => 'renamed-' . uniqid()]);

        $product->refresh();
        $this->assertSame($cat->id, $product->category_1c_id);
        $this->assertNull($product->pending_category_1c_external_id);
        Event::assertDispatched(ProductUpdated::class, 1);
    }

    /** @test */
    public function observer_does_not_touch_already_linked_products(): void
    {
        $shop = $this->makeShop();
        $existingCat = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'cat-existing',
        ]);

        $linked = Product::query()->create([
            'title' => 'linked',
            'articul' => 'L-' . uniqid(),
            'shop_id' => $shop->id,
            'category_1c_id' => $existingCat->id,
            'pending_category_1c_external_id' => null,
        ]);

        Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'cat-other-new',
        ]);

        $linked->refresh();
        $this->assertSame($existingCat->id, $linked->category_1c_id);
    }

    /** @test */
    public function updated_observer_picks_up_pending_after_external_id_change(): void
    {
        $shop = $this->makeShop();
        $pending = $this->makePendingProduct($shop, 'final-uuid');

        $cat = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'temporary-uuid',
        ]);

        $pending->refresh();
        $this->assertNull($pending->category_1c_id);

        $cat->update(['external_id' => 'final-uuid']);

        $pending->refresh();
        $this->assertSame($cat->id, $pending->category_1c_id);
        $this->assertNull($pending->pending_category_1c_external_id);
    }
}
