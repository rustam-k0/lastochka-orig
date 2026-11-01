<?php

namespace App\Aggregator\Support;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Флаг режима агрегатора (aggregator-режим, FOODLINK-10874).
 *
 * Хранится в admin_settings.project_modules.aggregator_enabled.enabled (boolean-модуль,
 * как assembly_zones). Ортогонален marketplace_mode. Default = false — fail-safe для
 * форков: при выключенном флаге aggregator-listeners не регистрируются (см.
 * AggregatorServiceProvider::boot и docs/aggregator-mode.md §3.2, §6.2).
 */
class AggregatorMode
{
  public const CACHE_KEY = 'aggregator.enabled';

  public function isEnabled(): bool
  {
    try {
      return Cache::rememberForever(self::CACHE_KEY, function () {
        $raw = AdminSetting::query()->where('key', 'project_modules')->value('value');
        if ($raw === null) {
          return false;
        }

        return (bool) data_get(json_decode($raw, true), 'aggregator_enabled.enabled', false);
      });
    } catch (\Throwable $e) {
      // БД ещё не мигрирована / недоступна (например, во время `php artisan migrate`).
      // Не кэшируем неудачу — просто считаем флаг выключенным.
      return false;
    }
  }

  public function flushCache(): void
  {
    Cache::forget(self::CACHE_KEY);
  }
}
