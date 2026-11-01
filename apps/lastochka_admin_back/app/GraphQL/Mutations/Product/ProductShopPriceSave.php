<?php

namespace App\GraphQL\Mutations\Product;

use App\Models\Product;
use App\Models\Shop;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Services\ProductShopPrice\ProductShopPriceService;
use Illuminate\Validation\ValidationException;

final class ProductShopPriceSave
{
  /**
   * @param null $_
   * @param array{product_id: int, shop_id: int, price: float, quantity: int, price_tiers?: array<int, array{min_quantity: float, value: float}>|null} $args
   */
  public function __invoke($_, array $args)
  {
    if (!app(NetworkCatalogMode::class)->isEnabled()) {
      throw ValidationException::withMessages([
        'product_id' => ['Доступно только при включённом сетевом каталоге.'],
      ]);
    }

    $product = Product::findOrFail($args['product_id']);
    $shop = Shop::findOrFail($args['shop_id']);

    $service = app(ProductShopPriceService::class);

    return $service->save(
      $product,
      $shop,
      (float) $args['price'],
      round((float) ($args['stock_quantity'] ?? $args['quantity']), 3),
      $service->ladderFromArgs($args),
    );
  }
}
