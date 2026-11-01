<?php

namespace App\GraphQL\Mutations\Product;

use App\Models\Product;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Services\ProductShopPrice\ProductShopPriceService;
use Illuminate\Validation\ValidationException;

final class ProductShopPricesApplyToAll
{
  /**
   * @param null $_
   * @param array{product_id: int, price: float, quantity: int, price_tiers?: array<int, array{min_quantity: float, value: float}>|null} $args
   */
  public function __invoke($_, array $args): int
  {
    if (!app(NetworkCatalogMode::class)->isEnabled()) {
      throw ValidationException::withMessages([
        'product_id' => ['Доступно только при включённом сетевом каталоге.'],
      ]);
    }

    $product = Product::findOrFail($args['product_id']);

    $service = app(ProductShopPriceService::class);

    return $service->applyToAll(
      $product,
      (float) $args['price'],
      round((float) ($args['stock_quantity'] ?? $args['quantity']), 3),
      $service->ladderFromArgs($args),
    );
  }
}
