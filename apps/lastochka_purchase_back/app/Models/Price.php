<?php

namespace App\Models;

use App\Services\ShopService;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperPrice
 */
class Price extends Model
{
  use HasFactory, ModelTools,SoftDeletes;
  
  protected $fillable = [
    'product_id',
    'shop_id',
    'price',
    'price_without_discount',
    'type_id'
  ];

  public function shop(): ?BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  public function product(): BelongsTo
  {
    return $this->belongsTo(Product::class);
  }

  public function scopeCurrentShop($query)
  {
    return ShopService::bindShopQuery($query);
  }

  public function withDiscount(): bool
  {
    return (bool)$this->price_without_discount;
  }
}
