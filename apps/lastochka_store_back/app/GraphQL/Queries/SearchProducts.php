<?php

namespace App\GraphQL\Queries;

use App\Models\Shop;

final class SearchProducts
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $search = fn($query) => $query->where('title', 'like', "%{$args['title']}%");

    $shops = Shop::auth()->onlyActive()
      ->whereHas('products', $search)
      ->with(['products' => $search,'products.price','logo_img'])->get();


    return $shops;
  }
}
