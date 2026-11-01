<?php

namespace App\GraphQL\Mutations\Shop;

use App\Models\Shop;

final class SetShopWorkingHours
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $shop = Shop::find($args['shop_id']);

    $working_hours_id = [];
    foreach ($args['days'] as $day) {
      $working_hour = $shop->workingHours()->whereDayOfWeek($day)->first()
        ?? $shop->workingHours()->make(['day_of_week' => $day]);

      $working_hour->type_id = $args['type_id'];
      $working_hour->opening_time = $args['opening_time'] ?? null;
      $working_hour->closing_time = $args['closing_time'] ?? null;
      
      $working_hour->save();
      $working_hours_id[] = $working_hour->id;
    }
    $shop->workingHours()->whereNotIn('id', $working_hours_id)->delete();

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
