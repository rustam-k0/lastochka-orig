<?php

namespace Tests\Unit\Converters;

use App\Services\Converters\CategoryConverter;
use App\Services\Converters\CustomerConverter;
use App\Services\Converters\ProductConverter;
use App\Services\Converters\ShopConverter;
use App\Services\Converters\UserConverter;
use Tests\TestCase;

/**
 * Тесты мультивендорности shop_code во всех конвертерах.
 *
 * @see docs/tz/001-multivendor-shop-code.md
 */
class ConvertersShopCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['retailcrm.shop_code' => 'default-config']);
        config(['retailcrm.source' => 'retailcrm']);
    }

    // ==================== CustomerConverter ====================

    /**
     * CustomerConverter: toRetailCRM использует shop_code из данных.
     */
    public function test_customer_to_retail_crm_uses_shop_code_from_data(): void
    {
        $converter = new CustomerConverter();

        $data = [
            'id' => '123',
            'shop_code' => 'shop-almaty',
            'name' => 'Test',
        ];

        $result = $converter->toRetailCRM($data);

        $this->assertEquals('shop-almaty', $result['site']);
    }

    /**
     * CustomerConverter: fromRetailCRM использует site из данных RetailCRM.
     */
    public function test_customer_from_retail_crm_uses_site_from_data(): void
    {
        $converter = new CustomerConverter();

        $retailCrmData = [
            'id' => 456,
            'site' => 'shop-astana',
            'firstName' => 'Test',
        ];

        $result = $converter->fromRetailCRM($retailCrmData);

        $this->assertEquals('shop-astana', $result['shop_code']);
    }

    // ==================== ProductConverter ====================

    /**
     * ProductConverter: fromRetailCRM использует site из данных RetailCRM.
     */
    public function test_product_from_retail_crm_uses_site_from_data(): void
    {
        $converter = new ProductConverter();

        $retailCrmData = [
            'id' => 789,
            'site' => 'shop-karaganda',
            'name' => 'Test Product',
        ];

        $result = $converter->fromRetailCRM($retailCrmData);

        $this->assertEquals('shop-karaganda', $result['shop_code']);
    }

    /**
     * ProductConverter: fromRetailCRM fallback на конфиг.
     */
    public function test_product_from_retail_crm_falls_back_to_config(): void
    {
        config(['retailcrm.shop_code' => 'product-fallback']);
        $converter = new ProductConverter(['shop_code' => 'product-fallback']);

        $retailCrmData = [
            'id' => 789,
            'name' => 'Test Product',
        ];

        $result = $converter->fromRetailCRM($retailCrmData);

        $this->assertEquals('product-fallback', $result['shop_code']);
    }

    // ==================== CategoryConverter ====================

    /**
     * CategoryConverter: fromRetailCRM использует site из данных RetailCRM.
     */
    public function test_category_from_retail_crm_uses_site_from_data(): void
    {
        $converter = new CategoryConverter();

        $retailCrmData = [
            'id' => 111,
            'site' => 'shop-shymkent',
            'name' => 'Test Category',
        ];

        $result = $converter->fromRetailCRM($retailCrmData);

        $this->assertEquals('shop-shymkent', $result['shop_code']);
    }

    // ==================== UserConverter ====================

    /**
     * UserConverter: fromRetailCRM использует site из данных RetailCRM.
     */
    public function test_user_from_retail_crm_uses_site_from_data(): void
    {
        $converter = new UserConverter();

        $retailCrmData = [
            'id' => 222,
            'site' => 'shop-aktau',
            'firstName' => 'Test',
            'lastName' => 'User',
        ];

        $result = $converter->fromRetailCRM($retailCrmData);

        $this->assertEquals('shop-aktau', $result['shop_code']);
    }

    // ==================== ShopConverter ====================

    /**
     * ShopConverter: fromRetailCRM использует code как shop_code (специфичная логика).
     */
    public function test_shop_from_retail_crm_uses_code_as_shop_code(): void
    {
        $converter = new ShopConverter();

        $retailCrmData = [
            'code' => 'my-shop-code',
            'name' => 'My Shop',
            'active' => true,
        ];

        $result = $converter->fromRetailCRM($retailCrmData);

        // Для ShopConverter shop_code = код самого магазина
        $this->assertEquals('my-shop-code', $result['shop_code']);
    }

    /**
     * ShopConverter: fromRetailCRM fallback на site когда code отсутствует.
     */
    public function test_shop_from_retail_crm_falls_back_to_site(): void
    {
        $converter = new ShopConverter();

        $retailCrmData = [
            'id' => 333,
            'site' => 'fallback-site',
            'name' => 'My Shop',
        ];

        $result = $converter->fromRetailCRM($retailCrmData);

        $this->assertEquals('fallback-site', $result['shop_code']);
    }
}
