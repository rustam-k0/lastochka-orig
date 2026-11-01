<?php

namespace App\GraphQL\Queries;

use App\Models\Category;
use App\Models\Shop;

final class GetShopCategories
{

  /**
   * Возвращает дерево категорий магазина (root → children) в виде plain-array
   * с ключом `child_categories`, фильтрованного строго по pivot shops_categories.
   *
   * Почему plain-array, а не Eloquent-модели: поле `Category.child_categories`
   * в схеме помечено `@hasMany(relation: "childCategories")`. Lighthouse
   * RelationDirective игнорирует уже загруженную через `setRelation()`
   * коллекцию и делает свой запрос → отфильтрованный результат затирается.
   * Схема объявляет отдельный `ShopCategoryTreeNode` без директив, и значения
   * берутся из массива по ключам.
   *
   * Инвариант pivot'а (полная ветка от корня до выбранного узла) поддерживает
   * ShopService::syncCategoriesWithEvents — благодаря этому дерево всегда
   * связное и пользователь видит в форме товара ровно те категории, к которым
   * привязан магазин.
   *
   * @param null $_
   * @param array{shop_id?: int|null} $args
   */
  public function __invoke($_, array $args): ?array
  {
    if (\Auth::user()->isGlobalScope()) {
      $shop_id = $args['shop_id'] ?? null;
    } else {
      $shop_id = \Auth::user()->shops()->first()->shop_id;
    }

    if (!$shop_id) {
      return null;
    }

    $shop = Shop::find($shop_id);

    if (!$shop) {
      return null;
    }

    $shopCategoryIds = $shop->categories()
      ->pluck('categories.id')
      ->map(fn($v) => (int) $v)
      ->all();

    if (empty($shopCategoryIds)) {
      return [];
    }

    $categories = Category::whereIn('id', $shopCategoryIds)
      ->orderBy('position')
      ->orderBy('id')
      ->get()
      ->keyBy('id');

    $childrenIndex = [];
    foreach ($categories as $cat) {
      $parentId = $cat->parent_id;
      if ($parentId !== null && isset($categories[$parentId])) {
        $childrenIndex[$parentId][] = $cat->id;
      }
    }

    $roots = $categories
      ->filter(fn(Category $c) => $c->parent_id === null || !isset($categories[$c->parent_id]))
      ->keys()
      ->all();

    return array_map(
      fn(int $rootId) => $this->buildNode($rootId, $categories, $childrenIndex),
      $roots
    );
  }

  /**
   * @param \Illuminate\Support\Collection<int, Category> $categories
   * @param array<int, list<int>>                        $childrenIndex
   */
  private function buildNode(int $id, $categories, array $childrenIndex): array
  {
    $cat = $categories[$id];

    $children = array_map(
      fn(int $childId) => $this->buildNode($childId, $categories, $childrenIndex),
      $childrenIndex[$id] ?? []
    );

    return [
      'id' => (int) $cat->id,
      'title' => $cat->title,
      'is_cooking' => (bool) ($cat->is_cooking ?? false),
      'child_categories' => $children,
    ];
  }
}
