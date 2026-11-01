<?php

namespace App\GraphQL\Mutations\Product;

use App\Models\Product;
use App\Models\Shop;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Services\ProductShopPrice\ProductShopPriceService;
use Illuminate\Validation\ValidationException;

final class ProductShopPriceDelete
{
  /**
   * @param null $_
   * @param array{product_id: int, shop_id: int} $args
   */
  public function __invoke($_, array $args): bool
  {
    if (!app(NetworkCatalogMode::class)->isEnabled()) {
      throw ValidationException::withMessages([
        'product_id' => ['Доступно только при включённом сетевом каталоге.'],
      ]);
    }

    $product = Product::findOrFail($args['product_id']);
    // Снятие оффера у soft-deleted магазина легитимно: @rules у Delete осознанно без deleted_at,NULL
    $shop = Shop::withTrashed()->findOrFail($args['shop_id']);

    return app(ProductShopPriceService::class)->delete($product, $shop);
  }
}
