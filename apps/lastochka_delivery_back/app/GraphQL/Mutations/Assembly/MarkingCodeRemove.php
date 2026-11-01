<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Models\AssemblyItem;
use App\Models\Order;
use App\Models\Status;

/**
 * Снять ошибочно отсканированный код маркировки с позиции (Честный знак,
 * MID-266). У собранной позиции коды зафиксированы — сначала ProductReturn.
 */
final class MarkingCodeRemove
{
  /**
   * @param null $_
   * @param array{assembly_item_id: int, code: string} $args
   */
  public function __invoke($_, array $args): array
  {
    if (!Order::isHonestSignModuleEnabled()) {
      return MarkingCodeScan::respond(422, MarkingCodeScan::MODULE_DISABLED);
    }

    $item = AssemblyItem::with('orderItem.product')->findOrFail($args['assembly_item_id']);
    $product = $item->orderItem?->product;

    if ((int) $item->status_id === Status::PRODUCT_ASSEMBLED) {
      return MarkingCodeScan::respond(422, MarkingCodeScan::ALREADY_ASSEMBLED, $item, $product);
    }

    $deleted = $item->markingCodes()->where('code', trim($args['code']))->delete();

    return MarkingCodeScan::respond(200, $deleted ? 'OK' : 'Код не найден у позиции', $item, $product);
  }
}
