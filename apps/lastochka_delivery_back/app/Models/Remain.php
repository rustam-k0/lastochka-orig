<?php

namespace App\Models;

use App\Services\ShopService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperRemain
 */
class Remain extends Model
{
  use SoftDeletes;
  
  protected $fillable = ['quantity'];

  /**
   * Остаток весового товара — килограммы с точностью до грамма (DECIMAL(14,3)).
   * Без каста Eloquent отдаёт строку "0.828", а `(int)` по коду усекал до 0.
   */
  protected $casts = [
    'quantity' => 'float',
  ];

  protected static function booted(): void
  {
    static::addGlobalScope('onlyAuth', function ($builder) {
      $builder->currentShop();
    });
  }

  public function scopeCurrentShop($query)
  {
    return ShopService::bindShopQuery($query);
  }

  public function shop()
  {
    return $this->belongsTo(Shop::class);
  }

}
