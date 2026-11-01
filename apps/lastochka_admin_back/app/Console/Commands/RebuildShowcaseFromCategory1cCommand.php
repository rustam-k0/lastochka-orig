<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Пересобрать витринное дерево как зеркало 1С-категорий магазина (1↔1).
 *
 * Для каждой `categories_1c` создаётся витринная `categories` с той же структурой
 * (parent_id), привязывается к магазину (shops_categories, полная ветка) и
 * связывается 1↔1 через pivot `category_1c_category`. Затем `touch()` публикует
 * `category.updated` per-shop → storefront пересоздаёт витрины.
 *
 * Товары перекладываются в новые витрины отдельным шагом — SyncCategoryMappingsJob
 * (мутация sync_categories_1c), который читает category1c→showcaseCategories.
 *
 * --wipe очищает старые ВИТРИННЫЕ категории и их линковки в ЭТОЙ БД (admin) перед
 * пересборкой. НЕ трогает: products, products.category_1c_id, categories_1c,
 * event_sync_metadata товаров/заказов/клиентов. Storefront чистится отдельно (SQL).
 */
class RebuildShowcaseFromCategory1cCommand extends Command
{
  protected $signature = 'category1c:rebuild-showcase
                          {--shop= : ID магазина (обязательно)}
                          {--wipe : Очистить старые витрины и их линковки в admin перед пересборкой}
                          {--no-events : Не публиковать RabbitMQ-события (для локального теста)}
                          {--dry-run : Показать план без изменений}';

  protected $description = 'Пересобрать витринное дерево как зеркало categories_1c магазина (1↔1) с привязкой и публикацией. В сетевом режиме после команды запустить PropagateShowcaseCategoriesToShopsJob (витрины на все магазины)';

  public function handle(): int
  {
    $shopId = (int) $this->option('shop');
    if ($shopId <= 0) {
      $this->error('Укажите --shop=<id>');
      return self::FAILURE;
    }

    $shop = Shop::find($shopId);
    if (!$shop) {
      $this->error("Магазин #{$shopId} не найден");
      return self::FAILURE;
    }

    $cats1c = Category1c::where('shop_id', $shopId)
      ->whereNull('deleted_at')
      ->orderBy('position')
      ->orderBy('id')
      ->get();

    if ($cats1c->isEmpty()) {
      $this->warn("У магазина #{$shopId} нет активных categories_1c — нечего зеркалить");
      return self::SUCCESS;
    }

    $dryRun = (bool) $this->option('dry-run');
    $wipe = (bool) $this->option('wipe');
    $withEvents = !$this->option('no-events');

    $this->info("Магазин #{$shopId} ({$shop->title}): categories_1c = {$cats1c->count()}");

    if ($dryRun) {
      $this->line('[dry-run] wipe=' . ($wipe ? 'да' : 'нет') . ", создано будет витрин: {$cats1c->count()}");
      return self::SUCCESS;
    }

    $map = []; // cat1c_id => new category_id

    DB::transaction(function () use ($cats1c, $shop, $wipe, &$map) {
      if ($wipe) {
        $this->wipeShowcaseCategories();
      }

      // Создаём витрины в топологическом порядке (родитель раньше детей).
      $byParent = $cats1c->groupBy('parent_id');
      $this->createSubtree(null, null, $byParent, $map);

      // Привязка всех витрин к магазину (дерево полное → инвариант ветки соблюдён).
      $shop->categories()->syncWithoutDetaching(array_values($map));

      // Привязка 1↔1 категория_1c → витрина-зеркало.
      foreach ($cats1c as $c1c) {
        $c1c->showcaseCategories()->syncWithoutDetaching([$map[$c1c->id] => ['position' => 0]]);
        $c1c->forceFill(['linked_at' => now()])->save();
      }
    });

    $this->info('Создано витрин: ' . count($map) . ', привязок 1↔1: ' . count($map));

    if ($withEvents) {
      // touch ПОСЛЕ коммита транзакции — Observer опубликует category.updated
      // с relatedModel=Shop (через relation shopCategories.shop) → storefront.
      Category::whereIn('id', array_values($map))->each(fn(Category $c) => $c->touch());
      $this->info('Опубликованы события category.updated для ' . count($map) . ' витрин');
    }

    return self::SUCCESS;
  }

  /**
   * Рекурсивно создать поддерево витрин по дереву categories_1c.
   *
   * @param  int|null  $parent1cId    parent_id в categories_1c (null = корни)
   * @param  int|null  $parentCatId   id уже созданной витрины-родителя
   * @param  \Illuminate\Support\Collection  $byParent  categories_1c, сгруппированные по parent_id
   * @param  array<int,int>  $map     cat1c_id => new category_id (заполняется по ссылке)
   */
  private function createSubtree(?int $parent1cId, ?int $parentCatId, $byParent, array &$map): void
  {
    foreach ($byParent->get($parent1cId, collect()) as $c1c) {
      $cat = Category::create([
        'title'     => $c1c->name,
        'link'      => 'c1c-' . $c1c->id,
        'parent_id' => $parentCatId,
        'position'  => $c1c->position,
      ]);
      $map[$c1c->id] = $cat->id;

      $this->createSubtree($c1c->id, $cat->id, $byParent, $map);
    }
  }

  /**
   * Очистить старые витринные категории и их линковки в admin-БД.
   * НЕ трогает товары, categories_1c и esm товаров.
   */
  private function wipeShowcaseCategories(): void
  {
    DB::table('category_1c_category')->delete();
    DB::table('category_product')->delete();
    DB::table('shops_categories')->delete();
    DB::table('merchant_promocode_categories')->delete();
    DB::table('merchant_banners')
      ->where('link_type', 'category')
      ->update(['link_type' => 'none', 'category_id' => null]);
    DB::table('event_sync_metadata')->where('entity_type', 'categories')->delete();
    Category1c::query()->update(['linked_at' => null]);
    // Снимаем self-FK parent_id перед удалением, иначе categories_parent_id_foreign
    // (RESTRICT) не даёт удалить родителя раньше детей.
    DB::table('categories')->update(['parent_id' => null]);
    DB::table('categories')->delete();

    $this->warn('Wipe admin: витрины и линковки категорий очищены');
  }
}
