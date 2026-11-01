<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\Category1c\PropagateShowcaseCategoriesToShopsJob;
use App\Models\Category;
use App\Models\Shop;
use App\Services\ModuleConfigService;

/**
 * Сетевой режим маппинга категорий 1С: новый (или восстановленный) магазин
 * автоматически получает все витринные категории, участвующие в сетевом
 * маппинге. Магазины, созданные в обход Eloquent-событий (bulk insert,
 * consumer с withoutEvents), подхватятся ближайшим Link (Propagate по всем).
 */
class ShopObserver
{
    public function created(Shop $shop): void
    {
        $this->propagateNetworkShowcase($shop);
    }

    public function restored(Shop $shop): void
    {
        $this->propagateNetworkShowcase($shop);
    }

    private function propagateNetworkShowcase(Shop $shop): void
    {
        if (ModuleConfigService::getCategory1cNetworkAnchorShopId() === null) {
            return;
        }

        $showcaseIds = Category::query()
            ->whereHas('category1cMappings')
            ->pluck('id')
            ->all();

        if ($showcaseIds === []) {
            return;
        }

        PropagateShowcaseCategoriesToShopsJob::dispatch($showcaseIds, $shop->id);
    }
}
