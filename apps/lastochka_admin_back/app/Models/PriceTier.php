<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ступень лесенки «цены от количества» товара в магазине (MID-308).
 *
 * min_quantity и value хранятся в базовых единицах товара (шт, кг, л).
 * Лесенка пары (товар, магазин) всегда заменяется целиком, см.
 * ProductShopPriceService::syncPriceTiers().
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

  protected $casts = [
    'product_id' => 'integer',
    'shop_id' => 'integer',
    'min_quantity' => 'float',
    'value' => 'float',
  ];

  public function product(): BelongsTo
  {
    return $this->belongsTo(Product::class);
  }

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }
}
