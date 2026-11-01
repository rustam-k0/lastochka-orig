<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Models\AssemblyItem;
use App\Models\Status;
use App\Models\Type;

final class ProductClarify
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    // Обе причины опциональны: explain_reason_id (справочник explain_reasons,
    // «причины уточнения») и cancel_reason_id (справочник product_cancel_reasons,
    // тот же, что в ProductCancel — «Причины отмены» из админки). Пишем то, что
    // прислал клиент; отсутствующий ключ → null, чтобы не словить undefined-key.
    AssemblyItem::whereId($args['id'])->update([
      'status_id' => Status::PRODUCT_CLARIFY,
      'type_id' => Type::PRODUCT_CLARIFY,
      'explain_reason_id' => $args['explain_reason_id'] ?? null,
      'cancel_reason_id' => $args['cancel_reason_id'] ?? null,
    ]);

    return [
      'code' => 200,
      'message' => 'Товар отправлен на уточнение'
    ];
  }
}
