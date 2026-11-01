<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


/**
 * Настройки категории для магазина (время сборки, приоритет).
 *
 * Явный $table обязателен: по конвенции Laravel модель смотрела бы в
 * мёртвую таблицу shop_categories (без данных), а живой pivot,
 * который пишет admin_back SetShopCategoriesTime, — shops_categories.
 * SoftDeletes удалён: в shops_categories нет deleted_at.
 *
 * @mixin IdeHelperShopCategory
 */
class ShopCategory extends Model
{
  protected $table = 'shops_categories';
}
