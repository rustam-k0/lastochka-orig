<?php

namespace Tests\Feature;

use App\Services\Settings\ModuleConfigService;
use Mockery;
use Tests\TestCase;

/**
 * Состав OpenAPI-документации: служебные ручки и функциональность, которой
 * в проекте нет, в спеку не попадают.
 */
class ApiDocsVisibilityTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        config(['api_docs.token' => 'secret-token']);

        $response = $this->get('/docs/api.json?key=secret-token');
        $response->assertStatus(200);

        return $response->json();
    }

    /**
     * Все модули выключены, режим маркетплейса не активен.
     */
    private function fakeModulesDisabled(): void
    {
        $modules = Mockery::mock(ModuleConfigService::class);
        $modules->shouldReceive('isModuleEnabled')->andReturn(false);
        $modules->shouldReceive('isMarketplaceMode')->andReturn(false);

        $this->app->instance(ModuleConfigService::class, $modules);
    }

    public function test_internal_and_debug_routes_are_not_documented(): void
    {
        $paths = array_keys($this->spec()['paths']);

        foreach ($paths as $path) {
            $this->assertStringStartsNotWith('/debug/', $path);
            $this->assertStringStartsNotWith('/v1/webhooks/', $path);
            $this->assertStringStartsNotWith('/v1/internal/', $path);
        }
    }

    public function test_hidden_paths_from_config_are_excluded(): void
    {
        config(['api_docs.hidden_paths' => ['v1/estimates', 'v1/estimates/*', 'v1/stores/*/menu-sections']]);

        $paths = array_keys($this->spec()['paths']);

        $this->assertNotContains('/v1/estimates', $paths);
        $this->assertNotContains('/v1/estimates/{estimate}', $paths);
        $this->assertNotContains('/v1/stores/{storeId}/menu-sections', $paths);

        // Соседние разделы чистка не задевает.
        $this->assertContains('/v1/cart', $paths);
        $this->assertContains('/v1/orders', $paths);
    }

    public function test_disabled_module_paths_are_excluded(): void
    {
        $this->fakeModulesDisabled();

        $paths = array_keys($this->spec()['paths']);

        $this->assertNotContains('/v1/preorders', $paths);
        $this->assertNotContains('/v1/brands', $paths);
        $this->assertNotContains('/v1/product-group-filters', $paths);
        $this->assertNotContains('/v1/products/{product}/documents', $paths);
        $this->assertNotContains('/v1/products/{product}/report-issue', $paths);
        $this->assertNotContains('/v1/pickup/cdek', $paths);
        $this->assertNotContains('/v1/delivery/cdek/tariffs', $paths);
        $this->assertNotContains('/v1/pickup/russianpost', $paths);
        $this->assertNotContains('/v1/client-orders', $paths);
        $this->assertNotContains('/v1/delivery/calculate', $paths);
    }

    public function test_enabled_module_paths_stay_documented(): void
    {
        $modules = Mockery::mock(ModuleConfigService::class);
        $modules->shouldReceive('isModuleEnabled')->andReturn(true);
        $modules->shouldReceive('isMarketplaceMode')->andReturn(true);

        $this->app->instance(ModuleConfigService::class, $modules);

        $paths = array_keys($this->spec()['paths']);

        $this->assertContains('/v1/preorders', $paths);
        $this->assertContains('/v1/brands', $paths);
        $this->assertContains('/v1/client-orders', $paths);
        $this->assertContains('/v1/pickup/cdek', $paths);
    }

    public function test_spec_has_no_tracker_ticket_codes(): void
    {
        $spec = json_encode($this->spec(), JSON_UNESCAPED_UNICODE);

        $this->assertSame(
            0,
            preg_match_all('/\b(?:MENDEV|MID|ALADDIN|LASTOCHKI-JAMI|LJM|BISMAR)[- ]?\d+\b/iu', $spec, $matches),
            'В документации остались коды задач: ' . implode(', ', $matches[0] ?? []),
        );
    }

    public function test_disabled_module_properties_are_removed_from_schemas(): void
    {
        $this->fakeModulesDisabled();

        $schemas = $this->spec()['components']['schemas'];

        $this->assertArrayNotHasKey('isB2b', $schemas['StoreResource']['properties'] ?? []);
        $this->assertArrayNotHasKey('isB2b', $schemas['OrderResource']['properties'] ?? []);
        $this->assertNotContains('isB2b', $schemas['StoreResource']['required'] ?? []);
        $this->assertNotContains('isB2b', $schemas['OrderResource']['required'] ?? []);

        // Остальные поля схемы на месте.
        $this->assertArrayHasKey('isActive', $schemas['StoreResource']['properties'] ?? []);
    }

    public function test_enabled_module_properties_stay_in_schemas(): void
    {
        $modules = Mockery::mock(ModuleConfigService::class);
        $modules->shouldReceive('isModuleEnabled')->andReturn(true);
        $modules->shouldReceive('isMarketplaceMode')->andReturn(true);
        $this->app->instance(ModuleConfigService::class, $modules);

        $schemas = $this->spec()['components']['schemas'];

        $this->assertArrayHasKey('isB2b', $schemas['StoreResource']['properties'] ?? []);
    }

    public function test_spec_has_no_traces_of_disabled_marketplace_mode(): void
    {
        $this->fakeModulesDisabled();

        $spec = mb_strtolower(json_encode($this->spec(), JSON_UNESCAPED_UNICODE));

        foreach (['marketplace', 'маркетплейс', 'b2b', 'сдэк', 'почты россии'] as $trace) {
            $this->assertStringNotContainsString($trace, $spec, "Документация упоминает недоступный режим: {$trace}");
        }
    }

    public function test_spec_has_no_other_project_names(): void
    {
        $spec = mb_strtolower(json_encode($this->spec(), JSON_UNESCAPED_UNICODE));

        foreach (['aladdin', 'аладдин', 'electrohub', 'электрохаб', 'portalstore', 'foodlink', 'mendev', 'bismar'] as $name) {
            $this->assertStringNotContainsString($name, $spec, "В документации упомянут другой проект: {$name}");
        }
    }
}
