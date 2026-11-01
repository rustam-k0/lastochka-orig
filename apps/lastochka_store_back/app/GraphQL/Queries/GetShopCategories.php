<?php

namespace App\GraphQL\Queries;

use App\Models\Category;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder;

final class GetShopCategories
{
  const PRODUCTS_PER_CATEGORY = 5;

  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $shop = Shop::find($args['shop_id']);
    if (!$shop) {
      return null;
    }

    $shop_query = fn($q) => $q->filter($args);

    $categories_query = isset($args['parent_id'])
      ? Category::whereParentId($args['parent_id'])
      : $shop->categories();
    $categories = $categories_query->with([
        'childCategories.products' => $shop_query,
        'childCategories.childCategories.products' => $shop_query,
        'products' => $shop_query
      ])
      ->get();

    foreach ($categories as $category) {
      $category->products = $this->getTree($category);
      $category->has_children = $category->childCategories->isNotEmpty();
    }

    return $categories;
  }


  public function getTree(Category $category, $count = 0)
  {

    if ($count >= self::PRODUCTS_PER_CATEGORY) {
      return collect();
    }

    $products = $category->products;


    foreach ($category->childCategories as $childCategory) {
      $products = $products->merge($this->getTree($childCategory, $products->count()));
    }

    return $products;
  }

}
