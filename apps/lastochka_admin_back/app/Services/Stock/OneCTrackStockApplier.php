<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Models\StockModeChange;
use Illuminate\Support\Facades\Log;

/**
 * Признак учёта остатков из 1С (track_stock, MID-305).
 *
 * true/null → «по категории» (stock_mode = null), false → «не учитывать».
 * Нераспознанное значение («Да», «Y») режим не трогает: опечатка в 1С не
 * должна выключать учёт остатков.
 * Товар с фиксированным лимитом 1С не трогает: да/нет этот режим не выражает.
 * Лимит, унаследованный от категории, «не учитывать» тоже не отменяет.
 * При изменении пишет историю смены режима и пересчитывает эффективный режим
 * (StockOverrideService сам публикует ProductUpdated, если он изменился).
 */
class OneCTrackStockApplier
{
  public function __construct(private readonly StockOverrideService $stockOverride)
  {
  }

  public function apply(Product $product, mixed $trackStock): bool
  {
    if ($trackStock === null) {
      $track = true;
    } else {
      $track = filter_var($trackStock, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

      if ($track === null) {
        Log::warning('Unrecognized track_stock in product event, stock mode kept', [
          'product_id' => $product->id,
          'track_stock' => $trackStock,
        ]);

        return false;
      }
    }

    if ($product->stock_mode === StockMode::FIXED) {
      return false;
    }

    $target = $track ? null : StockMode::IGNORE;
    $old = $product->stock_mode;

    if ((string) $old === (string) $target) {
      return false;
    }

    // «Не учитывать» у товара без собственного режима отменил бы дневной
    // лимит, унаследованный от категории. Эффективный режим читается из БД:
    // привязка витринных категорий (шаг 4b трансформера) пересчитывает его
    // на свежей копии товара, модель в памяти обработчика устаревшая.
    if ($target === StockMode::IGNORE && $old === null
      && Product::query()->whereKey($product->id)->value('effective_stock_mode') === StockMode::FIXED) {
      return false;
    }

    Product::withoutEvents(function () use ($product, $target) {
      $product->forceFill(['stock_mode' => $target])->save();
    });

    StockModeChange::create([
      'entity_type' => 'product',
      'entity_id' => $product->id,
      'old_mode' => $old,
      'new_mode' => $target,
      'user_id' => null,
    ]);

    $this->stockOverride->applyToProduct($product->fresh());

    return true;
  }
}
