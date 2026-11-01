<?php

namespace App\GraphQL\Mutations\User;

use App\Models\Shift;
use GraphQL\Error\UserError;

final class ShiftFinish
{
  /**
   * Явное закрытие своей смены из приложения (LASTOCHKI-JAMI-11595, #11599).
   * Публикация shift.ended — внутри Shift::finish().
   *
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    // Global scope NotFinished сам отфильтрует закрытые смены
    $shift = Shift::auth()->first();

    if (!$shift) {
      throw new UserError('У вас нет активной смены');
    }

    $shift->finish();

    return [
      'code' => 200,
      'message' => 'Смена завершена'
    ];
  }
}
