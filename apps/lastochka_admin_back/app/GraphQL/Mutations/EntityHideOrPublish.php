<?php

namespace App\GraphQL\Mutations;

use App\Models\Shop;
use App\Models\Status;

final class EntityHideOrPublish
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $item = \DB::table($args['table'])->find($args['id']);

    $status_id = $item->status_id == Status::CODE_ACTIVE
      ? Status::CODE_NOT_PUBLISHED
      : Status::CODE_ACTIVE;

    \DB::table($args['table'])->where('id', $item->id)
      ->update([
        'status_id' => $status_id
      ]);

    // Если это таблица shops, запускаем событие обновления через модель
    if ($args['table'] === 'shops') {
      $shop = Shop::find($item->id);
      
      if ($shop) {
        // Принудительно обновляем timestamp для срабатывания события
        $shop->touch();
      }
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
