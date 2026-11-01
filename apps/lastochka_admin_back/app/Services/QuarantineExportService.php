<?php

namespace App\Services;

use App\Models\AggregatorQuarantine;
use App\Models\OneCProblemProduct;
use Illuminate\Support\Collection;

/**
 * Экспорт страницы «Карантин товаров» в таблицу (FOODLINK-11194).
 *
 * Режимы зеркалят дропдаун причины на странице + фильтр «Магазин» (FOODLINK-11191):
 *  - __all__ / double_match — карантин агрегатора (aggregator_quarantine, неразрешённые);
 *  - no_barcode — объединённый список: карантин no_ean + 1С missing_barcode;
 *  - missing_* — проблемные товары 1С (onec_problem_products).
 */
class QuarantineExportService
{
  public const CODE_ALL = '__all__';
  public const CODE_NO_BARCODE = 'no_barcode';
  public const CODE_MISSING_BARCODE = 'missing_barcode';

  public const ONE_C_CODES = [
    'missing_sku',
    'missing_price',
    'missing_stock',
    'missing_image',
    'missing_name',
    'missing_unit',
  ];

  public const HEADERS = ['ID', 'Название', 'Магазин', 'Артикул', 'Штрихкод'];

  /** Допустимые значения параметра code (= значения дропдауна причины на странице). */
  public static function allowedCodes(): array
  {
    return array_merge(
      [self::CODE_ALL, AggregatorQuarantine::REASON_DOUBLE_MATCH, self::CODE_NO_BARCODE],
      self::ONE_C_CODES,
    );
  }

  /**
   * Строки экспорта: [id, название, магазин, артикул, штрихкод].
   * Все значения строками — чтобы Excel не превращал штрихкоды в экспоненту.
   */
  public function rows(string $code, ?int $shopId): Collection
  {
    if ($code === self::CODE_NO_BARCODE) {
      return $this->aggregatorRows(AggregatorQuarantine::REASON_NO_EAN, $shopId)
        ->concat($this->oneCRows(self::CODE_MISSING_BARCODE, $shopId));
    }

    if ($code === self::CODE_ALL || $code === AggregatorQuarantine::REASON_DOUBLE_MATCH) {
      return $this->aggregatorRows($code === self::CODE_ALL ? null : $code, $shopId);
    }

    return $this->oneCRows($code, $shopId);
  }

  /** Карантин агрегатора: ID = id товара курьерки, артикул/штрихкод — из карточки товара. */
  private function aggregatorRows(?string $reason, ?int $shopId): Collection
  {
    return AggregatorQuarantine::query()
      ->resolvedFilter(false)
      ->reason($reason)
      ->filterByShop($shopId)
      ->with(['shop', 'localProduct'])
      ->orderByDesc('created_at')
      ->get()
      ->map(function (AggregatorQuarantine $row) {
        $product = $row->localProduct;
        $barcode = $product?->barcode ?: implode(', ', $product?->barcodes ?? []);

        return [
          (string) ($row->local_product_id ?? ''),
          (string) ($row->incoming_title ?? ''),
          (string) ($row->shop_title ?? ''),
          (string) ($product?->articul ?? ''),
          $barcode,
        ];
      });
  }

  /** Проблемные товары 1С: ID = external_id (GUID товара в 1С). */
  private function oneCRows(string $code, ?int $shopId): Collection
  {
    return OneCProblemProduct::query()
      ->where('code', $code)
      ->filterByShop($shopId)
      ->with('shop')
      ->orderByDesc('created_at')
      ->get()
      ->map(fn (OneCProblemProduct $row) => [
        (string) ($row->external_id ?? ''),
        (string) ($row->name ?? ''),
        (string) ($row->shop_title ?? ''),
        (string) ($row->sku ?? ''),
        (string) ($row->barcode ?? ''),
      ]);
  }
}
