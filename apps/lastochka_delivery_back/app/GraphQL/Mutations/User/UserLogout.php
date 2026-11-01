<?php

namespace App\GraphQL\Mutations\User;

final class UserLogout
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {

    \Auth::user()->currentAccessToken()->delete();

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }
}
