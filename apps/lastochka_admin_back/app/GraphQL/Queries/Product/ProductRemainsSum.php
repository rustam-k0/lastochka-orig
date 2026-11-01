<?php

namespace App\GraphQL\Queries\Product;

use App\Models\Product;

/**
 * Суммарный остаток товара — по конкретному магазину или по всей сети.
 *
 * Замена выборки remains { shop_id quantity } в списке товаров: у товара
 * по строке остатка на каждый магазин сети (1.5k+), и страница из 25 товаров
 * гидрировала ~37k Eloquent-моделей только чтобы фронт сложил quantity.
 * Один SUM по индексу (product_id, shop_id) на строку списка на порядки дешевле.
 */
final class ProductRemainsSum
{
  /**
   * @param array{shop_id?: int|null} $args
   */
  public function __invoke(Product $product, array $args): float
  {
    return (float) $product->remains()
      ->when(isset($args['shop_id']), fn ($q) => $q->where('shop_id', (int) $args['shop_id']))
      ->sum('quantity');
  }
}
