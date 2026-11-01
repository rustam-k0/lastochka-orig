<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Models\Assembly;
use App\Services\AnalyticsEvents;
use GraphQL\Error\UserError;

final class ProductScan
{
  /**
   * Фиксация скана товара при сборке (LASTOCHKI-JAMI-11595, #11600).
   *
   * Чистая телеметрия: публикует picking.item_scanned и ничего не меняет
   * в сборке. Сканы могут досылаться офлайн-буфером после завершения
   * сборки — поэтому lookup без global scope NotFinished.
   *
   * @param null $_
   * @param array{assembly_id: int, product_id?: int, assembly_item_id?: int, barcode?: string, method: string, result: string} $args
   */
  public function __invoke($_, array $args)
  {
    $assembly = Assembly::withoutGlobalScopes()->find($args['assembly_id']);

    if (!$assembly) {
      throw new UserError('Сборка не найдена');
    }

    AnalyticsEvents::itemScanned(
      $assembly,
      $args['product_id'] ?? null,
      $args['assembly_item_id'] ?? null,
      $args['method'],
      $args['result'],
      $args['barcode'] ?? null,
    );

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
