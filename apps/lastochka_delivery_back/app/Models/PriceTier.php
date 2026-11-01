<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ступень цены товара в магазине (цены от количества, MID-308).
 *
 * Таблица price_tiers общей БД принадлежит admin_back: миграция и запись только
 * там. Здесь модель для чтения. min_quantity и value лежат в БАЗОВЫХ единицах
 * товара (шт, кг, л); в порции их переводит OrderItemTierPricing::freshSnapshot().
 * shop_id всегда заполнен: в сетевом каталоге магазин цены, иначе магазин товара.
 */
class PriceTier extends Model
{
  protected $table = 'price_tiers';

  protected $fillable = [
    'product_id',
    'shop_id',
    'min_quantity',
    'value',
  ];

  // Laravel 9: метод casts() не вызывается, только свойство
  protected $casts = [
    'min_quantity' => 'float',
    'value' => 'float',
  ];
}
