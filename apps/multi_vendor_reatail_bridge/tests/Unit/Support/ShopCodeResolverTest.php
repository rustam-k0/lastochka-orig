<?php

namespace Tests\Unit\Support;

use App\Services\RetailCRM\RetailCRMService;
use App\Support\ShopCodeResolver;
use Mockery;
use Tests\TestCase;

/**
 * @see docs/tz/001-multivendor-shop-code.md
 */
class ShopCodeResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Сбрасываем конфиг между тестами
        config(['retailcrm.shop_code' => null]);

        // Мокаем RetailCRMService чтобы getMainSite() возвращал null по умолчанию
        $this->mock(RetailCRMService::class, function ($mock) {
            $mock->shouldReceive('getMainSite')->andReturn(null);
        });
    }

    /**
     * Тест: shop_code из входящих данных имеет приоритет.
     */
    public function test_resolve_uses_provided_shop_code(): void
    {
        $result = ShopCodeResolver::resolve('shop-almaty');

        $this->assertEquals('shop-almaty', $result);
    }

    /**
     * Тест: fallback на конфиг когда shop_code отсутствует.
     */
    public function test_resolve_falls_back_to_config_when_shop_code_is_null(): void
    {
        config(['retailcrm.shop_code' => 'config-shop']);

        $result = ShopCodeResolver::resolve(null);

        $this->assertEquals('config-shop', $result);
    }

    /**
     * Тест: fallback на конфиг когда shop_code пустой.
     */
    public function test_resolve_falls_back_to_config_when_shop_code_is_empty(): void
    {
        config(['retailcrm.shop_code' => 'config-shop']);

        $result = ShopCodeResolver::resolve('');

        $this->assertEquals('config-shop', $result);
    }

    /**
     * Тест: 'default' считается невалидным и fallback на конфиг.
     */
    public function test_resolve_treats_default_as_invalid(): void
    {
        config(['retailcrm.shop_code' => 'config-shop']);

        $result = ShopCodeResolver::resolve('default');

        $this->assertEquals('config-shop', $result);
    }

    /**
     * Тест: fallback на getMainSite() когда конфиг пустой.
     */
    public function test_resolve_falls_back_to_get_main_site(): void
    {
        config(['retailcrm.shop_code' => null]);

        // Переопределяем mock чтобы getMainSite() вернул значение
        $this->mock(RetailCRMService::class, function ($mock) {
            $mock->shouldReceive('getMainSite')->andReturn('api-main-site');
        });

        $result = ShopCodeResolver::resolve(null);

        $this->assertEquals('api-main-site', $result);
    }

    /**
     * Тест: hardcoded fallback когда конфиг и API пустые.
     */
    public function test_resolve_returns_default_when_config_and_api_are_empty(): void
    {
        config(['retailcrm.shop_code' => null]);

        // Mock уже настроен в setUp() возвращать null

        $result = ShopCodeResolver::resolve(null);

        $this->assertEquals('default', $result);
    }

    /**
     * Тест: hardcoded fallback когда конфиг равен 'default'.
     */
    public function test_resolve_returns_default_when_config_is_default(): void
    {
        config(['retailcrm.shop_code' => 'default']);

        $result = ShopCodeResolver::resolve(null);

        $this->assertEquals('default', $result);
    }

    /**
     * Тест: extractFromData использует shop_code из данных.
     */
    public function test_extract_from_data_uses_shop_code_field(): void
    {
        $data = ['shop_code' => 'shop-from-data'];

        $result = ShopCodeResolver::extractFromData($data);

        $this->assertEquals('shop-from-data', $result);
    }

    /**
     * Тест: extractFromData использует site как fallback.
     */
    public function test_extract_from_data_uses_site_field_as_fallback(): void
    {
        $data = ['site' => 'site-from-retailcrm'];

        $result = ShopCodeResolver::extractFromData($data);

        $this->assertEquals('site-from-retailcrm', $result);
    }

    /**
     * Тест: shop_code имеет приоритет над site.
     */
    public function test_extract_from_data_shop_code_takes_priority_over_site(): void
    {
        $data = [
            'shop_code' => 'shop-priority',
            'site' => 'site-ignored',
        ];

        $result = ShopCodeResolver::extractFromData($data);

        $this->assertEquals('shop-priority', $result);
    }

    /**
     * Тест: fallback на конфиг когда ни shop_code ни site нет.
     */
    public function test_extract_from_data_falls_back_to_config(): void
    {
        config(['retailcrm.shop_code' => 'config-fallback']);
        $data = ['some_other_field' => 'value'];

        $result = ShopCodeResolver::extractFromData($data);

        $this->assertEquals('config-fallback', $result);
    }
}
