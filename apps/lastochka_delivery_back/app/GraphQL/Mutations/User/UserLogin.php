<?php

namespace App\GraphQL\Mutations\User;

use App\Models\User;

final class UserLogin
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $user = User::with('shift')->firstWhere('phone',$args['phone']);

    $token = $user->createToken(config("app.name"))->plainTextToken;

    return [
      "code" => 200,
      "message" => "You're logged in!",
      "token" => $token,
      "user" => $user,
    ];

  }
}
