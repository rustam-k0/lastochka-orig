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
   * Типы квантов
   */
  const UNIT_GRAM = 1; // граммы
  const UNIT_PIECE = 2; // штуки

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
   * Типы цен
   */
  const PRICE_DEFAULT = 1;

  /**
   * Категории атрибутов
   */
  const ATTRIBUTE_COMPOSITION = 100;
  const ATTRIBUTE_DEFAULT = 101;
  const ATTRIBUTE_OTHER = 103;

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

  /**
   * Типы товарных групп
   */
  const PRODUCT_GROUP_PRODUCTS = 1; // выборка товаров
  const PRODUCT_GROUP_FILTER = 2; // выборка на основе фильтрации

  /**
   * Типы информации о весе товара
   */
  const INFO_WEIGHT_PIECE = 1; //шт
  const INFO_WEIGHT_GRAM = 2; // грамм
  const INFO_WEIGHT_KILOGRAM = 3; // килограмм
  const INFO_WEIGHT_LITER = 4; // литр
  const INFO_WEIGHT_M_LITER = 5; // миллилитр
}
