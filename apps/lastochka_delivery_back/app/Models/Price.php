<?php

namespace App\Models;

use App\Services\ShopService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperPrice
 */
class Price extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'type_id',
    'value',
    'shop_id',
    'step'
  ];

  protected static function booted(): void
  {
//    static::addGlobalScope('onlyAuth', function ($builder) {
//      $builder->currentShop();
//    });
  }

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  public function scopeCurrentShop($query)
  {
    return ShopService::bindShopQuery($query);
  }

}
