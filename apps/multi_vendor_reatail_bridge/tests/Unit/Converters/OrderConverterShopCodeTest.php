<?php

namespace Tests\Unit\Converters;

use App\Services\Converters\OrderConverter;
use Tests\TestCase;

/**
 * Тесты мультивендорности shop_code в OrderConverter.
 *
 * @see docs/tz/001-multivendor-shop-code.md
 */
class OrderConverterShopCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['retailcrm.shop_code' => 'default-config']);
        config(['retailcrm.source' => 'retailcrm']);
    }

    /**
     * Тест: toRetailCRM использует shop_code из входящих данных.
     */
    public function test_to_retail_crm_uses_shop_code_from_data(): void
    {
        $converter = new OrderConverter();

        $data = [
            'id' => '123',
            'shop_code' => 'shop-almaty',
            'client_info' => ['name' => 'Test'],
        ];

        $result = $converter->toRetailCRM($data);

        $this->assertEquals('shop-almaty', $result['site']);
    }

    /**
     * Тест: toRetailCRM fallback на конфиг когда shop_code отсутствует.
     */
    public function test_to_retail_crm_falls_back_to_config(): void
    {
        config(['retailcrm.shop_code' => 'config-site']);
        $converter = new OrderConverter(['shop_code' => 'config-site']);

        $data = [
            'id' => '123',
            'client_info' => ['name' => 'Test'],
        ];

        $result = $converter->toRetailCRM($data);

        $this->assertEquals('config-site', $result['site']);
    }

    /**
     * Тест: fromRetailCRM использует site из данных RetailCRM.
     */
    public function test_from_retail_crm_uses_site_from_data(): void
    {
        $converter = new OrderConverter();

        $retailCrmData = [
            'id' => 456,
            'externalId' => '123',
            'site' => 'shop-astana',
            'status' => 'new',
            'firstName' => 'Test',
        ];

        $result = $converter->fromRetailCRM($retailCrmData);

        $this->assertEquals('shop-astana', $result['shop_code']);
    }

    /**
     * Тест: fromRetailCRM fallback на конфиг когда site отсутствует.
     */
    public function test_from_retail_crm_falls_back_to_config_when_site_absent(): void
    {
        config(['retailcrm.shop_code' => 'fallback-shop']);
        $converter = new OrderConverter(['shop_code' => 'fallback-shop']);

        $retailCrmData = [
            'id' => 456,
            'status' => 'new',
            'firstName' => 'Test',
        ];

        $result = $converter->fromRetailCRM($retailCrmData);

        $this->assertEquals('fallback-shop', $result['shop_code']);
    }

    /**
     * Тест: Разные события с разными shop_code генерируют разные значения.
     */
    public function test_different_shop_codes_produce_different_results(): void
    {
        $converter = new OrderConverter();

        $data1 = [
            'id' => '1',
            'shop_code' => 'shop-1',
            'client_info' => [],
        ];

        $data2 = [
            'id' => '2',
            'shop_code' => 'shop-2',
            'client_info' => [],
        ];

        $result1 = $converter->toRetailCRM($data1);
        $result2 = $converter->toRetailCRM($data2);

        $this->assertEquals('shop-1', $result1['site']);
        $this->assertEquals('shop-2', $result2['site']);
    }
}
