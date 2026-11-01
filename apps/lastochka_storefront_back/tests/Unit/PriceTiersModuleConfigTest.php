<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PriceTiersModuleConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        app(ModuleConfigService::class)->invalidateCache();
    }

    private function writeModule(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'price_tiers' => ['label' => 'Цены от количества', 'enabled' => $enabled],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );
    }

    public function test_disabled_by_default(): void
    {
        $svc = app(ModuleConfigService::class);

        $this->assertFalse($svc->isPriceTiersEnabled());
        $this->assertFalse($svc->isPriceTiersEnabledForConsumer());
    }

    public function test_enabled_when_module_on(): void
    {
        $this->writeModule(true);

        $svc = app(ModuleConfigService::class);
        $svc->invalidateCache();

        $this->assertTrue($svc->isPriceTiersEnabled());
        $this->assertTrue($svc->isPriceTiersEnabledForConsumer());
    }

    public function test_invalidate_cache_drops_price_tiers_key(): void
    {
        $this->writeModule(true);
        $svc = app(ModuleConfigService::class);
        $svc->invalidateCache();
        $this->assertTrue($svc->isPriceTiersEnabled());

        $this->writeModule(false);
        $svc->invalidateCache();

        $this->assertFalse($svc->isPriceTiersEnabled());
    }

    /**
     * Переключение флага обрабатывает консьюмер настроек в ДРУГОМ процессе:
     * он чистит общий кеш, но memo чужого экземпляра не сбрасывает.
     * Консьюмерный вариант обязан увидеть новое значение без рестарта.
     */
    public function test_consumer_variant_sees_switch_made_by_another_process(): void
    {
        $svc = app(ModuleConfigService::class);
        $this->assertFalse($svc->isPriceTiersEnabled());

        $this->writeModule(true);
        Cache::forget(ModuleConfigService::CACHE_KEY_PRICE_TIERS);

        $this->assertFalse($svc->isPriceTiersEnabled(), 'memo экземпляра держит старое значение');
        $this->assertTrue($svc->isPriceTiersEnabledForConsumer(), 'консьюмерный вариант читает общий кеш');
    }

    public function test_broken_settings_blob_means_disabled(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            ['value' => '"not an object"', 'type' => 'json']
        );

        $svc = app(ModuleConfigService::class);
        $svc->invalidateCache();

        $this->assertFalse($svc->isPriceTiersEnabled());
        $this->assertFalse($svc->isPriceTiersEnabledForConsumer());
    }
}
