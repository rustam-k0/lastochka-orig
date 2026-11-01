<?php

namespace App\GraphQL\Mutations\Shop;

use App\Models\Shop;
use App\Services\ShopService;

final class ShopCategorySectionDelete
{
  /**
   * Удалить секцию (категорию) из карты магазина.
   *
   * Удаляет строку pivot shops_categories (shop_id, category_id) и публикует
   * detach-событие, чтобы storefront узнал об отвязке. Идемпотентно: повторный
   * вызов на уже отвязанной категории (или несуществующем магазине) — 200 no-op.
   *
   * @param null $_
   * @param array{shop_id:int,category_id:int} $args
   */
  public function __invoke($_, array $args): array
  {
    $shop = Shop::find($args['shop_id']);

    if ($shop !== null) {
      (new ShopService())->detachCategorySection($shop, (int) $args['category_id']);
    }

    return [
      'code' => 200,
      'message' => 'OK',
    ];
  }
}
