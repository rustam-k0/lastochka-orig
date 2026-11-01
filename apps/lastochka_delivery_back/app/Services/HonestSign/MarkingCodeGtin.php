<?php

namespace App\Services\HonestSign;

use App\Models\Product;

/**
 * Разбор GTIN из кода маркировки и сверка со штрихкодами товара (MID-266).
 *
 * Коды Честного знака в формате GS1 DataMatrix начинаются с идентификатора
 * применения 01 и 14 цифр GTIN (далее 21 — серийный номер и криптохвост).
 * Марки ЕГАИС (алкоголь) такой структуры не имеют — для них сверка
 * пропускается, проверяет только 1С.
 */
final class MarkingCodeGtin
{
  public static function extract(string $cis): ?string
  {
    return preg_match('/^01(\d{14})/', $cis, $m) ? $m[1] : null;
  }

  /**
   * Штрихкоды товара: основной первым, затем остальные из JSON-списка.
   *
   * @return string[]
   */
  public static function productBarcodes(Product $product): array
  {
    $list = $product->barcodes;

    if (is_string($list)) {
      $list = json_decode($list, true);
    }

    $list = is_array($list) ? $list : [];

    if ($product->barcode) {
      array_unshift($list, $product->barcode);
    }

    $normalized = [];
    foreach ($list as $barcode) {
      $barcode = trim((string) $barcode);
      if ($barcode !== '' && !in_array($barcode, $normalized, true)) {
        $normalized[] = $barcode;
      }
    }

    return $normalized;
  }

  /**
   * Штрихкод товара, соответствующий GTIN кода (EAN-13 = GTIN-14 без
   * ведущего нуля, EAN-8/UPC — тоже сравниваются без ведущих нулей).
   *
   * @param string[] $barcodes
   */
  public static function matchingBarcode(string $gtin, array $barcodes): ?string
  {
    $target = ltrim($gtin, '0');

    foreach ($barcodes as $barcode) {
      if (ltrim(preg_replace('/\D/', '', $barcode) ?? '', '0') === $target) {
        return $barcode;
      }
    }

    return null;
  }
}
