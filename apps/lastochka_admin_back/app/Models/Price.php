<?php

namespace App\Models;

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

  /**
   * product_id и shop_id обязаны быть fillable: три мутации вариативных
   * товаров (ConfigurableProductAddVariant / UpdateVariant / SyncVariants)
   * зовут статический Price::upsertForShop($productId, $shopId, …),
   * а у статического вызова, в отличие от вызова через relation, некому
   * подставить внешний ключ — mass assignment отбрасывал product_id и вставка
   * падала с «Field 'product_id' doesn't have a default value» (500).
   */
  protected $fillable = [
    'blocked',
    'value',
    'type_id',
    'step',
    'product_id',
    'shop_id',
  ];
  public function shop(): ?BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  /**
   * Строка цены пары товар+магазин под блокировкой, включая мягко удалённую.
   *
   * Модель soft-delete, а UNIQUE `prices_product_shop_type_unique` про
   * `deleted_at` не знает: обычный запрос удалённую строку не видит, код идёт
   * создавать новую и получает «Duplicate entry». Сценарий штатный — товар
   * убрали из магазина, потом завели оффер заново.
   */
  public static function findForUpsert(int $productId, ?int $shopId, int $typeId): ?self
  {
    return static::withTrashed()
      ->where('product_id', $productId)
      ->where('shop_id', $shopId)
      ->where('type_id', $typeId)
      ->lockForUpdate()
      ->first();
  }

  /**
   * Записать значения в строку, снимая мягкое удаление, если оно было.
   */
  public function reviveWith(array $attributes): self
  {
    $this->deleted_at = null;
    $this->fill($attributes)->save();

    return $this;
  }

  /**
   * Замена `updateOrCreate()` для пары товар+магазин: видит мягко удалённую
   * строку и воскрешает её вместо падения на UNIQUE-индексе.
   */
  public static function upsertForShop(int $productId, ?int $shopId, int $typeId, array $attributes): self
  {
    $price = static::findForUpsert($productId, $shopId, $typeId);

    if ($price !== null) {
      return $price->reviveWith($attributes);
    }

    return static::create($attributes + [
      'product_id' => $productId,
      'shop_id' => $shopId,
      'type_id' => $typeId,
    ]);
  }
}
