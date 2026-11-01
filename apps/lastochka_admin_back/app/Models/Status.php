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
  const CODE_STORY_THUMBNAIL = 110;
  const CODE_STORY_SLIDER = 111;
  const CODE_MERCHANT_SALE_IMG = 112;
  const CODE_MERCHANT_PRODUCT_GROUP_IMG = 113;
  const CODE_MERCHANT_BANNER_IMG = 114;
  const CODE_ANNOUNCEMENT_IMG = 115;
  const CODE_BRAND_LOGO = 116;

  /**
   * Коды для файлов
   */
  const CODE_HEAD_FILE = 100;
  const CODE_SUB_FILE = 101;

  /**
   * Статусы позиций заказов
   */
  const ORDER_ITEM_WAITING = 1;
  const ORDER_ITEM_ASSEMBLED = 2;
  const ORDER_ITEM_CANCELED = 3;

  /**
   * Статусы для заказов
   */
  const ORDER_CREATED = 1;
  const ORDER_PREPARING = 2; // заказ на сборке
  const ORDER_ASSEMBLED = 3; // собрано
  const ORDER_IN_DELIVERY = 4; // в доставке
  const ORDER_FINISHED = 5; // доставлен
  const ORDER_CANCELLED = 6; // отменен

  /**
   * Статусы назначений на доставку (delivery_orders.status_id) —
   * зеркало констант delivery_back (App\Models\Status), общая таблица.
   */
  const DELIVERY_WAIT_TO_START = 1; // Ожидает начала доставки
  const DELIVERY_IN_PROGRESS = 2; // Доставка в процессе
  const DELIVERY_ARRIVED = 3; // Прибыл на место выдачи
  const DELIVERY_FINISHED = 4; // Доставка завершена
  const DELIVERY_CANCELED = 5; // Доставка отменена

  protected static function boot()
  {
    parent::boot();
    static::created(function ($model) {
      $model->update([
        "route" => str_replace("_", "-", $model->table_name),
      ]);
    });
  }
}
