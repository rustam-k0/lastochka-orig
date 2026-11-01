<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


class ShopZone extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'shop_id',
    'type_id',
    'delivery_price',
    'min_order_price',
  ];

  public function coordinates(): HasMany
  {
    return $this->hasMany(ZoneCoordinate::class)->orderBy('position');
  }

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  public function coordinatesExists(): bool
  {
    return $this->coordinates()->exists();
  }


}
