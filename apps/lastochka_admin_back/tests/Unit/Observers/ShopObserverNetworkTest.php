<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Jobs\Category1c\PropagateShowcaseCategoriesToShopsJob;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class ShopObserverNetworkTest extends TestCase
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

    private function makeNetworkMapping(Shop $anchor): Category
    {
        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);
        $cat1c = Category1c::factory()->create(['shop_id' => $anchor->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        return $showcase;
    }

    public function test_category1c_mappings_relation_works_on_pivot(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $showcase = $this->makeNetworkMapping($anchor);

        $this->assertTrue(
            Category::whereHas('category1cMappings')->pluck('id')->contains($showcase->id)
        );
    }

    public function test_new_shop_triggers_propagation_in_network_mode(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $showcase = $this->makeNetworkMapping($anchor);
        $this->enableCategory1cNetworkMode($anchor->id);

        Queue::fake();
        $newShop = $this->makeShop('new-' . uniqid());

        Queue::assertPushed(
            PropagateShowcaseCategoriesToShopsJob::class,
            fn ($job) => $job->onlyShopId === $newShop->id
                && in_array($showcase->id, $job->showcaseCategoryIds, true)
        );
    }

    public function test_new_shop_does_not_dispatch_when_network_mode_off(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $this->makeNetworkMapping($anchor);
        $this->disableCategory1cNetworkMode();

        Queue::fake();
        $this->makeShop('new-' . uniqid());

        Queue::assertNotPushed(PropagateShowcaseCategoriesToShopsJob::class);
    }

    public function test_new_shop_without_mappings_does_not_dispatch(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        Queue::fake();
        $this->makeShop('new-' . uniqid());

        Queue::assertNotPushed(PropagateShowcaseCategoriesToShopsJob::class);
    }
}
