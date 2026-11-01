<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\EnsureMarketplaceMode;
use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class EnsureMarketplaceModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Route::middleware(EnsureMarketplaceMode::class)
            ->get('/_test/marketplace-only', fn () => response()->json(['ok' => true]));
    }

    public function test_returns_404_when_standard_mode(): void
    {
        // По умолчанию — standard.
        $this->getJson('/_test/marketplace-only')->assertNotFound();
    }

    public function test_passes_through_when_marketplace_mode(): void
    {
        $this->enableMarketplaceMode();

        $this->getJson('/_test/marketplace-only')
            ->assertOk()
            ->assertExactJson(['ok' => true]);
    }

    public function test_returns_404_when_marketplace_mode_explicitly_standard(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'marketplace_mode' => ['type' => 'select', 'value' => 'standard'],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ],
        );

        $this->getJson('/_test/marketplace-only')->assertNotFound();
    }

    private function enableMarketplaceMode(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'marketplace_mode' => ['type' => 'select', 'value' => 'marketplace'],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ],
        );

        app(ModuleConfigService::class)->invalidateCache();
    }
}
