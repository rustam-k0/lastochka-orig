<?php

namespace App\GraphQL\Queries\Product;

use App\Models\Product;

/**
 * Значение базовой цены товара — по конкретному магазину или первой строке
 * (детерминированно, по минимальному shop_id: сортировка идёт по индексу
 * prices(product_id, shop_id, type_id) без filesort по ~1.5k строк товара).
 *
 * Замена default_price { value } в списке товаров: у товара по строке цены
 * на каждый магазин сети, hasOne-батч Lighthouse гидрировал их все
 * (~37k строк на страницу из 25 товаров), чтобы взять по одной на товар.
 */
final class ProductDefaultPriceValue
{
  /**
   * @param array{shop_id?: int|null} $args
   */
  public function __invoke(Product $product, array $args): ?float
  {
    $value = $product->defaultPrice()
      ->when(isset($args['shop_id']), fn ($q) => $q->where('shop_id', (int) $args['shop_id']))
      ->orderBy('shop_id')
      ->value('value');

    return $value !== null ? (float) $value : null;
  }
}
