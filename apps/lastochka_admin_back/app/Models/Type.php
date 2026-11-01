<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Types
 *
 * @package App\Models
 * @mixin IdeHelperType
 */
class Type extends Model
{
  use ModelTools;

  protected $guarded = [];

  const table = "types";
  protected $table = self::table;

  /**
   * Типы выходных дней
   */
  const HOLIDAY_DELIVERY = 1;
  const HOLIDAY_STORE = 2;
  const HOLIDAY_PICKUP = 3;

  /**
   * Типы квантов
   */
  const UNIT_FOOD = 1;
  const UNIT_PIECE = 2;

  /**
   * Типы цен
   */
  const PRICE_DEFAULT = 1;

  /**
   * Категории атрибутов
   */
  const ATTRIBUTE_COMPOSITION = 100;
  const ATTRIBUTE_DEFAULT = 101;
  const ATTRIBUTE_OTHER = 103;
  const ATTRIBUTE_PACKAGING = 104;

  /**
   * Типы наборов
   */
  const SET_SYSTEM = 1;
  const SET_USER = 2;

  /**
   * Типы атрибутов
   */
  const ATTRIBUTE_STRING = 1;
  const ATTRIBUTE_TEXT = 2;
  const ATTRIBUTE_INT = 3;
  const ATTRIBUTE_FLOAT = 4;
  const ATTRIBUTE_SELECT_ONE = 5;

  /**
   * Типы товарных групп
   */
  const PRODUCT_GROUP_PRODUCTS = 1; // выборка товаров
  const PRODUCT_GROUP_FILTER = 2; // выборка на основе фильтрации

  const MONDAY = 1;
  const TUESDAY = 2;
  const WEDNESDAY = 3;
  const THURSDAY = 4;
  const FRIDAY = 5;
  const SATURDAY = 6;
  const SUNDAY = 7;

  /**
   * Типы времени работы магазинов
   */
  const WORK_SCHEDULE = 1; // Работает по графику
  const WORK_ALL_DAY = 2; // Работает круглосуточно

  /**
   * Типы зон доставки
   */
  const ZONE_SMALL = 1;
  const ZONE_MIDDLE = 2;
  const ZONE_LARGE = 3;

  /**
   * Типы промокодов
   */
  const PROMO_PRODUCTS = 1;
  const PROMO_CATEGORIES = 2;

  /**
   * Типы порядка оплат у магазинов
   */
  const SHOP_PAYMENT_ASSEMBLY = 1; // Списывание оплаты после сборки заказа
  const SHOP_PAYMENT_ORDER_CREATED = 2; // Списывание оплаты после оформления заказа
  const SHOP_PAYMENT_ASSEMBLY_STARTED = 3; // Списывание оплаты после начала сборки
  const SHOP_PAYMENT_ASSEMBLY_DELIVERED = 4; // Списывание оплаты после доставки

  const SHOP_TYPE_STORE = 1; // Магазин
  const SHOP_TYPE_CAFE = 2; // Кафе


  /**
   * Типы оплаты заказов
   */
  const PAYMENT_CARD = 1; // Оплата картой
  const PAYMENT_CASH = 2; // Оплата наличными

  /**
   * Типы доставки заказов (shipping_method)
   */
  const DELIVERY_PICKUP = 1; // Самовывоз
  const DELIVERY_COURIER = 2; // Курьерская доставка
  const DELIVERY_CDEK = 3; // СДЭК
  const DELIVERY_RUSSIAN_POST = 4; // Почта России
  const DELIVERY_YANDEX = 5; // Яндекс Доставка

  // Alias для обратной совместимости
  const RECEIVE_DELIVERY = 1; // = DELIVERY_COURIER

  /**
   * Типы информации о весе товара
   */
  const INFO_WEIGHT_PIECE = 1; //шт
  const INFO_WEIGHT_GRAM = 2; // грамм
  const INFO_WEIGHT_KILOGRAM = 3; // килограмм
  const INFO_WEIGHT_LITER = 4; // литр
  const INFO_WEIGHT_M_LITER = 5; // миллилитр

  const MALE = 1; // Мужчина
  const FEMALE = 2; // женщина
  public static function getWeekDays(): array
  {
    return [
      self::MONDAY,
      self::TUESDAY,
      self::WEDNESDAY,
      self::THURSDAY,
      self::FRIDAY,
      self::SATURDAY,
      self::SUNDAY,
    ];
  }

}
