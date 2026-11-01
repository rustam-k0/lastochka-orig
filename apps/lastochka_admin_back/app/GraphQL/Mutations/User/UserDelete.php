<?php

namespace App\GraphQL\Mutations\User;

use App\Models\Role;
use App\Models\User;

final class UserDelete
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $user = User::find($args['id']);

    if ($user->hasAnyRole([Role::COURIER, Role::COLLECTOR])) {

      $result = $this->detachUserOrders($user);

      if (!$result->successful()) {
        return [
          'code' => 500,
          'message' => 'Server error'
        ];
      }
    }

    $user->tokens()->delete();
    $user->shops()->delete();
    $user->delete();

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }

  protected function detachUserOrders(User $user)
  {
    $url = config('app.url') . "/backend-delivery/user/delete";

    $data = [
      'id' => $user->id,
      'token' => config('services.system.token')
    ];

    return \Http::asJson()->post($url, $data);
  }
}
