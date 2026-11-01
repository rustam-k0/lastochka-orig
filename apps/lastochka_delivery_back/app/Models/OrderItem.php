<?php

namespace App\Models;

use App\GraphQL\Mutations\Order\IdeHelperOrderItem;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Product;
use App\Services\Pricing\OrderItemTierPricing;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperOrderItem
 */
class OrderItem extends Model
{
  use ModelTools, SoftDeletes;

  protected $fillable = [
    'status_id',
    'quantity',
    // Заказанные порции до пересчёта по факту сборки (AssemblyFinish)
    'ordered_quantity',
    'price',
    'price_during_order',
    // Зачёркнутая цена за единицу и снапшот лесенки «цены от количества» (MID-308)
    'price_old',
    'price_tiers',
    'product_id',
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

  public function product(): ?BelongsTo
  {
    return $this->belongsTo(Product::class);
  }

  public function cancelReason(): BelongsTo
  {
    return $this->belongsTo(ProductCancelReason::class, 'cancel_reason_id');
  }

  public function returnReason(): BelongsTo
  {
    return $this->belongsTo(ProductCancelReason::class, 'return_reason_id');
  }

  public function replaceReason(): BelongsTo
  {
    return $this->belongsTo(ProductReplaceReason::class, 'replace_reason_id');
  }

  public function order(): ?BelongsTo
  {
    return $this->belongsTo(Order::class);
  }

  /**
   * Коды маркировки (Честный знак, MID-266) позиции — для событий заказа.
   */
  public function markingCodes(): HasMany
  {
    return $this->hasMany(AssemblyItemMarkingCode::class, 'order_item_id');
  }

  public function assemblyItem(): HasOne
  {
    return $this->hasOne(AssemblyItem::class);
  }

  public function originProduct(): BelongsTo
  {
    return $this->belongsTo(Product::class, 'origin_product_id');
  }

  public function supplements(): HasMany
  {
    return $this->hasMany(OrderItemSupplement::class);
  }

  public function parent(): BelongsTo
  {
    return $this->belongsTo(OrderItem::class, 'parent_id');
  }

  public function childItems(): HasMany
  {
    return $this->hasMany(OrderItem::class, 'parent_id');
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

}
