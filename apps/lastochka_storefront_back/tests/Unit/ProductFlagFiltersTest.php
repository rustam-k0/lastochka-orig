<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ProductFlagFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function seedConfig(array $productItems): void
    {
        Cache::flush();
        StorefrontSetting::updateOrCreate(
            ['key' => 'entity_checkboxes'],
            ['value' => json_encode(['Product' => $productItems]), 'type' => 'json'],
        );
    }

    public function test_returns_only_custom_enabled_filterable(): void
    {
        $this->seedConfig([
            ['field' => 'is_culinary', 'label' => 'Кулинария', 'enabled' => true],
            ['field' => 'is_hit', 'label' => 'Хит продаж', 'enabled' => true, 'custom' => true, 'filterable' => true],
            ['field' => 'is_eco', 'label' => 'Эко', 'enabled' => true, 'custom' => true, 'filterable' => false],
            ['field' => 'is_off', 'label' => 'Выкл', 'enabled' => false, 'custom' => true, 'filterable' => true],
            ['field' => 'BAD SLUG', 'label' => 'Битый', 'enabled' => true, 'custom' => true, 'filterable' => true],
        ]);

        $flags = app(ModuleConfigService::class)->productFlagFilters();

        $this->assertSame([['field' => 'is_hit', 'label' => 'Хит продаж']], $flags);
    }

    public function test_returns_empty_on_missing_or_broken_config(): void
    {
        Cache::flush();
        $this->assertSame([], app(ModuleConfigService::class)->productFlagFilters());

        Cache::flush();
        StorefrontSetting::updateOrCreate(
            ['key' => 'entity_checkboxes'],
            ['value' => '{broken', 'type' => 'json'],
        );
        $this->assertSame([], app(ModuleConfigService::class)->productFlagFilters());
    }

    public function test_invalidate_cache_resets_flags(): void
    {
        $this->seedConfig([
            ['field' => 'is_hit', 'label' => 'Хит', 'enabled' => true, 'custom' => true, 'filterable' => true],
        ]);
        $service = app(ModuleConfigService::class);
        $this->assertCount(1, $service->productFlagFilters());

        StorefrontSetting::updateOrCreate(
            ['key' => 'entity_checkboxes'],
            ['value' => json_encode(['Product' => []]), 'type' => 'json'],
        );
        $service->invalidateCache();

        $this->assertSame([], $service->productFlagFilters());
    }
}
