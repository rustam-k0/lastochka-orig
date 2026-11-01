<?php

namespace Tests\Unit\Converters;

use App\Services\Converters\ShopConverter;
use Tests\TestCase;

/**
 * Тесты ShopConverter.
 * 
 * @see docs/tz/002-shops-exchange.md
 */
class ShopConverterTest extends TestCase
{
    protected ShopConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        config(['retailcrm.default_country_iso' => 'KZ']);
        config(['retailcrm.source' => 'retailcrm']);
        $this->converter = new ShopConverter();
    }

    // ==================== toRetailCRM ====================

    /**
     * Тест: корректный маппинг полей shop -> RetailCRM site.
     */
    public function test_to_retail_crm_maps_fields_correctly(): void
    {
        $data = [
            'id' => 'shop-almaty-001',
            'shop_code' => 'shop-almaty',
            'title' => 'Магазин Алматы',
            'source' => 'bagisto',
        ];

        $result = $this->converter->toRetailCRM($data);

        $this->assertEquals('shop-almaty', $result['code']);
        $this->assertEquals('Магазин Алматы', $result['name']);
        $this->assertEquals('', $result['url']);
        $this->assertEquals('KZ', $result['countryIso']);
    }

    /**
     * Тест: fallback на shop_code если title отсутствует.
     */
    public function test_to_retail_crm_uses_shop_code_as_name_fallback(): void
    {
        $data = [
            'id' => 'shop-001',
            'shop_code' => 'shop-astana',
            'source' => 'bagisto',
        ];

        $result = $this->converter->toRetailCRM($data);

        $this->assertEquals('shop-astana', $result['code']);
        $this->assertEquals('shop-astana', $result['name']); // Fallback
    }

    /**
     * Тест: конвертация с полным набором опциональных полей.
     */
    public function test_to_retail_crm_with_optional_fields(): void
    {
        $data = [
            'id' => 'shop-001',
            'shop_code' => 'shop-karaganda',
            'title' => 'Магазин Караганда',
            'url' => 'https://shop.karaganda.kz',
            'country_iso' => 'RU',
            'catalog_id' => 'main',
            'ordering' => 100,
            'source' => 'bagisto',
        ];

        $result = $this->converter->toRetailCRM($data);

        $this->assertEquals('shop-karaganda', $result['code']);
        $this->assertEquals('Магазин Караганда', $result['name']);
        $this->assertEquals('https://shop.karaganda.kz', $result['url']);
        $this->assertEquals('RU', $result['countryIso']);
        $this->assertEquals('main', $result['catalogId']);
        $this->assertEquals(100, $result['ordering']);
    }

    /**
     * Тест: санитизация shop_code с недопустимыми символами.
     */
    public function test_to_retail_crm_sanitizes_invalid_shop_code(): void
    {
        $data = [
            'id' => 'shop-001',
            'shop_code' => 'shop@almaty#2024!',
            'title' => 'Test',
            'source' => 'bagisto',
        ];

        $result = $this->converter->toRetailCRM($data);

        // Недопустимые символы заменяются на дефис, множественные дефисы схлопываются
        $this->assertEquals('shop-almaty-2024', $result['code']);
    }

    /**
     * Тест: shop_code обрезается до 50 символов.
     */
    public function test_to_retail_crm_truncates_long_shop_code(): void
    {
        $longCode = str_repeat('a', 100);
        
        $data = [
            'id' => 'shop-001',
            'shop_code' => $longCode,
            'title' => 'Test',
            'source' => 'bagisto',
        ];

        $result = $this->converter->toRetailCRM($data);

        $this->assertEquals(50, strlen($result['code']));
    }

    /**
     * Тест: shop_code с дефисами в начале и конце очищается.
     */
    public function test_to_retail_crm_trims_dashes_from_shop_code(): void
    {
        $data = [
            'id' => 'shop-001',
            'shop_code' => '---my-shop---',
            'title' => 'Test',
            'source' => 'bagisto',
        ];

        $result = $this->converter->toRetailCRM($data);

        $this->assertEquals('my-shop', $result['code']);
    }

    // ==================== extractExtendedData ====================

    /**
     * Тест: extractExtendedData извлекает все расширенные поля.
     */
    public function test_extract_extended_data_with_all_fields(): void
    {
        $data = [
            'id' => 'shop-001',
            'shop_code' => 'shop-almaty',
            'schedule' => [
                'monday' => ['start' => '09:00', 'end' => '21:00'],
                'tuesday' => ['start' => '09:00', 'end' => '21:00'],
            ],
            'latitude' => 43.238949,
            'longitude' => 76.945465,
            'slot_time' => 60,
            'open_24_7' => false,
            'active' => true,
        ];

        $extended = $this->converter->extractExtendedData($data);

        $this->assertEquals($data['schedule'], $extended['schedule']);
        $this->assertEquals(43.238949, $extended['latitude']);
        $this->assertEquals(76.945465, $extended['longitude']);
        $this->assertEquals(60, $extended['slot_time']);
        $this->assertFalse($extended['open_24_7']);
        $this->assertTrue($extended['active']);
    }

    /**
     * Тест: extractExtendedData возвращает пустой массив без расширенных полей.
     */
    public function test_extract_extended_data_returns_empty_for_minimal_data(): void
    {
        $data = [
            'id' => 'shop-001',
            'shop_code' => 'shop-almaty',
            'title' => 'Test Shop',
        ];

        $extended = $this->converter->extractExtendedData($data);

        $this->assertEmpty($extended);
    }

    /**
     * Тест: extractExtendedData корректно преобразует типы.
     */
    public function test_extract_extended_data_casts_types_correctly(): void
    {
        $data = [
            'latitude' => '43.238949', // string
            'longitude' => '76.945465', // string
            'slot_time' => '60', // string
            'open_24_7' => 1, // int
            'active' => 0, // int
        ];

        $extended = $this->converter->extractExtendedData($data);

        $this->assertIsFloat($extended['latitude']);
        $this->assertIsFloat($extended['longitude']);
        $this->assertIsInt($extended['slot_time']);
        $this->assertIsBool($extended['open_24_7']);
        $this->assertIsBool($extended['active']);
    }

    // ==================== fromRetailCRM ====================

    /**
     * Тест: fromRetailCRM корректно маппит данные из RetailCRM.
     */
    public function test_from_retail_crm_maps_fields_correctly(): void
    {
        $retailCrmData = [
            'code' => 'shop-almaty',
            'name' => 'Магазин Алматы',
            'active' => true,
        ];

        $result = $this->converter->fromRetailCRM($retailCrmData);

        $this->assertEquals('shop-almaty', $result['id']);
        $this->assertEquals('shop-almaty', $result['shop_code']);
        $this->assertEquals('Магазин Алматы', $result['title']);
        $this->assertTrue($result['active']);
        $this->assertEquals('retailcrm', $result['source']);
    }
}
