<?php

namespace App\GraphQL\Mutations\Shop;

use App\Models\Shop;
use App\Services\ShopService;

final class ShopUpdate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $service = new ShopService();
    $shop = Shop::find($args['id']);
    $service->fillInfo($shop,$args);

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
