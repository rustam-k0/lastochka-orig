<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Флаг network_catalog из общей БД djami (admin_settings ведёт admin_back).
 * Кэш короткий: включение флага в админке должно долетать до курьерки
 * без ручного сброса кэшей на всех модулях.
 */
class NetworkCatalogMode
{
  public const CACHE_KEY = 'network_catalog.enabled';

  public static function isEnabled(): bool
  {
    try {
      return Cache::remember(self::CACHE_KEY, 60, function () {
        $raw = DB::table('admin_settings')->where('key', 'project_modules')->value('value');

        return (bool) data_get(json_decode((string) $raw, true), 'network_catalog.enabled', false);
      });
    } catch (\Throwable $e) {
      return false;
    }
  }
}
