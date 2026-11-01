<?php

namespace App\GraphQL\Mutations\Category;

use App\Models\Category;

final class CategoryUpdate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $args['parent_id'] = $args['parent'] ?? null;
    $args = \App\Services\CustomCheckboxService::sanitizeArgs('Category', $args);

    $item = Category::find($args['id']);

    $item->update($args);
    $changed = $item->wasChanged();

    if (array_key_exists('attribute_family_id', $args)) {
        $item->attribute_family_id = $args['attribute_family_id'];
        $item->save();
        $changed = $changed || $item->wasChanged();
    }

    if (isset($args['site_catalog_img']) && !$item->joinSiteCatalogImg) {
      if ($args['site_catalog_img'] != []) {
        $item->saveImg($args['site_catalog_img'], \App\Models\Status::CODE_SITE_CATALOG_IMG);
      }
    } else {
      $item->updateImg($item->joinSiteCatalogImg, $args['site_catalog_img'] ?? null, \App\Models\Status::CODE_SITE_CATALOG_IMG);
    }
    if (isset($args['site_square_img']) && !$item->joinSiteSquareImg) {
      if ($args['site_square_img'] != []) {
        $item->saveImg($args['site_square_img'], \App\Models\Status::CODE_SITE_SQUARE_IMG);
        $item->saveImg($args['site_square_img'], \App\Models\Status::CODE_SITE_CATALOG_IMG);
      }
    } elseif (isset($args['site_square_img'])) {
      $item->updateImg($item->joinSiteSquareImg, $args['site_square_img'] ?? null, \App\Models\Status::CODE_SITE_SQUARE_IMG);
      $item->updateImg($item->joinSiteCatalogImg, $args['site_square_img'] ?? null, \App\Models\Status::CODE_SITE_CATALOG_IMG);
    }
    if (isset($args['site_rect_img']) && !$item->joinSiteRectImg) {
      if ($args['site_rect_img'] != []) {
        $item->saveImg($args['site_rect_img'], \App\Models\Status::CODE_SITE_RECT_IMG);
      }
    } else {
      $item->updateImg($item->joinSiteRectImg, $args['site_rect_img'] ?? null, \App\Models\Status::CODE_SITE_RECT_IMG);
    }
    if (isset($args['mobile_column_img']) && !$item->joinMobileColumnImg) {
      if ($args['mobile_column_img'] != []) {
        $item->saveImg($args['mobile_column_img'], \App\Models\Status::CODE_MOB_COLUMN_IMG);
      }
    } else {
      $item->updateImg($item->joinMobileColumnImg, $args['mobile_column_img'] ?? null, \App\Models\Status::CODE_MOB_COLUMN_IMG);
    }
    if (isset($args['mobile_square_img']) && !$item->joinMobileSquareImg) {
      if ($args['mobile_square_img'] != []) {
        $item->saveImg($args['mobile_square_img'], \App\Models\Status::CODE_MOB_SQUARE_IMG);
      }
    } else {
      $item->updateImg($item->joinMobileSquareImg, $args['mobile_square_img'] ?? null, \App\Models\Status::CODE_MOB_SQUARE_IMG);
    }
    if (isset($args['mobile_rect_img']) && !$item->joinMobileRectImg) {
      if ($args['mobile_rect_img'] != []) {
        $item->saveImg($args['mobile_rect_img'], \App\Models\Status::CODE_MOB_RECT_IMG);
      }
    } else {
      $item->updateImg($item->joinMobileRectImg, $args['mobile_rect_img'] ?? null, \App\Models\Status::CODE_MOB_RECT_IMG);
    }
    if (isset($args['background_img']) && !$item->joinBackgroundImg) {
      if ($args['background_img'] != []) {
        $item->saveImg($args['background_img'], \App\Models\Status::CODE_BACKGROUND_IMG);
      }
    } else {
      $item->updateImg($item->joinBackgroundImg, $args['background_img'] ?? null);
    }

    // category.updated не фаерится, если менялись только изображения (они в
    // отдельной таблице join_img, не в строке categories). Форсим событие, иначе
    // правка только фона/картинок не уедет на витрину.
    if (!$changed) {
      $item->touch();
    }

    return [
      'code' => 200,
      'message' => 'OK',
      'id' => $item->id
    ];
  }
}
