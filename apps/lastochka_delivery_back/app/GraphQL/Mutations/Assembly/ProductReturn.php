<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Models\AssemblyItem;
use App\Models\Status;
use App\Models\Type;

final class ProductReturn
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {

    $assembly_item = AssemblyItem::find($args['id']);

    $assembly_item->update([
      'quantity' => null,
      'actual_weight' => null,
      'price' => null,
      'explain_reason_id' => null,
      'assembled_at' => null,
      'status_id' => Status::PRODUCT_WAIT,
      'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
    ]);

    $assembly_item
      ->replacements()
      ->delete();

    // Честный знак (MID-266): позиция возвращается в «Собрать» — коды
    // сканируются заново вместе с товаром.
    $assembly_item->markingCodes()->delete();

    return [
      'code' => 200,
      'message' => 'OK'
    ];

  }
}
