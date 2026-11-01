<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Catalog;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductBarcodeSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_product_by_barcode_success(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'barcodes' => ['1234567890', '0987654321'],
            'title' => 'Тестовый товар',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/1234567890');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'sku',
                    'title',
                    'price',
                    'currency',
                    'images',
                ],
            ])
            ->assertJson([
                'data' => [
                    'id' => $product->id,
                    'title' => 'Тестовый товар',
                ],
            ]);
    }

    public function test_search_product_by_second_barcode(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'barcodes' => ['1111111111', '2222222222'],
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/2222222222');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $product->id,
                ],
            ]);
    }

    public function test_search_product_by_barcode_not_found(): void
    {
        $store = Store::factory()->create();
        Product::factory()->create([
            'store_id' => $store->id,
            'barcodes' => ['1234567890'],
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/9999999999');

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Товар с указанным штрихкодом не найден',
            ]);
    }

    public function test_search_inactive_product_by_barcode_not_found(): void
    {
        $store = Store::factory()->create();
        Product::factory()->create([
            'store_id' => $store->id,
            'barcodes' => ['1234567890'],
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/1234567890');

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Товар с указанным штрихкодом не найден',
            ]);
    }

    public function test_search_by_weighted_barcode_finds_product_by_code(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'code' => '296',
            'barcodes' => ['4606055986816'],
            'title' => 'Картофель',
            'is_active' => true,
        ]);

        // Весовой штрихкод: «23» + код «00296» (padding) + вес/к.ц.
        $response = $this->getJson('/api/v1/products/search/barcode/2300296000000');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $product->id, 'title' => 'Картофель']]);
    }

    public function test_weighted_barcode_tolerates_leading_zeros_in_stored_code(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'code' => '00296', // хранится с padding-ом
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/2300296000000');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $product->id]]);
    }

    public function test_weighted_barcode_with_prefix_21_also_matches_by_code(): void
    {
        // Весовой префикс может быть любым из 20–29, не только «23».
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'code' => '777',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/2100777000000');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $product->id]]);
    }

    /**
     * 1С генерирует для собственной номенклатуры (выпечка/супы «Ласточка»)
     * СТАТИЧНЫЕ внутренние EAN-13 с префиксом 20–29: 2100100058357 = префикс 21
     * + сквозной счётчик, цифры после префикса — НЕ код товара. Такой штрихкод
     * лежит в products.barcodes и должен находиться по точному совпадению,
     * а не перехватываться как весовой (код «00100» → 404).
     */
    public function test_stored_internal_barcode_with_prefix_21_is_found_by_exact_match(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'code' => '171 289',
            'barcodes' => ['2100100058357'],
            'title' => 'Суп Чечевичный Ласточка',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/2100100058357');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $product->id, 'title' => 'Суп Чечевичный Ласточка']]);
    }

    public function test_stored_internal_barcode_wins_over_product_with_embedded_code(): void
    {
        $store = Store::factory()->create();
        $internal = Product::factory()->create([
            'store_id' => $store->id,
            'code' => '171 289',
            'barcodes' => ['2100100058357'],
            'title' => 'Суп Чечевичный Ласточка',
            'is_active' => true,
        ]);
        // Конкурент: код «100» — ровно то, что WeightedBarcode вырезает из 2100100058357.
        Product::factory()->create([
            'store_id' => $store->id,
            'code' => '100',
            'barcodes' => ['4600000000100'],
            'title' => 'Товар с кодом 100',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/2100100058357');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $internal->id, 'title' => 'Суп Чечевичный Ласточка']]);
    }

    public function test_weighted_barcode_falls_back_to_code_only_when_no_stored_barcode(): void
    {
        // Весовой товар: в 1С «штрихкод» = код, дополненный нулями до 13 знаков.
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'code' => '23',
            'barcodes' => ['0000000000023'],
            'title' => 'Огурцы Осетия',
            'is_active' => true,
        ]);

        // С весов: 23 + код 00023 + вес 01234 + к.ц. — такого штрихкода в базе нет.
        $response = $this->getJson('/api/v1/products/search/barcode/2300023012345');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $product->id, 'title' => 'Огурцы Осетия']]);
    }

    /**
     * 1С отдаёт EAN-8/UPC-A дополненными нулями до 13 знаков («0000080768258»
     * для Kinder Joy «80768258»), а сканер возвращает штрихкод как есть —
     * поиск должен быть толерантен к ведущим нулям в обе стороны.
     */
    public function test_ean8_scanned_raw_finds_product_stored_zero_padded_by_1c(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'code' => '5 480',
            'barcodes' => ['0000080768258'],
            'title' => 'Киндер Сюрприз Джой 20г для девочек',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/80768258');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $product->id, 'title' => 'Киндер Сюрприз Джой 20г для девочек']]);
    }

    public function test_upc_a_scanned_raw_finds_product_stored_zero_padded_by_1c(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'code' => '96 987',
            'barcodes' => ['0873999000036'],
            'title' => 'Батарейки Камелион Aa lr6 2шт',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/873999000036');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $product->id]]);
    }

    public function test_scanned_zero_padded_finds_product_stored_raw_ean8(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'barcodes' => ['80768258'],
            'title' => 'Ручной товар с сырым EAN-8',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/0000080768258');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $product->id]]);
    }

    public function test_weighted_barcode_finds_product_by_plu_when_code_is_real_1c_code(): void
    {
        // Выгрузка после доработки 1С: «Код» = код номенклатуры с ценника,
        // PLU весов — отдельным реквизитом. Весы печатают в штрихкоде PLU.
        $store = Store::factory()->create();
        $pepper = Product::factory()->create([
            'store_id' => $store->id,
            'code' => '52026',
            'plu' => '00030',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/2300030012345');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $pepper->id]]);
    }

    public function test_weighted_barcode_prefers_plu_over_foreign_code(): void
    {
        $store = Store::factory()->create();
        $pepper = Product::factory()->create([
            'store_id' => $store->id,
            'code' => '52026',
            'plu' => '30',
            'is_active' => true,
        ]);
        // Суп с коротким кодом номенклатуры, равным PLU перца.
        Product::factory()->create([
            'store_id' => $store->id,
            'code' => '30',
            'plu' => null,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/2300030012345');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $pepper->id]]);
    }

    /**
     * Код 1С у весового и штучного товара совпадает: у весовых 1С кладёт в «Код»
     * PLU весов, у собственного производства «Ласточка» коды тоже короткие
     * (код 23: «Огурцы Осетия» и «Люля Куриные На Мангале»). Штучный создан
     * первым — без явного приоритета выигрывала строка с меньшим id.
     */
    public function test_weighted_barcode_prefers_weighted_product_over_piece_with_same_code(): void
    {
        $store = Store::factory()->create();
        $piece = Product::factory()->create([
            'store_id' => $store->id,
            'title' => 'Люля Куриные На Мангале',
            'code' => '23',
            'barcodes' => ['2100100065539'],
            'measurement_unit' => 'piece',
            'is_active' => true,
        ]);
        $cucumbers = Product::factory()->create([
            'store_id' => $store->id,
            'title' => 'Огурцы Осетия',
            'code' => '23',
            'barcodes' => ['0000000000023'],
            'measurement_unit' => 'gram',
            'unit_type' => 'kg',
            'is_active' => true,
        ]);

        // Этикетка весов: префикс 22 + код 00023 + вес 00968 + к.ц.
        $response = $this->getJson('/api/v1/products/search/barcode/2200023009680');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $cucumbers->id]]);
        $this->assertNotSame($piece->id, $response->json('data.id'));
    }

    public function test_weighted_barcode_skips_piece_product_when_weighted_with_same_code_is_not_purchasable(): void
    {
        $store = Store::factory()->create();
        Product::factory()->create([
            'store_id' => $store->id,
            'title' => 'Люля Говяжьи На Мангале',
            'code' => '24',
            'barcodes' => ['2100100065621'],
            'measurement_unit' => 'piece',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'store_id' => $store->id,
            'title' => 'Огурцы Кисловодские',
            'code' => '24',
            'barcodes' => ['0000000000024'],
            'measurement_unit' => 'gram',
            'unit_type' => 'kg',
            'is_active' => false,
        ]);

        // Весовой недоступен → «не найден», а не штучный товар с тем же кодом.
        $response = $this->getJson('/api/v1/products/search/barcode/2200024005000');

        $response->assertStatus(404);
    }

    public function test_weighted_barcode_still_finds_piece_product_when_code_is_not_shared(): void
    {
        $store = Store::factory()->create();
        $piece = Product::factory()->create([
            'store_id' => $store->id,
            'title' => 'Борщ Красный Овощной Постный Ласточка',
            'code' => '25',
            'barcodes' => ['2100100065777'],
            'measurement_unit' => 'piece',
            'is_active' => true,
        ]);

        // Штучный отсекается, только если тот же код есть у весового товара.
        $response = $this->getJson('/api/v1/products/search/barcode/2200025001000');

        $response->assertStatus(200)
            ->assertJson(['data' => ['id' => $piece->id]]);
    }

    public function test_weighted_barcode_with_unknown_code_returns_404(): void
    {
        $store = Store::factory()->create();
        Product::factory()->create([
            'store_id' => $store->id,
            'code' => '296',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/2399999000000');

        $response->assertStatus(404);
    }

    public function test_non_weighted_barcode_does_not_match_by_code(): void
    {
        // Обычный штрихкод (не «23») не должен искаться по code — только по barcodes.
        $store = Store::factory()->create();
        Product::factory()->create([
            'store_id' => $store->id,
            'code' => '296',
            'barcodes' => ['4606055986816'],
            'is_active' => true,
        ]);

        // Штрихкод «296» как обычный — товар по barcodes не найдётся (фолбэк, не code).
        $response = $this->getJson('/api/v1/products/search/barcode/296');

        $response->assertStatus(404);
    }

    public function test_search_product_by_barcode_loads_relations(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'barcodes' => ['1234567890'],
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/products/search/barcode/1234567890');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'sku',
                    'title',
                    'price',
                    'images',
                ],
            ]);

        // Проверяем, что в ответе есть данные связей
        $responseData = $response->json('data');
        $this->assertArrayHasKey('images', $responseData);
    }
}