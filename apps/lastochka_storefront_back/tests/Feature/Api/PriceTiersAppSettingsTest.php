<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class PriceTiersAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        app(ModuleConfigService::class)->invalidateCache();
    }

    public function test_price_tiers_flag_is_false_by_default(): void
    {
        $this->getJson('/api/v1/app-settings')
            ->assertOk()
            ->assertJsonPath('data.priceTiers', false);
    }

    public function test_price_tiers_flag_is_true_when_module_enabled(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'price_tiers' => ['label' => 'Цены от количества', 'enabled' => true],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );
        app(ModuleConfigService::class)->invalidateCache();

        $this->getJson('/api/v1/app-settings')
            ->assertOk()
            ->assertJsonPath('data.priceTiers', true);
    }
}
