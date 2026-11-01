<?php

namespace App\GraphQL\Mutations\User;

use App\Models\User;
use App\Support\PartnerStaffAccess;
use Illuminate\Support\Facades\Log;

final class UserUpdate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args): array
  {
    $user = User::find($args['id']);

    // Партнёр не управляет привязкой к магазинам: сотрудник уже привязан к его
    // магазину, а wholesale-замена могла бы отвязать его от чужих магазинов.
    $manageShops = !PartnerStaffAccess::isPartner(\Auth::user());

    $this->updateInfo($user, $args, $manageShops);

    if (isset($args['password']) && !empty($args['password'])) {
      $sendCredentials = $args['send_credentials'] ?? false;
      $this->updatePass($user, $args['password'], $sendCredentials);
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }

  public function updateInfo(User $user, $args, bool $manageShops = true): void
  {
    $user->update($args);

    if (isset($args['role_id'])) {
      $user->syncRoles([$args['role_id']]);
    }

    if ($manageShops) {
      $this->updateShops($args['shops'] ?? [], $user);
    }
  }

  public function updatePass(User $user, string $password, bool $sendCredentials = false): void
  {
    $user->forceFill([
      'password' => bcrypt($password)
    ])->save();

    Log::info("User {$user->id} password changed by admin " . \Auth::id());

    if ($sendCredentials) {
      $user->sendCredentials($password);
    }
  }

  /**
   * @param $shops
   * @param User $user
   * @return void
   */
  protected function updateShops($shops, User $user): void
  {
    $shops_id = [];
    foreach ($shops as $shop) {
      $shops_id[] = $user->shops()->firstOrCreate([
        'shop_id' => $shop
      ])->id;
    }

    $user->shops()->whereNotIn('id', $shops_id)->delete();

    $this->syncOpenShiftShop($user);
  }

  /**
   * Открытая смена хранит снапшот shop_id с момента селфи (UserConfirmSelfie
   * в delivery_back); после смены магазинов он может указывать на отвязанный
   * магазин — профиль в приложении, ShiftCouriers и фильтр зон сборки
   * продолжают жить в старом магазине (ALADDIN-13554). Модели Shift в этом
   * сервисе нет — работаем с таблицей, как AnalyticsEvents::employeeSessionId.
   *
   * Если магазины сняли совсем — закрываем смену без публикации shifts.ended:
   * в admin_back нет entity-конфига shifts для CustomEventPublisher, а держать
   * смену открытой на отвязанном магазине хуже потери одного B2E-события.
   */
  protected function syncOpenShiftShop(User $user): void
  {
    app(\App\Services\Staff\OpenShiftShopSync::class)->sync($user);
  }
}
