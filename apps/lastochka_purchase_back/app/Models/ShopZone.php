<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperShopZone
 */
class ShopZone extends Model
{
  use HasFactory, SoftDeletes;

  protected $fillable = [
    'shop_id',
    'delivery_price',
    'min_order_price',
    'free_delivery_from',
    'delivery_days',
    'delivery_lead_minutes',
  ];

  protected $casts = [
    'delivery_days' => 'array',
    'delivery_lead_minutes' => 'integer',
    // MID-280: ступени «от суммы товаров → доставка», ведёт admin_back, отдаёт slots-api
    'delivery_price_ranges' => 'array',
  ];

  public function coordinates(): HasMany
  {
    return $this->hasMany(ZoneCoordinate::class)->orderBy('position');
  }

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }
}
