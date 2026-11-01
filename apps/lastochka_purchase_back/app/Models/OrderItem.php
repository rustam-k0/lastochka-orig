<?php

namespace App\Models;

use App\Services\Pricing\OrderItemTierPricing;
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
    'quantity',
    'ordered_quantity',
    'price',
    'status_id',
    'price_during_order',
    'price_old',
    // Снапшот лесенки «цены от количества» и признак «промокод действует» (MID-308)
    'price_tiers',
    'is_eligible',
    'origin_product_id',
    'origin_title',
    'origin_quantity',
    'origin_price',
    'is_supplemented',
    'actual_weight',
    'cancel_reason_id',
    'replace_reason_id',
    'returned_quantity',
    'return_reason_id',
    'parent_id',
    'source_supplement_value_id',
  ];

  /**
   * Снапшот лесенки (MID-308): {base_price, base_price_old, base_is_eligible, tiers[]},
   * всё в порциях и ценах за порцию. null = у позиции нет лесенки, формулы прежние.
   * Laravel 9: метод casts() не вызывается, только свойство.
   */
  protected $casts = [
    'price_tiers' => 'array',
  ];

  protected static function boot()
  {
    parent::boot();
    static::creating(function ($model){
      $model->status_id = Status::ORDER_ITEM_WAITING;
    });
  }

  /**
   * Возвращает items заказа и всех его дочерних подзаказов
   */
  public function scopeForOrder($query, array $args): void
  {
    $orderId = (int) $args['order_id'];
    $childIds = Order::where('parent_order_id', $orderId)->pluck('id');
    $allOrderIds = $childIds->prepend($orderId);
    $query->whereIn('order_id', $allOrderIds);
  }

  public function product(): BelongsTo
  {
    return $this->belongsTo(Product::class);
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
   * Пишет delivery_back, здесь чтение: события заказа и карточка в админке.
   */
  public function markingCodes(): HasMany
  {
    return $this->hasMany(AssemblyItemMarkingCode::class, 'order_item_id');
  }

  /**
   * Коды маркировки текущего товара позиции для GraphQL (OrderItem.marking_codes).
   *
   * @return string[]
   */
  public function getMarkingCodesList(): array
  {
    return $this->markingCodes()
      ->where('product_id', $this->product_id)
      ->orderBy('id')
      ->pluck('code')
      ->all();
  }

  /**
   * Снапшот лесенки для GraphQL (OrderItem.price_tiers, MID-308): нормализованный
   * массив; пустышка «снапшот снят» отдаётся как есть (tiers: []); null, когда
   * снапшота нет или он битый. От флага модуля не зависит.
   */
  public function resolvePriceTiers(): ?array
  {
    return OrderItemTierPricing::exposedSnapshot($this);
  }

  public function cancelReason(): BelongsTo
  {
    return $this->belongsTo(ProductCancelReason::class, 'cancel_reason_id');
  }

  public function replaceReason(): BelongsTo
  {
    return $this->belongsTo(ProductReplaceReason::class, 'replace_reason_id');
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
