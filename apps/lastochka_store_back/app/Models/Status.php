<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Status
 *
 * @package App\Models
 * @mixin IdeHelperStatus
 */
class Status extends Model
{
  use ModelTools;
  protected $guarded = [];

  const table = "statuses";
  protected $table = self::table;

  /**
   * Стандартные коды статусов
   */
  const CODE_ACTIVE = 1;
  const CODE_NOT_PUBLISHED = 3;

  /**
   * Статусы для фотографий
   */
  const CODE_HEAD_IMG = 100;
  const CODE_SLIDER_IMG = 101;
  const CODE_SITE_CATALOG_IMG = 102;
  const CODE_SITE_SQUARE_IMG = 103;
  const CODE_SITE_RECT_IMG = 104;
  const CODE_MOB_COLUMN_IMG = 105;
  const CODE_MOB_SQUARE_IMG = 106;
  const CODE_MOB_RECT_IMG = 107;
  const CODE_LOGO_IMG = 108;
  const CODE_BACKGROUND_IMG = 109;

  /**
   * Коды для файлов
   */
  const CODE_HEAD_FILE = 100;
  const CODE_SUB_FILE = 101;

  protected static function boot()
  {
    parent::boot();
    static::created(function ($model) {
      $model->update([
        "route" => str_replace("_", "-", $model->table_name),
      ]);
    });
  }

  /**
   * Статусы отзывов
   */
  const REVIEW_MODERATION = 1; // На модерации
  const REVIEW_PUBLISHED = 2; // Опубликованный
  const REVIEW_HIDDEN = 3; // Скрытый
}
