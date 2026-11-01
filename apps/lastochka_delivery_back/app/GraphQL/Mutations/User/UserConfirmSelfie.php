<?php

namespace App\GraphQL\Mutations\User;

use App\Models\UserWorkingHour;

final class UserConfirmSelfie
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {

    $shop = \Auth::user()->shops()->first();

    $shift = \Auth::user()->shift()->create([
      'shop_id' => $shop->id,
    ]);

    $shift->saveImg($args['img']);

    // Аналитика: старт рабочей сессии сотрудника (LASTOCHKI-JAMI-11659, B2E #11599)
    \App\Services\AnalyticsEvents::shiftStarted($shift);

    return [
      'code' => 200,
      'message' => 'Смена началась'
    ];


  }
}
