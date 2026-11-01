<?php

namespace App\GraphQL\Mutations\Shop;

use App\Models\Category;
use App\Models\Shop;
use App\Models\ShopCategory;

final class SetShopCategoriesTime
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $shop = Shop::find($args['shop_id']);

    // Upsert строго в pivot shops_categories: Shop::categories() — belongsToMany(Category),
    // присвоение time_to_take через него уходит UPDATE'ом в таблицу categories (SQL error).
    // Состав привязок магазин<->категория мутация не урезает — пивот ведут
    // LinkToShowcase/ShopService, удаление "лишних" строк сносило бы привязки витрин.
    //
    // touch'им (→ RabbitMQ category.updated → storefront перекачивает картинку)
    // ТОЛЬКО реально изменённые/новые категории. Фронт планограммы шлёт ВЕСЬ
    // список (сотни категорий) на каждый blur ради консистентного position —
    // безусловный touch всех приводил бы к сотням лишних перекачек картинок.
    $touchIds = [];

    foreach ($args['items'] as $item) {
      $shop_category = ShopCategory::firstOrNew([
        'shop_id' => $shop->id,
        'category_id' => (int) $item['category_id'],
      ]);
      $shop_category->time_to_take = (int) $item['time_to_take'];
      $shop_category->position = (int) ($item['position'] ?? 0);
      $changed = ! $shop_category->exists || $shop_category->isDirty();
      $shop_category->save();
      if ($changed) {
        $touchIds[] = (int) $item['category_id'];
      }
    }

    // Автоматически добавляем родительские категории, чтобы иерархия была целостной
    $itemCategoryIds = collect($args['items'])->pluck('category_id')->map(fn($v) => (int) $v)->toArray();
    $ancestorIds = Category::collectAncestorIds($itemCategoryIds);

    foreach ($ancestorIds as $ancestorId) {
      $ancestor = ShopCategory::firstOrCreate([
        'shop_id' => $shop->id,
        'category_id' => $ancestorId,
      ]);
      if ($ancestor->wasRecentlyCreated) {
        $touchIds[] = $ancestorId;
      }
    }

    // Публикуем RabbitMQ события только для затронутых категорий и только по
    // этому магазину: правка расписания касается пары (shop, category), а без
    // скоупа Observer разослал бы событие по всем связям категории.
    $touchIds = array_unique($touchIds);
    if (! empty($touchIds)) {
      Category::whereIn('id', $touchIds)
          ->each(fn(Category $cat) => Category::publishingOnlyForShops(
            [$shop->id],
            fn() => $cat->touch()
          ));
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
