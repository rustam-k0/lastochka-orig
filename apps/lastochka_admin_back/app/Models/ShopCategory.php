<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


class ShopCategory extends Model
{

  protected $table = 'shops_categories';

  protected $fillable = [
    'shop_id',
    'category_id',
    'time_to_take',
    'position',
  ];

  protected $touches = ['category'];

  public function category(): BelongsTo
  {
    return $this->belongsTo(Category::class);
  }

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  public function scopeCurrentShop($query, $shop_id = null): void
  {
    if ($shop_id) {
      $query->where('shop_id', $shop_id);
    }
  }

}
