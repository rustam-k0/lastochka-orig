<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Settings;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ModuleConfigServiceTest extends TestCase
{
    use RefreshDatabase;

    private ModuleConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = new ModuleConfigService();
    }

    public function test_returns_standard_when_no_setting_exists(): void
    {
        $this->assertSame(ModuleConfigService::MODE_STANDARD, $this->service->getMarketplaceMode());
        $this->assertFalse($this->service->isMarketplaceMode());
    }

    public function test_returns_marketplace_when_select_value_is_marketplace(): void
    {
        $this->seedProjectModules([
            'marketplace_mode' => [
                'type' => 'select',
                'value' => 'marketplace',
                'label' => 'Режим магазина',
            ],
        ]);

        $this->assertSame(ModuleConfigService::MODE_MARKETPLACE, $this->service->getMarketplaceMode());
        $this->assertTrue($this->service->isMarketplaceMode());
    }

    public function test_returns_standard_when_select_value_is_unknown(): void
    {
        $this->seedProjectModules([
            'marketplace_mode' => [
                'type' => 'select',
                'value' => 'xyz-unknown',
            ],
        ]);

        $this->assertSame(ModuleConfigService::MODE_STANDARD, $this->service->getMarketplaceMode());
        $this->assertFalse($this->service->isMarketplaceMode());
    }

    public function test_returns_standard_when_project_modules_is_not_array(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            ['value' => 'not-a-json-object', 'type' => 'string'],
        );

        $this->assertSame(ModuleConfigService::MODE_STANDARD, $this->service->getMarketplaceMode());
    }

    public function test_returns_standard_when_marketplace_module_missing(): void
    {
        $this->seedProjectModules([
            'some_other_module' => ['type' => 'boolean', 'value' => true],
        ]);

        $this->assertSame(ModuleConfigService::MODE_STANDARD, $this->service->getMarketplaceMode());
    }

    public function test_returns_standard_on_exception(): void
    {
        Log::spy();

        // Сломаем саму таблицу — StorefrontSetting::getValue бросит PDO exception.
        Schema::drop('storefront_settings');

        $this->assertSame(ModuleConfigService::MODE_STANDARD, $this->service->getMarketplaceMode());

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) {
                return str_contains($message, 'Failed to read marketplace_mode')
                    && array_key_exists('error', $context);
            })
            ->atLeast()
            ->once();
    }

    public function test_is_marketplace_mode_returns_false_when_standard(): void
    {
        $this->seedProjectModules([
            'marketplace_mode' => ['type' => 'select', 'value' => 'standard'],
        ]);

        $this->assertFalse($this->service->isMarketplaceMode());
    }

    public function test_cache_hit_does_not_query_db_twice(): void
    {
        $this->seedProjectModules([
            'marketplace_mode' => ['type' => 'select', 'value' => 'marketplace'],
        ]);

        $this->assertTrue($this->service->isMarketplaceMode());

        // Подменяем запись — если кеш работает, сервис вернёт старое значение.
        StorefrontSetting::where('key', ModuleConfigService::PROJECT_MODULES_KEY)
            ->update([
                'value' => json_encode([
                    'marketplace_mode' => ['type' => 'select', 'value' => 'standard'],
                ], JSON_UNESCAPED_UNICODE),
            ]);

        $this->assertTrue($this->service->isMarketplaceMode(), 'cache should be hit');
    }

    public function test_repeated_reads_memoized_within_instance(): void
    {
        // N+1 к database-кешу (GlitchTip STOREFRONT_BACK-1E): ProductResource
        // зовёт is*Enabled() на каждый товар листинга — ~9000 SQL-запросов
        // к таблице cache на один HTTP-запрос. Повторное чтение в рамках
        // одного инстанса должно отдаваться из памяти, не из Cache.
        $this->seedProjectModules([
            ModuleConfigService::VIDEO_KEY => ['type' => 'checkbox', 'enabled' => true],
        ]);

        $this->assertTrue($this->service->isVideoEnabled());

        // Подменяем значение прямо в кеше: мемоизированный инстанс
        // не должен этого заметить...
        Cache::put(ModuleConfigService::CACHE_KEY_VIDEO, false, 600);

        $this->assertTrue(
            $this->service->isVideoEnabled(),
            'повторный вызов должен отдаваться из per-instance памяти, не из Cache',
        );

        // ...а свежий инстанс читает кеш как обычно
        $this->assertFalse((new ModuleConfigService())->isVideoEnabled());
    }

    public function test_service_is_singleton_in_container(): void
    {
        // Мемоизация работает только если app() отдаёт один и тот же инстанс
        // на весь запрос (ресурсы зовут app(ModuleConfigService::class) в цикле)
        $this->assertSame(
            app(ModuleConfigService::class),
            app(ModuleConfigService::class),
        );
    }

    public function test_invalidate_cache_forces_db_read(): void
    {
        $this->seedProjectModules([
            'marketplace_mode' => ['type' => 'select', 'value' => 'marketplace'],
        ]);

        $this->assertTrue($this->service->isMarketplaceMode());

        StorefrontSetting::where('key', ModuleConfigService::PROJECT_MODULES_KEY)
            ->update([
                'value' => json_encode([
                    'marketplace_mode' => ['type' => 'select', 'value' => 'standard'],
                ], JSON_UNESCAPED_UNICODE),
            ]);

        $this->service->invalidateCache();

        $this->assertFalse($this->service->isMarketplaceMode());
    }

    public function test_get_marketplace_free_shipping_threshold_returns_null_when_not_set(): void
    {
        $this->assertNull($this->service->getMarketplaceFreeShippingThreshold());
    }

    public function test_get_marketplace_free_shipping_threshold_returns_float_when_set(): void
    {
        StorefrontSetting::create([
            'key' => ModuleConfigService::FREE_SHIPPING_THRESHOLD_KEY,
            'value' => '2500',
            'type' => 'int',
        ]);

        $this->assertSame(2500.0, $this->service->getMarketplaceFreeShippingThreshold());
    }

    public function test_is_configurable_products_enabled_returns_false_when_no_setting(): void
    {
        $this->assertFalse($this->service->isConfigurableProductsEnabled());
    }

    public function test_is_configurable_products_enabled_true_when_module_enabled(): void
    {
        $this->seedProjectModules([
            'configurable_products' => [
                'label' => 'Вариативные товары',
                'enabled' => true,
            ],
        ]);

        $this->assertTrue($this->service->isConfigurableProductsEnabled());
    }

    public function test_is_configurable_products_enabled_false_when_module_disabled(): void
    {
        $this->seedProjectModules([
            'configurable_products' => [
                'label' => 'Вариативные товары',
                'enabled' => false,
            ],
        ]);

        $this->assertFalse($this->service->isConfigurableProductsEnabled());
    }

    public function test_product_ready_time_disabled_by_default(): void
    {
        $this->assertFalse(app(ModuleConfigService::class)->isProductReadyTimeEnabled());
    }

    public function test_product_ready_time_reads_enabled_flag(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'product_ready_time' => ['label' => 'Время готовности товаров', 'enabled' => true],
                ]),
                'type' => 'json',
            ]
        );
        app(ModuleConfigService::class)->invalidateCache();

        $this->assertTrue(app(ModuleConfigService::class)->isProductReadyTimeEnabled());
    }

    /**
     * @param  array<string, mixed>  $modules
     */
    private function seedProjectModules(array $modules): void
    {
        // updateOrCreate: запись project_modules может сидиться миграцией —
        // create() падал на UNIQUE constraint
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode($modules, JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ],
        );
    }
}
