<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperRemain
 */
class Remain extends Model
{
  use HasFactory, ModelTools, SoftDeletes;

  protected $fillable = [
    'product_id',
    'shop_id',
    'quantity',
  ];

  /**
   * Остаток весового товара — килограммы с точностью до грамма (DECIMAL(14,3)).
   * Без каста Eloquent отдаёт строку "0.828", а `(int)` по коду усекал до 0.
   */
  protected $casts = [
    'quantity' => 'float',
  ];

  /**
   * Магазин остатка. Нужен выдаче Remain.shop — таблица «Цены и остатки по
   * магазинам» в карточке товара берёт из неё название магазина для строк,
   * где остаток есть, а строки цены нет (иначе показывается «Магазин #id»).
   */
  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  /**
   * Остаток пары товар+магазин с воскрешением мягко удалённой строки.
   *
   * `updateOrCreate()` здесь не годится: модель soft-delete, а UNIQUE
   * `remains_product_shop_unique` про `deleted_at` не знает. Eloquent
   * удалённую строку не видит, пытается вставить новую и получает «Duplicate
   * entry». Сценарий штатный: товар убрали из магазина (строки помечены
   * удалёнными), потом завели оффер заново.
   */
  public static function upsertQuantity(int $productId, int $shopId, float $quantity): self
  {
    $remain = static::withTrashed()
      ->where('product_id', $productId)
      ->where('shop_id', $shopId)
      ->lockForUpdate()
      ->first();

    if ($remain === null) {
      return static::create([
        'product_id' => $productId,
        'shop_id' => $shopId,
        'quantity' => $quantity,
      ]);
    }

    $remain->deleted_at = null;
    $remain->quantity = $quantity;
    $remain->save();

    return $remain;
  }
}
