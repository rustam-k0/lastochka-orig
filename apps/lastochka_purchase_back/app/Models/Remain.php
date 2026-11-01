<?php

namespace App\Models;

use App\Services\ShopService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
   * RemainExistsRule переводит кг → граммы (×1000), без каста строка "0.828"
   * работала бы через числовое приведение, каст делает тип явным.
   */
  protected $casts = [
    'quantity' => 'float',
  ];

  public function shop(): ?BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  public function scopeCurrentShop($query)
  {
    return ShopService::bindShopQuery($query);
  }
}
