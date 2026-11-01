<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Settings;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

/**
 * Модуль «1С ведёт магазины и сотрудников» (onec_shops_master, MID-306) читает
 * долгоживущий консьюмер магазинов, а кеш сбрасывает консьюмер настроек в другом
 * процессе. Значение не должно залипать в экземпляре сервиса.
 */
final class ModuleConfigServiceOneCShopsMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function writeModules(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'onec_shops_master' => ['label' => '1С ведёт магазины и сотрудников', 'enabled' => $enabled],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );
    }

    public function test_disabled_by_default(): void
    {
        $this->assertFalse(app(ModuleConfigService::class)->isOneCShopsMasterEnabled());
    }

    public function test_enabled_when_module_on(): void
    {
        $this->writeModules(true);
        $svc = app(ModuleConfigService::class);
        $svc->invalidateCache();

        $this->assertTrue($svc->isOneCShopsMasterEnabled());
    }

    public function test_switch_from_other_process_is_visible_to_same_instance(): void
    {
        $this->writeModules(true);
        $svc = app(ModuleConfigService::class);
        $svc->invalidateCache();
        $this->assertTrue($svc->isOneCShopsMasterEnabled());

        $this->writeModules(false);
        Cache::forget(ModuleConfigService::CACHE_KEY_ONEC_SHOPS_MASTER);

        $this->assertFalse($svc->isOneCShopsMasterEnabled());
    }

    public function test_cache_failure_defaults_to_false(): void
    {
        $this->writeModules(true);
        $svc = app(ModuleConfigService::class);
        $svc->invalidateCache();
        Cache::shouldReceive('remember')->andThrow(new RuntimeException('cache down'));

        $this->assertFalse($svc->isOneCShopsMasterEnabled());
    }
}
