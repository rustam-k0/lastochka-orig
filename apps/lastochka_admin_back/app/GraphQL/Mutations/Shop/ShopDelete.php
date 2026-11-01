<?php

namespace App\GraphQL\Mutations\Shop;

use App\Models\Shop;

final class ShopDelete
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    Shop::destroy($args['id']);

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
