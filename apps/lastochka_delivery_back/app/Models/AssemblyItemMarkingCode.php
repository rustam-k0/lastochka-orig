<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Код маркировки (Честный знак, MID-266), отсканированный сборщиком —
 * один ряд на единицу товара. Привязан к позиции сборки и к товару,
 * которому принадлежит код (товар позиции либо товар-замена).
 */
class AssemblyItemMarkingCode extends Model
{
  protected $table = 'assembly_item_marking_codes';

  protected $fillable = [
    'assembly_item_id',
    'order_item_id',
    'product_id',
    'code',
    'barcode',
    'verified_at',
    'verify_message',
  ];

  protected $casts = [
    'verified_at' => 'datetime',
  ];

  public function assemblyItem(): BelongsTo
  {
    return $this->belongsTo(AssemblyItem::class);
  }

  public function orderItem(): BelongsTo
  {
    return $this->belongsTo(OrderItem::class);
  }

  public function product(): BelongsTo
  {
    return $this->belongsTo(Product::class);
  }

  /**
   * Коды, занятые живыми заказами: позиция сборки не удалена, заказ не
   * отменён. Код отменённого заказа можно отсканировать снова.
   *
   * Join вместо whereHas: у Assembly глобальный scope NotFinished, а коды
   * завершённых (доставленных) заказов заняты навсегда.
   */
  public function scopeOccupied(Builder $query): Builder
  {
    return $query
      ->join('assembly_items', 'assembly_items.id', '=', 'assembly_item_marking_codes.assembly_item_id')
      ->join('assemblies', 'assemblies.id', '=', 'assembly_items.assembly_id')
      ->join('orders', 'orders.id', '=', 'assemblies.order_id')
      ->whereNull('assembly_items.deleted_at')
      ->where('orders.status_id', '!=', Status::ORDER_CANCELLED)
      ->select('assembly_item_marking_codes.*', 'orders.id as occupied_order_id', 'orders.uuid as occupied_order_uuid');
  }
}
