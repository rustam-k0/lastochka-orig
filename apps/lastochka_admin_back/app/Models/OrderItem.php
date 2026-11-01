<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperOrderItem
 */
class OrderItem extends Model
{
  use ModelTools, SoftDeletes;

  protected $fillable = [
    'order_id',
    'product_id',
    'offer_id',
    'meta',
    'quantity',
    'price',
    'status_id',
    'is_eligible',
    'price_during_order',
    'price_old',
    // Снапшот лесенки «цены от количества» (MID-308), см. миграцию колонки.
    'price_tiers',
    'origin_product_id',
    'origin_quantity',
    'origin_price',
    'origin_title',
    'is_supplemented',
    'actual_weight',
    'cancel_reason_id',
    'returned_quantity',
    'return_reason_id',
    'parent_id',
    'source_supplement_value_id',
  ];

  protected $casts = [
    'meta' => 'array',
    'price_tiers' => 'array',
  ];

  protected static function boot()
  {
    parent::boot();
    static::creating(function ($model){
      $model->status_id = Status::ORDER_ITEM_WAITING;
    });
  }

  public function product(): BelongsTo
  {
    return $this->belongsTo(Product::class);
  }

  public function offer(): BelongsTo
  {
    return $this->belongsTo(ProductOffer::class, 'offer_id');
  }

  public function originProduct(): BelongsTo
  {
    return $this->belongsTo(Product::class, 'origin_product_id');
  }

  public function supplements()
  {
    return $this->hasMany(OrderItemSupplement::class);
  }

  /**
   * Коды маркировки (Честный знак, MID-266), отсканированные сборщиком.
   * Пишет delivery_back, здесь только чтение для событий заказа.
   */
  public function markingCodes(): HasMany
  {
    return $this->hasMany(AssemblyItemMarkingCode::class, 'order_item_id');
  }

  public function cancelReason(): BelongsTo
  {
    return $this->belongsTo(ProductCancelReason::class, 'cancel_reason_id');
  }

  public function parent(): BelongsTo
  {
    return $this->belongsTo(OrderItem::class, 'parent_id');
  }

  public function childItems(): HasMany
  {
    return $this->hasMany(OrderItem::class, 'parent_id');
  }
}
