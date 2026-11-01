<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Models\AssemblyItem;
use App\Models\ReplacementProduct;
use App\Models\Status;
use App\Models\Type;

final class SetProductsForChange
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $item = AssemblyItem::find($args['id']);


    $replacements_id = [];

    // Выбираем товары для замены
    foreach ($args['replacements'] as $replacement) {
      $replacements_id[] = $item->replacements()->firstOrCreate([
        'product_id' => $replacement,
      ])->id;
    }

    //Удаляем товары, которые могли быть выбраны для замены раннее, но сейчас они не отправились
    $item->replacements()
      ->whereNotIn((new ReplacementProduct())->getTable() . '.id', $replacements_id)
      ->delete();


    // Если до этого, товар был в статусе собрано, ставим статус "Утончить" и меняем на тип "Товар отсутствует"
    if ($item->status_id == Status::PRODUCT_WAIT) {
      $item->update([
        'status_id' => Status::PRODUCT_CLARIFY,
        'type_id' => Type::PRODUCT_NOT_EXISTS
      ]);
    }

    return [
      'code' => 200,
      'message' => 'Товары на замену выбраны'
    ];
  }
}
