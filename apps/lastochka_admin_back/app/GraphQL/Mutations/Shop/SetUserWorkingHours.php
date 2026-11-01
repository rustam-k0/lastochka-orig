<?php

namespace App\GraphQL\Mutations\Shop;

use App\Models\UserShop;

final class SetUserWorkingHours
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $user_shop = UserShop::find($args['user_shop_id']);

    $working_hours_id = [];
    foreach ($args['days'] as $day) {
      $working_hour = $user_shop->workingHours()->whereDayOfWeek($day)->first()
        ?? $user_shop->workingHours()->make(['day_of_week' => $day]);

      $working_hour->opening_time = $args['opening_time'] ?? null;
      $working_hour->closing_time = $args['closing_time'] ?? null;

      $working_hour->save();
      $working_hours_id[] = $working_hour->id;
    }
    $user_shop->workingHours()->whereNotIn('id', $working_hours_id)->delete();

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
