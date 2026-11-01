<?php

declare(strict_types=1);

namespace App\Jobs\Category1c;

use App\Models\Category;
use App\Models\Shop;
use App\Services\ShopService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Распространяет витринные категории на магазины сети (сетевой режим
 * маппинга категорий 1С): каждому магазину аддитивно добавляются выбранные
 * витрины вместе с предками (инвариант shops_categories — полная ветка от
 * корня), после чего по каждой затронутой категории публикуется
 * `category.updated` — по одному событию на магазин (publishMultiEntity).
 *
 * $onlyShopId — ограничение одним магазином (сценарий «в сеть добавили магазин»).
 *
 * Почему привязка и публикация разнесены, а не идут per-shop, как в
 * ShopService::attachCategoriesWithEvents: publishMultiEntity пишет в outbox по
 * событию на КАЖДУЮ существующую связь shopCategories. touch() внутри цикла по
 * магазинам давал 1+2+...+N = N(N+1)/2 событий на категорию (при 824 магазинах
 * — ~340k), запрос по 60 секунд не укладывался в fastcgi_read_timeout и падал в
 * 504, а часть магазинов вообще не получала событие: touch() в пределах одной
 * секунды не меняет updated_at, модель не dirty и Observer молчит. Один touch
 * после полной привязки даёт ровно N событий — по одному на магазин.
 *
 * Этого мало, когда магазины заводятся по одному: ShopObserver::created
 * запускает Job с $onlyShopId на каждый новый магазин, и хотя внутри прогона
 * touch один, Observer рассылал его по ВСЕМ уже существующим связям — те же
 * N(N+1)/2, только размазанные по прогонам. Поэтому публикация идёт в скоупе
 * Category::publishingOnlyForShops() по магазинам, которым связь реально
 * добавлена в этом прогоне (инцидент 2026-08-10: 654 магазина за час → ~29k
 * событий на две категории → диск 100% → RabbitMQ в disk alarm).
 *
 * Ретраи безопасны: привязка идемпотентна (вставляются только недостающие пары),
 * повторный проход дотянет хвост после сбоя.
 */
class PropagateShowcaseCategoriesToShopsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Магазинов за проход: столько же строк pivot читается и вставляется за раз. */
    private const SHOP_CHUNK = 500;

    /** Максимум строк в одном INSERT — защита от разрастания пакета запроса. */
    private const INSERT_CHUNK = 1000;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public array $showcaseCategoryIds,
        public ?int $onlyShopId = null,
    ) {
    }

    public function handle(ShopService $shopService): void
    {
        if ($this->showcaseCategoryIds === []) {
            return;
        }

        $parentMap = $shopService->categoryParentMap();
        $shopIdsByCategory = [];

        Shop::query()
            ->when($this->onlyShopId !== null, fn ($q) => $q->where('id', $this->onlyShopId))
            ->select('id')
            ->chunkById(self::SHOP_CHUNK, function ($shops) use ($shopService, $parentMap, &$shopIdsByCategory) {
                $shopIds = $shops->pluck('id')->map(fn ($id) => (int) $id)->all();

                $this->attachChunk($shopService, $shopIds, $parentMap, $shopIdsByCategory);
            });

        if ($shopIdsByCategory === []) {
            return;
        }

        // Один touch на категорию, но событие уходит только магазинам, которым
        // связь реально добавлена в этом прогоне. Без скоупа Observer разослал бы
        // по всем существующим связям, и заведение магазинов по одному
        // (ShopObserver::created → Job с $onlyShopId) давало бы N(N+1)/2 событий.
        Category::query()
            ->whereIn('id', array_keys($shopIdsByCategory))
            ->each(fn (Category $category) => Category::publishingOnlyForShops(
                $shopIdsByCategory[$category->id],
                fn () => $category->touch()
            ));
    }

    /**
     * Привязать витрины (с предками) к пачке магазинов одним пакетом запросов.
     *
     * @param  list<int>  $shopIds
     * @param  array<int, int|null>  $parentMap
     * @param  array<int, list<int>>  $shopIdsByCategory  накопитель: категория → магазины,
     *                                                    которым связь реально добавлена
     */
    private function attachChunk(
        ShopService $shopService,
        array $shopIds,
        array $parentMap,
        array &$shopIdsByCategory
    ): void {
        if ($shopIds === []) {
            return;
        }

        $currentByShop = DB::table('shops_categories')
            ->whereIn('shop_id', $shopIds)
            ->get(['shop_id', 'category_id'])
            ->groupBy('shop_id')
            ->map(fn ($rows) => $rows->pluck('category_id')->map(fn ($id) => (int) $id)->all())
            ->all();

        $now = now();
        $rows = [];

        foreach ($shopIds as $shopId) {
            $currentIds = $currentByShop[$shopId] ?? [];

            // Семантика attachCategoriesWithEvents: расширяем предками объединение
            // текущих и новых категорий — так добираются и предки уже привязанных
            // листьев, если инвариант pivot был нарушен раньше.
            $fullIds = $shopService->expandWithAncestorsFromMap(
                array_merge($currentIds, $this->showcaseCategoryIds),
                $parentMap
            );

            foreach (array_diff($fullIds, $currentIds) as $categoryId) {
                $rows[] = [
                    'shop_id' => $shopId,
                    'category_id' => $categoryId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $shopIdsByCategory[$categoryId][] = $shopId;
            }
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $batch) {
            DB::table('shops_categories')->insert($batch);
        }
    }
}
