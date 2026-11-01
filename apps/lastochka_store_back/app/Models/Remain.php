<?php

namespace App\Models;

use App\Services\ShopService;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


class Remain extends Model
{
  use HasFactory, ModelTools, SoftDeletes;

  /**
   * The "booted" method of the model.
   *
   * @return void
   */
  protected static function booted()
  {
    static::addGlobalScope('currentShop', function ($builder) {
      $builder->currentShop();
    });
  }

  public function shop(): ?BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  public function scopeCurrentShop($query)
  {
    return ShopService::bindShopQuery($query);
  }
}
