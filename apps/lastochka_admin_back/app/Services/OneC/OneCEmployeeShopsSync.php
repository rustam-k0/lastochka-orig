<?php

declare(strict_types=1);

namespace App\Services\OneC;

use App\Models\Shop;
use App\Models\User;
use App\Models\UserShop;
use App\Services\Staff\OpenShiftShopSync;
use Illuminate\Support\Facades\Log;

/**
 * Магазины и графики сотрудника из 1С (MID-306).
 *
 * Список заменяет привязки: магазин не из списка отвязывается вместе с графиком,
 * открытая смена переезжает как при сохранении сотрудника в админке. Неизвестный
 * shop_code пропускается, магазин-заглушка не создаётся. schedule элемента заменяет
 * график в этом магазине целиком; нет ключа или график невалиден — не трогаем.
 */
final class OneCEmployeeShopsSync
{
  public function __construct(private readonly OpenShiftShopSync $openShiftShopSync)
  {
  }

  /**
   * @param array<int, mixed> $shops элементы {shop_code, schedule?}
   */
  public function sync(User $user, array $shops): void
  {
    $items = [];

    foreach ($shops as $item) {
      $code = is_array($item) && isset($item['shop_code']) && is_scalar($item['shop_code'])
        ? trim((string) $item['shop_code'])
        : '';

      if ($code === '') {
        $this->warn('shop item without shop_code skipped', $user);
        continue;
      }

      $shopId = Shop::query()->where('code', $code)->value('id');

      if ($shopId === null) {
        $this->warn('unknown shop_code skipped', $user, ['shop_code' => $code]);
        continue;
      }

      // Дубли shop_code: последний элемент списка.
      $items[(int) $shopId] = $item;
    }

    $keepIds = [];

    foreach ($items as $shopId => $item) {
      $userShop = UserShop::query()->firstOrCreate(['user_id' => $user->id, 'shop_id' => $shopId]);
      $keepIds[] = $userShop->id;

      if (array_key_exists('schedule', $item)) {
        $this->syncSchedule($userShop, $item['schedule'], $user);
      }
    }

    $user->shops()
      ->whereNotIn('id', $keepIds)
      ->get()
      ->each(function (UserShop $userShop) {
        $userShop->workingHours()->delete();
        $userShop->delete();
      });

    $this->openShiftShopSync->sync($user);
  }

  private function syncSchedule(UserShop $userShop, mixed $schedule, User $user): void
  {
    $days = OneCWeekSchedule::parse($schedule);

    if ($days === false) {
      $this->warn('schedule is invalid, working hours not changed', $user, ['shop_id' => $userShop->shop_id]);

      return;
    }

    $keepIds = [];

    foreach ($days as $day => $interval) {
      if ($interval === null) {
        continue;
      }

      $hour = $userShop->workingHours()->where('day_of_week', $day)->first()
        ?? $userShop->workingHours()->make(['day_of_week' => $day]);

      $hour->opening_time = $interval['start'];
      $hour->closing_time = $interval['end'];
      $hour->save();

      $keepIds[] = $hour->id;
    }

    $userShop->workingHours()->whereNotIn('id', $keepIds)->delete();
  }

  private function warn(string $message, User $user, array $context = []): void
  {
    Log::warning('1C employee shops: ' . $message, array_merge(['user_id' => $user->id], $context));
  }
}
