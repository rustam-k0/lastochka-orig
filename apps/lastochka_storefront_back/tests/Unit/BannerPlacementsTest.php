<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class BannerPlacementsTest extends TestCase
{
    use RefreshDatabase;

    private function seedConfig(array $bannerItems): void
    {
        Cache::flush();
        StorefrontSetting::updateOrCreate(
            ['key' => 'entity_checkboxes'],
            ['value' => json_encode(['MerchantBanner' => $bannerItems]), 'type' => 'json'],
        );
    }

    public function test_returns_only_custom_enabled_with_valid_slug(): void
    {
        $this->seedConfig([
            ['field' => 'main_after_catalog', 'label' => 'Главная: после блока Каталог', 'custom' => true, 'enabled' => true],
            ['field' => 'product_card', 'label' => 'Карточка товара: под брендом', 'custom' => true, 'enabled' => false],
            ['field' => 'builtin_flag', 'label' => 'Встроенный', 'enabled' => true],
            ['field' => 'BAD SLUG', 'label' => 'Битый', 'custom' => true, 'enabled' => true],
            'not-an-array',
        ]);

        $placements = app(ModuleConfigService::class)->bannerPlacements();

        $this->assertSame(
            [['field' => 'main_after_catalog', 'label' => 'Главная: после блока Каталог']],
            $placements,
        );
    }

    public function test_label_falls_back_to_field(): void
    {
        $this->seedConfig([
            ['field' => 'solution_page', 'custom' => true, 'enabled' => true],
        ]);

        $this->assertSame(
            [['field' => 'solution_page', 'label' => 'solution_page']],
            app(ModuleConfigService::class)->bannerPlacements(),
        );
    }

    public function test_returns_empty_on_missing_or_broken_config(): void
    {
        Cache::flush();
        $this->assertSame([], app(ModuleConfigService::class)->bannerPlacements());

        Cache::flush();
        StorefrontSetting::updateOrCreate(
            ['key' => 'entity_checkboxes'],
            ['value' => '{broken', 'type' => 'json'],
        );
        $this->assertSame([], app(ModuleConfigService::class)->bannerPlacements());

        Cache::flush();
        StorefrontSetting::updateOrCreate(
            ['key' => 'entity_checkboxes'],
            ['value' => json_encode(['Product' => []]), 'type' => 'json'],
        );
        $this->assertSame([], app(ModuleConfigService::class)->bannerPlacements());
    }

    public function test_does_not_share_cache_with_product_flag_filters(): void
    {
        Cache::flush();
        StorefrontSetting::updateOrCreate(
            ['key' => 'entity_checkboxes'],
            ['value' => json_encode([
                'Product' => [
                    ['field' => 'is_hit', 'label' => 'Хит', 'custom' => true, 'enabled' => true, 'filterable' => true],
                ],
                'MerchantBanner' => [
                    ['field' => 'catalog_listing', 'label' => 'Каталог', 'custom' => true, 'enabled' => true],
                ],
            ]), 'type' => 'json'],
        );

        $service = app(ModuleConfigService::class);

        $this->assertSame([['field' => 'is_hit', 'label' => 'Хит']], $service->productFlagFilters());
        $this->assertSame([['field' => 'catalog_listing', 'label' => 'Каталог']], $service->bannerPlacements());
    }

    public function test_invalidate_cache_resets_placements(): void
    {
        $this->seedConfig([
            ['field' => 'catalog_listing', 'label' => 'Каталог', 'custom' => true, 'enabled' => true],
        ]);
        $service = app(ModuleConfigService::class);
        $this->assertCount(1, $service->bannerPlacements());

        StorefrontSetting::updateOrCreate(
            ['key' => 'entity_checkboxes'],
            ['value' => json_encode(['MerchantBanner' => []]), 'type' => 'json'],
        );
        $service->invalidateCache();

        $this->assertSame([], $service->bannerPlacements());
    }
}
