<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Shop;
use App\Services\ShopService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillShopCategoryAncestorsCommand extends Command
{
  protected $signature = 'shops:backfill-category-ancestors
                          {--dry-run : Показать план без изменений}
                          {--shop= : Ограничить одним shop_id}';

  protected $description = 'Дополнить pivot shops_categories отсутствующими предками (инвариант: полная ветка от корня до выбранного узла)';

  public function handle(ShopService $shopService): int
  {
    $dryRun = (bool) $this->option('dry-run');
    $shopFilter = $this->option('shop');

    $query = Shop::query();
    if ($shopFilter !== null) {
      $query->where('id', (int) $shopFilter);
    }

    $totalAdded = 0;
    $affectedShops = 0;

    $query->orderBy('id')->chunkById(100, function ($shops) use ($shopService, $dryRun, &$totalAdded, &$affectedShops) {
      foreach ($shops as $shop) {
        $currentIds = $shop->categories()->pluck('categories.id')->map(fn($v) => (int) $v)->all();

        if (empty($currentIds)) {
          continue;
        }

        $fullIds = $shopService->expandWithAncestors($currentIds);
        $missing = array_values(array_diff($fullIds, $currentIds));

        if (empty($missing)) {
          continue;
        }

        $affectedShops++;
        $totalAdded += count($missing);

        $missingTitles = Category::whereIn('id', $missing)->pluck('title', 'id')->all();
        $this->line(sprintf(
          'Shop #%d "%s": +%d предков (%s)',
          $shop->id,
          $shop->title,
          count($missing),
          collect($missingTitles)->map(fn($t, $id) => "{$id}:{$t}")->implode(', ')
        ));

        if ($dryRun) {
          continue;
        }

        DB::transaction(function () use ($shop, $fullIds, $missing) {
          $shop->categories()->sync($fullIds);

          // Скоуп по текущему магазину: предки дописаны в его pivot, ему и
          // сообщаем. Без скоупа touch внутри цикла по магазинам рассылал бы
          // событие по всем связям категории — на сети это N(N+1)/2 событий.
          Category::whereIn('id', $missing)
            ->each(fn(Category $cat) => Category::publishingOnlyForShops(
              [$shop->id],
              fn() => $cat->touch()
            ));
        });
      }
    });

    $this->newLine();
    $this->info(sprintf(
      '%s: затронуто магазинов — %d, добавлено привязок — %d',
      $dryRun ? 'DRY-RUN' : 'Готово',
      $affectedShops,
      $totalAdded
    ));

    return self::SUCCESS;
  }
}
