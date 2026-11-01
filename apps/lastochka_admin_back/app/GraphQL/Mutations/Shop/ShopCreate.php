<?php

namespace App\GraphQL\Mutations\Shop;

use App\Models\Shop;
use App\Services\ShopService;

final class ShopCreate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $service = new ShopService();
    $args = \App\Services\CustomCheckboxService::sanitizeArgs('Shop', $args);
    $shop = Shop::create($args);
    $service->fillImages($shop, $args);

    // Единственность дефолтного магазина в городе — только через сервис:
    // Shop::create() записал бы флаг напрямую, не сбросив прежний дефолт.
    if (!empty($args['is_default'])) {
      $service->syncDefaultShop($shop, true);
    }

    $service->syncCategoriesWithEvents($shop, $args['categories'] ?? []);

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
