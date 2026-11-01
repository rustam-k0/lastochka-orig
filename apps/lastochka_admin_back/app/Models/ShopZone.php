<?php

namespace App\Models;

use App\Services\Pricing\DeliveryPriceLadder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


class ShopZone extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'shop_id',
    'name',
    'delivery_price',
    'min_order_price',
    'free_delivery_from',
    'delivery_price_ranges',
    'delivery_type',
    'delivery_days',
    'delivery_lead_minutes',
    'is_active',
    'assembly_price',
  ];

  protected $casts = [
    'delivery_days' => 'array',
    'delivery_lead_minutes' => 'integer',
    'is_active' => 'boolean',
    'assembly_price' => 'integer',
    'delivery_price_ranges' => 'array',
  ];

  public function coordinates(): HasMany
  {
    return $this->hasMany(ZoneCoordinate::class)->orderBy('position');
  }

  public function coordinatesExists(): bool
  {
    return $this->coordinates()->exists();
  }

  /**
   * Ступени стоимости доставки для API и событий (MID-280): по возрастанию
   * from_amount, [] — ступеней нет (NULL в колонке читается так же).
   *
   * @return array<int, array{from_amount: int, price: int}>
   */
  public function deliveryPriceRangesList(): array
  {
    return DeliveryPriceLadder::normalize($this->delivery_price_ranges) ?? [];
  }


}
