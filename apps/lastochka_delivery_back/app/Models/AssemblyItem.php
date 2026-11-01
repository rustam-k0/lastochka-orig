<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperAssemblyItem
 */
class AssemblyItem extends Model
{
  use ModelTools, SoftDeletes;

  protected $fillable = [
    'assembly_id',
    'order_item_id',
    'quantity',
    'actual_weight',
    'price',
    'explain_reason_id',
    'cancel_reason_id',
    'replace_reason_id',
    'assembled_at',
    'status_id',
    'type_id',
    'returned_quantity',
    'return_reason_id',
  ];

  public function assembly(): ?BelongsTo
  {
    return $this->belongsTo(Assembly::class);
  }

  public function orderItem(): ?BelongsTo
  {
    return $this->belongsTo(OrderItem::class);
  }

  public function replacements(): ?HasMany
  {
    return $this->hasMany(ReplacementProduct::class);
  }

  /**
   * Коды маркировки (Честный знак, MID-266), отсканированные для позиции.
   */
  public function markingCodes(): HasMany
  {
    return $this->hasMany(AssemblyItemMarkingCode::class);
  }

  /**
   * Сколько кодов маркировки нужно для подтверждения позиции (GraphQL
   * marking_codes_required): 0 — модуль выключен или товар не маркируемый.
   * Товар — выбранная замена либо товар позиции.
   */
  public function getMarkingCodesRequired(): int
  {
    if (!Order::isHonestSignModuleEnabled()) {
      return 0;
    }

    $selected = $this->replacements->first(fn($replacement) => (bool) $replacement->selected);
    $product = $selected?->product ?? $this->orderItem?->product;

    if (!$product) {
      return 0;
    }

    return \App\Services\HonestSign\MarkingRequirement::required(
      $product,
      $this->quantity,
      $this->actual_weight,
      $this->orderItem,
    );
  }

  public function explainReason(): BelongsTo
  {
    return $this->belongsTo(ExplainReason::class);
  }

  public function cancelReason(): BelongsTo
  {
    return $this->belongsTo(ProductCancelReason::class, 'cancel_reason_id');
  }

  public function replaceReason(): BelongsTo
  {
    return $this->belongsTo(ProductReplaceReason::class, 'replace_reason_id');
  }

  public function scopeAuth($query): void
  {
    $query->whereHas('assembly',fn($query) => $query->auth());
  }

  /**
   * Порядок обхода магазина сборщиком (планограмма): по приоритету категории
   * товара для магазина заказа (shops_categories.position, задаётся в админке).
   *
   * Товар часто привязан к нескольким категориям (реальная + таксономия из
   * 1С/eandb). Время/позицию админ задаёт только «реальной» категории, поэтому
   * берём позицию категории с МАКСИМАЛЬНЫМ time_to_take (при равенстве — с
   * наименьшей позицией), а не MIN(position) по всем — иначе товар встаёт по
   * случайной таксономической категории, а не там, где его реально собирают.
   * Товары без категории/приоритета — в конец, внутри группы — по id.
   */
  public function scopeByCategoryPriority($query): void
  {
    $query->orderByRaw('COALESCE((
        SELECT sc.position
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN category_product cp ON cp.product_id = oi.product_id
        JOIN shops_categories sc ON sc.category_id = cp.category_id AND sc.shop_id = o.shop_id
        WHERE oi.id = assembly_items.order_item_id
        ORDER BY sc.time_to_take DESC, sc.position ASC
        LIMIT 1
      ), 999999) asc, assembly_items.id asc');
  }

}
