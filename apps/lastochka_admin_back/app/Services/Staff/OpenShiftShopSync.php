<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Открытая смена хранит снапшот shop_id с момента селфи (ALADDIN-13554).
 * После смены магазинов сотрудника смена переезжает на первый актуальный магазин,
 * а без магазинов закрывается. Используется UserUpdate и приёмом сотрудников из 1С (MID-306).
 */
final class OpenShiftShopSync
{
  public function sync(User $user): void
  {
    $openShift = DB::table('shifts')
      ->where('user_id', $user->id)
      ->whereNull('finished_at')
      ->whereNull('deleted_at')
      ->first();

    if (!$openShift) {
      return;
    }

    $actualShopIds = $user->shops()->pluck('shop_id')->map(fn($id) => (int)$id);

    if ($actualShopIds->contains((int)$openShift->shop_id)) {
      return;
    }

    DB::table('shifts')->where('id', $openShift->id)->update(
      $actualShopIds->isEmpty()
        ? ['finished_at' => now(), 'updated_at' => now()]
        : ['shop_id' => $actualShopIds->first(), 'updated_at' => now()]
    );
  }
}
