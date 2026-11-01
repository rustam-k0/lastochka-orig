<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Код маркировки (Честный знак), отсканированный сборщиком при сборке —
 * один ряд на единицу товара. Пишет delivery_back; здесь модель нужна
 * для транспорта кодов в события заказа и выдачи в админку (OrderItem.marking_codes).
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

  public function orderItem(): BelongsTo
  {
    return $this->belongsTo(OrderItem::class);
  }

  public function product(): BelongsTo
  {
    return $this->belongsTo(Product::class);
  }
}
