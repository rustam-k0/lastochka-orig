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
   * Типы цен
   */
  const PRICE_DEFAULT = 1;

  /**
   * Типы получения
   */
  const RECEIVE_PICKUP = 1; // Самовывоз
  const RECEIVE_DELIVERY = 2; // Доставка

  /**
   * Типы квантов
   */
  const UNIT_GRAM = 1; // граммы
  const UNIT_PIECE = 2; // штуки

  /**
   * Типы информации о весе товара (products.info_weight_type_id,
   * products.base_unit_type_id). Не справочник в БД, а фиксированный контракт
   * платформы: те же значения в admin_back и delivery_back Type::INFO_WEIGHT_*.
   */
  const INFO_WEIGHT_PIECE = 1; // шт
  const INFO_WEIGHT_GRAM = 2; // грамм
  const INFO_WEIGHT_KILOGRAM = 3; // килограмм
  const INFO_WEIGHT_LITER = 4; // литр
  const INFO_WEIGHT_M_LITER = 5; // миллилитр

  /**
   * Типы оплат
   */
  const PAYMENT_ONLINE = 1;
  const PAYMENT_OFFLINE = 2;


  /**
   * Типы, что делать сборщику если товара нет
   */
  const ON_CHANGE_CALL = 1;
  const ON_CHANGE_CHANGE = 2;
  const ON_CHANGE_CANCEL = 3;

  /**
   * Типы времени работы магазинов
   */
  const WORK_SCHEDULE = 1; // Работает по графику
  const WORK_ALL_DAY = 2; // Работает круглосуточно

  /**
   * Типы промокодов
   */
  const PROMO_PRODUCTS = 1;
  const PROMO_CATEGORIES = 2;


  /**
   * Типы партнеров
   */
  const SHOP_TYPE_STORE = 1; // Магазин
  const SHOP_TYPE_CAFE = 2; // Кафе


  /**
   * Типы оплаты заказов.
   * Канон платформы = admin_back/admin_front/мобилка: 1=карта, 2=наличные
   * (admin_back перевёрнут коммитом «fix payment type» 2026-01-24; здесь
   * зеркалим — иначе исходящие order.updated несут инвертированный
   * payment_type и admin_back-консьюмер пинг-понгом портит payment_type_id).
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
}
