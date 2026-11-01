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

  const CODE_LOGO_IMG = 108;
  const CODE_BACKGROUND_IMG = 109;

  /**
   * Коды для файлов
   */
  const CODE_HEAD_FILE = 100;
  const CODE_SUB_FILE = 101;

  /**
   * Статусы для заказов
   */
  const ORDER_CREATED = 1;
  const ORDER_PREPARING = 2; // заказ на сборке
  const ORDER_ASSEMBLED = 3; // собрано
  const ORDER_IN_DELIVERY = 4; // в доставке
  const ORDER_FINISHED = 5; // доставлен
  const ORDER_CANCELED = 6; // Отменен
  const ORDER_CANCELLED = 6; // отменен


  /**
   * Статусы позиций заказов
   */
  const ORDER_ITEM_WAITING = 1;
  const ORDER_ITEM_ASSEMBLED = 2;
  const ORDER_ITEM_CANCELED = 3;

  /**
   * Статусы отзывов
   */
  const REVIEW_MODERATION = 1; // На модерации
  const REVIEW_PUBLISHED = 2; // Опубликованный
  const REVIEW_HIDDEN = 3; // Скрытый

}
