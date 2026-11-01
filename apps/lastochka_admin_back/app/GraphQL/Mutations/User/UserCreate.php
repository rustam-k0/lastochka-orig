<?php

namespace App\GraphQL\Mutations\User;

use App\Models\User;
use App\Services\SmsAero;
use App\Support\PartnerStaffAccess;
use Illuminate\Support\Facades\Log;

final class UserCreate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    // Партнёр (shop-scope) заводит сотрудника только в свои магазины —
    // привязка форсится из user_shops, что бы фронт ни прислал.
    $authUser = \Auth::user();
    if (PartnerStaffAccess::isPartner($authUser)) {
      $args['shops'] = PartnerStaffAccess::ownShopIds($authUser);
    }

    $password = $args['password'];
    $args['password'] = bcrypt($password);

    $sendCredentials = $args['send_credentials'] ?? false;

    \DB::transaction(function () use ($args, $password, $sendCredentials) {
      $user = User::create($args);

      if ($sendCredentials) {
        $user->sendCredentials($password);
      }

      Log::info("User {$user->id} created by admin " . \Auth::id());

      $user->assignRole($args['role_id']);

      foreach ($args['shops'] ?? [] as $shop) {
        $user->shops()->create([
          'shop_id' => $shop
        ]);
      }
    });


    return
      [
        'code' => 200,
        'message' => 'OK'
      ];
  }
}
