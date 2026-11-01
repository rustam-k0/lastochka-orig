<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\DefaultAttribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Type;
use Illuminate\Support\Facades\DB;

/**
 * Расчёт % заполненности карточки товара (LASTOCHKI-JAMI-11653).
 *
 * Веса полей — config/fill-percent.php, веса атрибутов — default_attributes.
 * Процент нормализуется: round(100 * набрано / максимум), полностью
 * заполненный товар = 100% независимо от состава весов.
 *
 * В отличие от легаси ЗЯ: никакой авто-(де)публикации — только индикатор.
 */
class ProductFillPercentService
{
  /** Сумма весов атрибутов; кэш на инстанс (важно для бэкфила). */
  private ?int $attributesMax = null;

  public function calculate(Product $product): int
  {
    $weights = config('fill-percent.fields');

    $earned = $weights['base'];
    $earned += $product->barcode ? $weights['barcode'] : 0;
    $earned += $product->articul ? $weights['articul'] : 0;
    $earned += $product->description ? $weights['description'] : 0;
    $earned += ($product->meta_title && $product->meta_description && $product->meta_keywords)
      ? $weights['meta'] : 0;
    $earned += $product->img ? $weights['img'] : 0;
    $earned += $product->categories->isNotEmpty() ? $weights['category'] : 0;
    $earned += $product->prices->contains(fn ($price) => (float) $price->value > 0)
      ? $weights['price'] : 0;
    $earned += $this->attributesEarned($product);

    $max = array_sum($weights) + $this->attributesMax();

    return (int) round(100 * $earned / max(1, $max));
  }

  /**
   * Пересчитать и сохранить fill_percent.
   *
   * Запись — прямым DB-update: без Eloquent-событий (не дёргаем outbox)
   * и без touch updated_at (не сдвигаем event_timestamp синхронизации).
   *
   * @param bool $refresh true — перечитать товар со связями из БД (актуально
   *   после мутаций/консьюмера, где загруженные связи могли устареть);
   *   false — считать по уже загруженным связям (бэкфил с eager loading).
   */
  public function recalculate(Product $product, bool $refresh = true): void
  {
    if ($refresh) {
      $fresh = $product->fresh(['img', 'categories', 'prices', 'attributes.attribute.defaultAttribute']);
      if (!$fresh) {
        return;
      }
      $product = $fresh;
    }

    $percent = $this->calculate($product);

    if ((int) $product->fill_percent === $percent) {
      return;
    }

    DB::table($product->getTable())
      ->where('id', $product->id)
      ->update(['fill_percent' => $percent]);
  }

  private function attributesEarned(Product $product): int
  {
    $earned = 0;

    foreach ($product->attributes as $productAttribute) {
      $weight = (int) ($productAttribute->attribute?->defaultAttribute?->fill_percent ?? 0);
      if ($weight <= 0) {
        continue;
      }
      if ($this->isAttributeFilled($productAttribute)) {
        $earned += $weight;
      }
    }

    return $earned;
  }

  /**
   * Заполнен ли атрибут: непустое значение в колонке, соответствующей типу.
   * В отличие от ЗЯ обрабатываем SELECT_ONE (attribute_value_id) и не
   * падаем на неизвестных типах.
   */
  private function isAttributeFilled(ProductAttribute $productAttribute): bool
  {
    $column = match ((int) $productAttribute->attribute->type_id) {
      Type::ATTRIBUTE_STRING => 'string_value',
      Type::ATTRIBUTE_TEXT => 'text_value',
      Type::ATTRIBUTE_INT => 'int_value',
      Type::ATTRIBUTE_FLOAT => 'float_value',
      Type::ATTRIBUTE_SELECT_ONE => 'attribute_value_id',
      default => null,
    };

    if ($column === null) {
      return false;
    }

    $value = $productAttribute->{$column};

    return $value !== null && $value !== '';
  }

  /** Сумма весов живых (не soft-deleted) атрибутов. */
  private function attributesMax(): int
  {
    return $this->attributesMax ??= (int) DefaultAttribute::query()
      ->whereIn('attribute_id', Attribute::query()->select('id'))
      ->sum('fill_percent');
  }
}
