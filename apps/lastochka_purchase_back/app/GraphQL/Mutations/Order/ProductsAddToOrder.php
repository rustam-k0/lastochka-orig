<?php

namespace App\GraphQL\Mutations\Order;

final class ProductsAddToOrder
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    foreach ($args['products'] as $product) {
      $product['order_id'] = $args['order_id'];
      (new ProductAddToOrder())(null, $product);
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
