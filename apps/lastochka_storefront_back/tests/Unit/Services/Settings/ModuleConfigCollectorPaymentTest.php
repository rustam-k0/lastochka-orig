<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Settings;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ModuleConfigCollectorPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_collector_payment_disabled_by_default(): void
    {
        $this->assertFalse(app(ModuleConfigService::class)->isCollectorPaymentEnabled());
    }

    public function test_collector_payment_enabled_from_project_modules(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            ['value' => json_encode(['collector_payment' => ['label' => 'x', 'enabled' => true]]), 'type' => 'json'],
        );
        app(ModuleConfigService::class)->invalidateCache();

        $this->assertTrue(app(ModuleConfigService::class)->isCollectorPaymentEnabled());
    }

    public function test_surcharge_wait_hours_default_24(): void
    {
        $this->assertSame(24, app(ModuleConfigService::class)->getSurchargeWaitHours());
    }

    public function test_surcharge_wait_hours_from_setting_with_floor(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => 'surcharge_wait_hours'],
            ['value' => '48', 'type' => 'string'],
        );
        app(ModuleConfigService::class)->invalidateCache();
        $this->assertSame(48, app(ModuleConfigService::class)->getSurchargeWaitHours());

        StorefrontSetting::updateOrCreate(['key' => 'surcharge_wait_hours'], ['value' => '0', 'type' => 'string']);
        app(ModuleConfigService::class)->invalidateCache();
        $this->assertSame(24, app(ModuleConfigService::class)->getSurchargeWaitHours());
    }
}
