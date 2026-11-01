<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Чтение флагов модулей из project_modules (JSON в admin_settings, пишет admin_back).
 * В delivery используется только для чтения.
 */
class ModuleConfigService
{
  public const PROJECT_MODULES_KEY = 'project_modules';

  public const PRODUCT_READY_TIME_KEY = 'product_ready_time';
  public const CACHE_KEY_PRODUCT_READY_TIME = 'module_config:delivery:product_ready_time';

  /**
   * Включён ли модуль «Время готовности товаров».
   * Fail-safe: любые исключения → false.
   */
  public static function isProductReadyTimeEnabled(): bool
  {
    try {
      return (bool) Cache::remember(
        self::CACHE_KEY_PRODUCT_READY_TIME,
        now()->addMinutes(5),
        fn () => self::readBooleanModule(self::PRODUCT_READY_TIME_KEY)
      );
    } catch (Throwable $e) {
      Log::warning('Failed to read product_ready_time flag, defaulting to false', [
        'error' => $e->getMessage(),
      ]);

      return false;
    }
  }

  public static function invalidateCache(): void
  {
    Cache::forget(self::CACHE_KEY_PRODUCT_READY_TIME);
  }

  /**
   * Читает boolean-модуль из project_modules JSON blob в admin_settings.
   */
  private static function readBooleanModule(string $moduleKey): bool
  {
    $raw = AdminSetting::getValue(self::PROJECT_MODULES_KEY);

    if (!$raw) {
      return false;
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
      return false;
    }

    $module = $decoded[$moduleKey] ?? null;

    if (!is_array($module)) {
      return false;
    }

    return (bool) ($module['enabled'] ?? false);
  }
}
