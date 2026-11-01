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

  /**
   * Коды для файлов
   */
  const CODE_HEAD_FILE = 100;
  const CODE_SUB_FILE = 101;

  /**
   * Коды заказов
   */
  const ORDER_CREATED = 1;
  const ORDER_PREPARING = 2; // заказ на сборке
  const ORDER_ASSEMBLED = 3; // собрано
  const ORDER_IN_DELIVERY = 4; // в доставке
  const ORDER_FINISHED = 5; // доставлен
  const ORDER_CANCELED = 6; // Отменен
  const ORDER_COOKING = 7; // Готовится
  const ORDER_CANCELLED = 6; // отменен


  /**
   * Коды товаров при сборке
   */
  const PRODUCT_WAIT = 1; // Собрать
  const PRODUCT_ASSEMBLED = 2; // Товар собран
  const PRODUCT_CLARIFY = 3; // Уточнить
  const PRODUCT_CANCELED = 4; // Товар отменен

  /**
   * Статусы позиций заказов
   */
  const ORDER_ITEM_WAITING = 1;
  const ORDER_ITEM_ASSEMBLED = 2;
  const ORDER_ITEM_CANCELED = 3;

  /**
   * Коды заказов в доставке
   */
  const DELIVERY_WAIT_TO_START = 1; // Ожидает начала доставки
  const DELIVERY_IN_PROGRESS = 2; // Доставка в процессе
  const DELIVERY_ARRIVED = 3; // Прибыл на место выдачи
  const DELIVERY_FINISHED = 4; // Доставка завершена
  const DELIVERY_CANCELED = 5; // Доставка отменена курьером


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
