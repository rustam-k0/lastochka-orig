<?php

namespace App\NetworkCatalog\Support;

use App\Models\AdminSetting;
use App\Models\Shop;
use Illuminate\Support\Facades\Cache;

/**
 * Флаг сетевого каталога (network_catalog): одна карточка товара на сеть,
 * цены/остатки пер-магазин. См. docs/specs/2026-08-06-network-catalog-design.md.
 *
 * Хранится в admin_settings.project_modules.network_catalog.enabled (boolean-модуль,
 * как aggregator_enabled). Default = false — fail-safe для форков.
 */
class NetworkCatalogMode
{
  public const CACHE_KEY = 'network_catalog.enabled';
  public const ANCHOR_CACHE_KEY = 'network_catalog.anchor_shop_id';

  public function isEnabled(): bool
  {
    try {
      return Cache::rememberForever(self::CACHE_KEY, function () {
        $raw = AdminSetting::query()->where('key', 'project_modules')->value('value');
        if ($raw === null) {
          return false;
        }

        return (bool) data_get(json_decode($raw, true), 'network_catalog.enabled', false);
      });
    } catch (\Throwable $e) {
      // БД не мигрирована / недоступна (во время php artisan migrate) — флаг выключен.
      return false;
    }
  }

  /**
   * Якорный магазин сети: владелец всех глобальных карточек. Конвенция та же,
   * что у сентинела 'default' в ResolvesShopCode (shops.default=1, fallback id=1).
   */
  public function anchorShopId(): int
  {
    try {
      return Cache::rememberForever(self::ANCHOR_CACHE_KEY, function () {
        return (int) (Shop::query()->where('default', 1)->value('id') ?? 1);
      });
    } catch (\Throwable $e) {
      return 1;
    }
  }

  public function flushCache(): void
  {
    Cache::forget(self::CACHE_KEY);
    Cache::forget(self::ANCHOR_CACHE_KEY);
  }
}
