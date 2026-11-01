<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\GraphQL\Mutations\Category1cMutations\LinkToShowcase;
use App\Jobs\Category1c\PropagateShowcaseCategoriesToShopsJob;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class Category1cLinkNetworkTest extends TestCase
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

    public function test_network_mode_dispatches_propagate_job(): void
    {
        Queue::fake();
        $anchor = $this->makeShop('anchor-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);
        $cat1c = Category1c::factory()->create(['shop_id' => $anchor->id]);

        (new LinkToShowcase())(null, [
            'category_1c_ids' => [$cat1c->id],
            'showcase_category_ids' => [$showcase->id],
        ]);

        Queue::assertPushed(
            PropagateShowcaseCategoriesToShopsJob::class,
            fn ($job) => $job->showcaseCategoryIds === [$showcase->id] && $job->onlyShopId === null
        );
        $this->assertTrue($cat1c->fresh()->showcaseCategories->pluck('id')->contains($showcase->id));
        // Синхронного attach к магазину не было — распространение асинхронное.
        $this->assertFalse($anchor->categories()->where('categories.id', $showcase->id)->exists());
    }

    public function test_per_shop_mode_attaches_synchronously_without_job(): void
    {
        Queue::fake();
        $shop = $this->makeShop('shop-' . uniqid());
        $this->disableCategory1cNetworkMode();

        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);

        (new LinkToShowcase())(null, [
            'category_1c_ids' => [$cat1c->id],
            'showcase_category_ids' => [$showcase->id],
        ]);

        Queue::assertNotPushed(PropagateShowcaseCategoriesToShopsJob::class);
        $this->assertTrue($shop->categories()->where('categories.id', $showcase->id)->exists());
    }
}
