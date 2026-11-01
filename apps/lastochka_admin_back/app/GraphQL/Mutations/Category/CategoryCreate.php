<?php

namespace App\GraphQL\Mutations\Category;

use App\Models\Category;
use App\Services\Stock\StockMode;

final class CategoryCreate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $args['parent_id'] = $args['parent'] ?? null;
    $args = \App\Services\CustomCheckboxService::sanitizeArgs('Category', $args);

    $item = Category::create($args);

    // Подкатегория наследует режим остатков (stock_mode) родителя, если у того
    // он задан (ignore/fixed) и сама подкатегория ещё в normal/пусто
    // (LASTOCHKI-JAMI-11207).
    if ($item->parent_id) {
        $parent = Category::find($item->parent_id);
        if ($parent
            && $parent->stock_mode
            && $parent->stock_mode !== StockMode::NORMAL
            && ($item->stock_mode === null || $item->stock_mode === StockMode::NORMAL)) {
            $item->forceFill([
                'stock_mode' => $parent->stock_mode,
                'fixed_stock_quantity' => $parent->fixed_stock_quantity,
                'fixed_stock_unit_type_id' => $parent->fixed_stock_unit_type_id,
            ])->save();
        }
    }

    if (isset($args['attribute_family_id'])) {
        $item->attribute_family_id = $args['attribute_family_id'];
        $item->save();
    }

    if (isset($args['site_catalog_img']) && $args['site_catalog_img'] != []) {
      $item->saveImg($args['site_catalog_img'], \App\Models\Status::CODE_SITE_CATALOG_IMG);
    }
    if (isset($args['site_square_img']) && $args['site_square_img'] != []) {
      $item->saveImg($args['site_square_img'], \App\Models\Status::CODE_SITE_SQUARE_IMG);
      $item->saveImg($args['site_square_img'], \App\Models\Status::CODE_SITE_CATALOG_IMG);
    }
    if (isset($args['site_rect_img']) && $args['site_rect_img'] != []) {
      $item->saveImg($args['site_rect_img'], \App\Models\Status::CODE_SITE_RECT_IMG);
    }
    if (isset($args['mobile_column_img']) && $args['mobile_column_img'] != []) {
      $item->saveImg($args['mobile_column_img'], \App\Models\Status::CODE_MOB_COLUMN_IMG);
    }
    if (isset($args['mobile_square_img']) && $args['mobile_square_img'] != []) {
      $item->saveImg($args['mobile_square_img'], \App\Models\Status::CODE_MOB_SQUARE_IMG);
    }
    if (isset($args['mobile_rect_img']) && $args['mobile_rect_img'] != []) {
      $item->saveImg($args['mobile_rect_img'], \App\Models\Status::CODE_MOB_RECT_IMG);
    }
    if (isset($args['background_img']) && $args['background_img'] != []) {
      $item->saveImg($args['background_img'], \App\Models\Status::CODE_BACKGROUND_IMG);
    }


    return [
      'code' => 200,
      'message' => 'OK',
      'id' => $item->id
    ];
  }
}
