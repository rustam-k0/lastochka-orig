<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));

        Storage::fake('public');
    }

    public function test_product_created_event_creates_product(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_001',
            'sku' => 'MILK-32',
            'title' => 'Молоко 3.2%',
            'price' => 89.00,
            'quantity' => 50,
            'unit_type' => 'piece',
            'description' => 'Свежее молоко 3.2% жирности',
            'weight' => 1000,
            'nds' => 10,
            'active' => true,
            'is_culinary' => false,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('products', [
            'store_id' => $store->id,
            'source' => 'bagisto',
            'sku' => 'MILK-32',
            'title' => 'Молоко 3.2%',
            'price' => 89,
            'price_old' => null,
            'quantity' => 50,
            'unit_type' => 'piece',
            'description' => 'Свежее молоко 3.2% жирности',
            'weight' => 1000,
            'vat_rate' => 10,
            'is_active' => true,
            'is_culinary' => false,
        ]);
    }

    public function test_product_created_with_discount_price_inverts_prices(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_002']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_002',
            'sku' => 'MILK-SALE',
            'title' => 'Молоко со скидкой',
            'price' => 89.00,           // Базовая цена
            'discount_price' => 76.00,  // Цена со скидкой
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'MILK-SALE')->first();
        $this->assertNotNull($product);

        // Проверяем инверсию цен
        $this->assertEquals(76, $product->price);      // discount_price → price
        $this->assertEquals(89, $product->price_old);  // price → price_old
    }

    public function test_product_without_discount_has_no_price_old(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_003']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_003',
            'sku' => 'NO-DISCOUNT',
            'title' => 'Товар без скидки',
            'price' => 100.00,
            // discount_price отсутствует
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'NO-DISCOUNT')->first();
        $this->assertNotNull($product);
        $this->assertEquals(100, $product->price);
        $this->assertNull($product->price_old);
    }

    public function test_stock_changed_event_updates_only_quantity(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_004']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'STOCK-TEST',
            'title' => 'Тестовый товар',
            'price' => 100,
            'quantity' => 100,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_stock',
            'source' => 'bagisto',
            'shop_code' => 'MSK_004',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subMinute(),
        ]);

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'stock_changed',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_stock',
            'shop_code' => 'MSK_004',
            'source' => 'bagisto',
            'quantity' => 45,
        ];

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertEquals(45, $product->fresh()->quantity);

        // Другие поля не должны измениться
        $this->assertEquals(100, $product->fresh()->price);
        $this->assertEquals('Тестовый товар', $product->fresh()->title);
    }

    public function test_attributes_synced_and_nutrition_extracted(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_005']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_005',
            'sku' => 'NUTRITION-TEST',
            'title' => 'Товар с КБЖУ',
            'price' => 50.00,
            'attributes' => [
                ['code' => 'calories', 'name' => 'Калории', 'value' => 60],
                ['code' => 'proteins', 'name' => 'Белки', 'value' => 3.2],
                ['code' => 'fats', 'name' => 'Жиры', 'value' => 3.2],
                ['code' => 'carbs', 'name' => 'Углеводы', 'value' => 4.7],
                ['code' => 'shelf_life', 'name' => 'Срок хранения', 'value' => '14 дней'],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'NUTRITION-TEST')->first();
        $this->assertNotNull($product);

        // Проверяем КБЖУ в основной модели
        $this->assertEquals(60.00, $product->calories);
        $this->assertEquals(3.20, $product->proteins);
        $this->assertEquals(3.20, $product->fats);
        $this->assertEquals(4.70, $product->carbohydrates);

        // Проверяем атрибуты
        $this->assertCount(5, $product->attributes);
        $this->assertDatabaseHas('product_attributes', [
            'product_id' => $product->id,
            'code' => 'shelf_life',
            'name' => 'Срок хранения',
            'value' => '14 дней',
        ]);
    }

    public function test_nutrition_extracted_from_string_values_with_commas(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_COMMA']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_COMMA',
            'sku' => 'COMMA-NUTRITION-TEST',
            'title' => 'Товар с КБЖУ в строковом формате',
            'price' => 85.00,
            'attributes' => [
                ['code' => 'calories', 'name' => 'Ккал', 'value' => '51,2 ккал(200 кДж)'],
                ['code' => 'proteins', 'name' => 'Белки', 'value' => '0'],
                ['code' => 'fats', 'name' => 'Жиры', 'value' => '0'],
                ['code' => 'carbs', 'name' => 'Углеводы', 'value' => '12,5'],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'COMMA-NUTRITION-TEST')->first();
        $this->assertNotNull($product);

        $this->assertEquals(51.20, $product->calories);
        $this->assertEquals(0.00, $product->proteins);
        $this->assertEquals(0.00, $product->fats);
        $this->assertEquals(12.50, $product->carbohydrates);
    }

    public function test_composition_extracted_from_attributes(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_COMP']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_COMP',
            'sku' => 'COMP-TEST',
            'title' => 'Товар с составом',
            'price' => 50.00,
            'attributes' => [
                ['code' => 'composition', 'name' => 'Состав', 'value' => 'Молоко цельное, молоко обезжиренное'],
                ['code' => 'shelf_life', 'name' => 'Срок хранения', 'value' => '14 дней'],
                ['code' => 'storage_conditions', 'name' => 'Условия хранения', 'value' => 'от +2 до +6 °С'],
                ['code' => 'proteins', 'name' => 'Белки', 'value' => 3.2],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'COMP-TEST')->first();
        $this->assertNotNull($product);

        // Состав должен быть извлечён в основную модель
        $this->assertEquals('Молоко цельное, молоко обезжиренное', $product->composition);

        // КБЖУ тоже извлекается
        $this->assertEquals(3.20, $product->proteins);

        // Все атрибуты должны быть сохранены в product_attributes
        $this->assertCount(4, $product->attributes);
        $this->assertDatabaseHas('product_attributes', [
            'product_id' => $product->id,
            'code' => 'storage_conditions',
            'value' => 'от +2 до +6 °С',
        ]);
    }

    public function test_image_downloaded_on_product_create(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_006']);

        Http::fake([
            'https://cdn.example.com/*' => Http::response(
                file_get_contents(__DIR__.'/../../fixtures/test_image.png') ?: 'fake-image-content',
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_006',
            'sku' => 'IMG-TEST',
            'title' => 'Товар с изображением',
            'price' => 100.00,
            'image_url' => 'https://cdn.example.com/products/milk.jpg',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'IMG-TEST')->first();
        $this->assertNotNull($product);

        $image = ProductImage::where('product_id', $product->id)->first();
        $this->assertNotNull($image);
        $this->assertStringStartsWith('products/', $image->path);
        $this->assertEquals(0, $image->position);
    }

    public function test_category_pending_reference_created_when_category_not_exists(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_007']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_007',
            'sku' => 'CAT-PENDING',
            'title' => 'Товар с несуществующей категорией',
            'price' => 100.00,
            'category_id' => 'cat_nonexistent',
            'category_source' => 'bagisto',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'CAT-PENDING')->first();
        $this->assertNotNull($product);

        // После Phase E: multi-category через pivot. Если категория не найдена — pivot пустой.
        $this->assertCount(0, $product->categories);
        $this->assertNull($product->category_id); // accessor возвращает null при пустом pivot
    }

    public function test_category_linked_when_exists(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_008']);
        $category = Category::factory()->forStore($store)->create([
            'name' => 'Молочные продукты',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
            'external_id' => 'cat_milk',
            'source' => 'bagisto',
            'shop_code' => 'MSK_008',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_008',
            'sku' => 'CAT-LINKED',
            'title' => 'Товар с категорией',
            'price' => 100.00,
            'category_id' => 'cat_milk',
            'category_source' => 'bagisto',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'CAT-LINKED')->first();
        $this->assertNotNull($product);

        // После Phase E: категория привязана через pivot category_product
        $this->assertDatabaseHas('category_product', [
            'product_id' => $product->id,
            'category_id' => $category->id,
        ]);

        // accessor getCategoryAttribute() возвращает первую Category из pivot
        $linkedCategory = $product->categories()->first();
        $this->assertNotNull($linkedCategory);
        $this->assertEquals($category->id, $linkedCategory->id);
    }

    public function test_product_updated_event_updates_product(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_009']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'UPDATE-TEST',
            'title' => 'Старое название',
            'price' => 100,
            'is_active' => true,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_update',
            'source' => 'bagisto',
            'shop_code' => 'MSK_009',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_update',
            'shop_code' => 'MSK_009',
            'sku' => 'UPDATE-TEST',
            'title' => 'Новое название',
            'price' => 150.00,
            'active' => false,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product->refresh();
        $this->assertEquals('Новое название', $product->title);
        $this->assertEquals(150, $product->price);
        $this->assertFalse($product->is_active);
    }

    public function test_product_update_without_active_preserves_deactivation(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_DEACT']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'DEACTIVATED-PRODUCT',
            'title' => 'Деактивированный товар',
            'price' => 100,
            'is_active' => false,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_deact',
            'source' => 'bagisto',
            'shop_code' => 'MSK_DEACT',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Событие из 1С без поля active — не должно реактивировать товар
        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_deact',
            'shop_code' => 'MSK_DEACT',
            'sku' => 'DEACTIVATED-PRODUCT',
            'title' => 'Деактивированный товар',
            'price' => 120.00,
        ]);
        unset($eventData['active']);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product->refresh();
        $this->assertEquals(120, $product->price);
        $this->assertFalse($product->is_active, 'Товар не должен реактивироваться при обновлении без поля active');
    }

    public function test_product_deleted_event_soft_deletes_product(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_010']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'DELETE-TEST',
            'title' => 'Товар для удаления',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_delete',
            'source' => 'bagisto',
            'shop_code' => 'MSK_010',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'prod_delete',
            'shop_code' => 'MSK_010',
            'sku' => 'DELETE-TEST',
            'title' => 'Товар для удаления',
            'price' => 100.00,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertSoftDeleted('products', [
            'id' => $product->id,
        ]);
    }

    public function test_barcodes_saved_as_json(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_011']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_011',
            'sku' => 'BARCODE-TEST',
            'title' => 'Товар со штрихкодами',
            'price' => 100.00,
            'barcodes' => ['4607001234567', '4607001234568'],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'BARCODE-TEST')->first();
        $this->assertNotNull($product);
        $this->assertIsArray($product->barcodes);
        $this->assertCount(2, $product->barcodes);
        $this->assertEquals('4607001234567', $product->barcodes[0]);
    }

    public function test_sku_collision_creates_new_product_with_synthetic_sku(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_012']);

        // Существующий товар с тем же sku (легаси / ручная карточка / другой источник)
        $existingProduct = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'EXISTING-SKU',
            'title' => 'Существующий товар',
            'price' => 50,
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'ext_prod_12',
            'shop_code' => 'MSK_012',
            'sku' => 'EXISTING-SKU',
            'title' => 'Новая 1С позиция',
            'price' => 75.00,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        // Sku-collision protection: НЕ binding на existing, создан новый продукт с synthetic sku.
        $this->assertEquals(2, Product::where('store_id', $store->id)->count());

        // Existing товар сохранён без изменений
        $existingProduct->refresh();
        $this->assertEquals('Существующий товар', $existingProduct->title);
        $this->assertEquals(50.0, (float) $existingProduct->price);

        // Новый товар создан с synthetic sku '1C-{sku}-{external_id_short}'
        $newProduct = Product::where('store_id', $store->id)
            ->where('id', '!=', $existingProduct->id)
            ->first();
        $this->assertNotNull($newProduct);
        $this->assertSame('1C-EXISTING-SKU-extprod1', $newProduct->sku);
        $this->assertSame('Новая 1С позиция', $newProduct->title);

        // Metadata привязана к НОВОЙ product, не existing
        $this->assertDatabaseHas('event_sync_metadata', [
            'entity_type' => 'products',
            'entity_id' => $newProduct->id,
            'external_id' => 'ext_prod_12',
            'source' => 'bagisto',
        ]);
    }

    public function test_product_event_throws_when_store_missing(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'NON_EXISTENT_SHOP',
            'sku' => 'NO-STORE',
            'title' => 'Товар без магазина',
            'price' => 100.00,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no store found for shop_code=NON_EXISTENT_SHOP/');

        try {
            $this->consumer->processEvent($eventData);
        } finally {
            $this->assertDatabaseMissing('products', ['sku' => 'NO-STORE']);
            $this->assertDatabaseMissing('stores', ['code' => 'NON_EXISTENT_SHOP']);
        }
    }

    public function test_duplicate_event_is_skipped(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_013']);
        $eventId = Str::uuid()->toString();

        $eventData = $this->buildEventData([
            'event_id' => $eventId,
            'event_type' => 'created',
            'shop_code' => 'MSK_013',
            'sku' => 'UNIQUE-PRODUCT',
            'title' => 'Уникальный товар',
            'price' => 100.00,
        ]);

        // Первый раз — создаём товар
        $result1 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result1);

        // Второй раз с тем же event_id — должен быть пропущен
        $result2 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result2);

        // Товар должен быть только один
        $this->assertEquals(1, Product::where('store_id', $store->id)->count());
    }

    public function test_unit_type_kg_mapped_correctly(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_014']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_014',
            'sku' => 'KG-PRODUCT',
            'title' => 'Весовой товар',
            'price' => 200.00,
            'unit_type' => 'kg',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'KG-PRODUCT')->first();
        $this->assertNotNull($product);
        $this->assertEquals('kg', $product->unit_type->value);
    }

    public function test_validation_fails_without_required_fields(): void
    {
        $this->expectException(\Comrades\RabbitMQEvents\Exceptions\InvalidEventException::class);

        // Событие без обязательного поля price
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_invalid',
            'source' => 'bagisto',
            'shop_code' => 'MSK_015',
            'title' => 'Товар без цены',
            // price отсутствует
        ];

        $this->consumer->processEvent($eventData);
    }

    public function test_multiple_images_downloaded_on_product_create(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_MULTI_IMG']);

        Http::fake([
            'https://cdn.example.com/*' => Http::response(
                file_get_contents(__DIR__.'/../../fixtures/test_image.png') ?: 'fake-image-content',
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_MULTI_IMG',
            'sku' => 'MULTI-IMG-TEST',
            'title' => 'Товар с несколькими фото',
            'price' => 100.00,
            'image_url' => 'https://cdn.example.com/products/main.jpg',
            'image_urls' => [
                'https://cdn.example.com/products/main.jpg',
                'https://cdn.example.com/products/slide1.jpg',
                'https://cdn.example.com/products/slide2.jpg',
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'MULTI-IMG-TEST')->first();
        $this->assertNotNull($product);
        $this->assertCount(3, $product->images);
        $this->assertEquals(0, $product->images->sortBy('position')->values()[0]->position);
        $this->assertEquals(1, $product->images->sortBy('position')->values()[1]->position);
        $this->assertEquals(2, $product->images->sortBy('position')->values()[2]->position);
    }

    public function test_image_urls_replaces_all_images_on_update(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_REPLACE_IMG']);

        Http::fake([
            'https://cdn.example.com/*' => Http::response(
                file_get_contents(__DIR__.'/../../fixtures/test_image.png') ?: 'fake-image-content',
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        // Создаём товар с одним фото
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'prod_replace_img',
            'shop_code' => 'MSK_REPLACE_IMG',
            'sku' => 'REPLACE-IMG-TEST',
            'title' => 'Товар для замены фото',
            'price' => 100.00,
            'image_url' => 'https://cdn.example.com/products/old.jpg',
        ]);

        $this->consumer->processEvent($eventData);

        $product = Product::where('sku', 'REPLACE-IMG-TEST')->first();
        $this->assertCount(1, $product->images);

        // Обновляем с тремя фото через image_urls
        $updateData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_replace_img',
            'shop_code' => 'MSK_REPLACE_IMG',
            'sku' => 'REPLACE-IMG-TEST',
            'title' => 'Товар для замены фото',
            'price' => 100.00,
            'image_urls' => [
                'https://cdn.example.com/products/new-main.jpg',
                'https://cdn.example.com/products/new-slide1.jpg',
            ],
        ]);

        $this->consumer->processEvent($updateData);

        $product->refresh();
        $images = $product->images()->orderBy('position')->get();
        $this->assertCount(2, $images);
        $this->assertEquals(0, $images[0]->position);
        $this->assertEquals(1, $images[1]->position);
    }

    public function test_fallback_to_single_image_url_when_no_image_urls(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_FALLBACK_IMG']);

        Http::fake([
            'https://cdn.example.com/*' => Http::response(
                file_get_contents(__DIR__.'/../../fixtures/test_image.png') ?: 'fake-image-content',
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_FALLBACK_IMG',
            'sku' => 'FALLBACK-IMG-TEST',
            'title' => 'Товар с одним фото (fallback)',
            'price' => 100.00,
            'image_url' => 'https://cdn.example.com/products/single.jpg',
        ]);
        // image_urls отсутствует — проверяем обратную совместимость

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'FALLBACK-IMG-TEST')->first();
        $this->assertNotNull($product);
        $this->assertCount(1, $product->images);
        $this->assertEquals(0, $product->images->first()->position);
    }

    public function test_empty_attributes_in_payload_deletes_existing(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_EMPTY']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'CLEAR-ATTRS',
            'title' => 'Товар с атрибутами на снятие',
            'price' => 200,
        ]);

        \App\Models\ProductAttribute::create([
            'product_id' => $product->id,
            'code' => 'material',
            'name' => 'Материал',
            'value' => 'Пластик',
            'attribute_value_id' => 3,
            'attribute_value_code' => 'plastik',
            'position' => 0,
        ]);

        \Comrades\RabbitMQEvents\Models\EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_clear',
            'source' => 'bagisto',
            'shop_code' => 'MSK_EMPTY',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subMinute(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_clear',
            'shop_code' => 'MSK_EMPTY',
            'sku' => 'CLEAR-ATTRS',
            'title' => 'Товар с атрибутами на снятие',
            'price' => 200,
            'attributes' => [],
        ]);

        $this->consumer->processEvent($eventData);

        $this->assertSame(0, $product->fresh()->attributes()->count());
    }

    public function test_missing_attributes_key_keeps_existing(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_KEEP']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'KEEP-ATTRS',
            'title' => 'Товар с сохраняемыми атрибутами',
            'price' => 300,
        ]);

        \App\Models\ProductAttribute::create([
            'product_id' => $product->id,
            'code' => 'material',
            'name' => 'Материал',
            'value' => 'Пластик',
            'attribute_value_id' => 3,
            'attribute_value_code' => 'plastik',
            'position' => 0,
        ]);

        \Comrades\RabbitMQEvents\Models\EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_keep',
            'source' => 'bagisto',
            'shop_code' => 'MSK_KEEP',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subMinute(),
        ]);

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'stock_changed',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_keep',
            'shop_code' => 'MSK_KEEP',
            'source' => 'bagisto',
            'quantity' => 42,
        ];

        $this->consumer->processEvent($eventData);

        $this->assertSame(1, $product->fresh()->attributes()->count());
        $this->assertSame('plastik', $product->fresh()->attributes()->first()->attribute_value_code);
    }

    public function test_select_attribute_value_code_is_persisted(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_ATTR']);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_ATTR',
            'sku' => 'SWITCH-1',
            'title' => 'Выключатель 1P',
            'price' => 450.00,
            'quantity' => 10,
            'unit_type' => 'piece',
            'attributes' => [
                [
                    'code' => 'manufacturer',
                    'name' => 'Производитель',
                    'value' => 'Schneider Electric',
                    'attribute_value_id' => 42,
                    'attribute_value_code' => 'schneider',
                ],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('product_attributes', [
            'code' => 'manufacturer',
            'attribute_value_id' => 42,
            'attribute_value_code' => 'schneider',
        ]);
    }

    public function test_product_update_with_explicit_null_category_unlinks(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_UNL']);
        $category = Category::factory()->forStore($store)->create(['name' => 'Живая категория']);
        // Phase E: инициализируем через pivot
        $product = Product::factory()->inCategory($category)->create([
            'store_id' => $store->id,
            'sku' => 'UNL-1',
            'is_active' => true,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_unl',
            'source' => 'bagisto',
            'shop_code' => 'MSK_UNL',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_unl',
            'shop_code' => 'MSK_UNL',
            'sku' => 'UNL-1',
            'title' => 'Обновлённый',
            'price' => 150,
            'category_id' => null,
            'category_source' => null,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        // Phase E: category_id=null в payload → detach всех; accessor возвращает null
        $this->assertCount(0, $product->fresh('categories')->categories);
        $this->assertNull($product->fresh()->categories()->first());
    }

    public function test_product_update_without_category_key_preserves_category(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_KEEP']);
        $category = Category::factory()->forStore($store)->create(['name' => 'Сохраняемая']);
        // Phase E: инициализируем через pivot
        $product = Product::factory()->inCategory($category)->create([
            'store_id' => $store->id,
            'sku' => 'KEEP-1',
            'is_active' => true,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_keep',
            'source' => 'bagisto',
            'shop_code' => 'MSK_KEEP',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Частичный payload — без ключа category_id/category_ids/category_source
        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_keep',
            'shop_code' => 'MSK_KEEP',
            'sku' => 'KEEP-1',
            'title' => 'Обновлённый',
            'price' => 200,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        // Phase E: partial update (нет category_ids/category_id ключа) — pivot не трогаем
        $firstCat = $product->fresh('categories')->categories()->first();
        $this->assertNotNull($firstCat);
        $this->assertEquals($category->id, $firstCat->id);
    }

    public function test_product_created_event_sets_served_warm(): void
    {
        Store::factory()->create(['code' => 'MSK_WARM']);

        $result = $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_WARM',
            'sku' => 'WARM-1',
            'served_warm' => true,
        ]));

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('products', ['sku' => 'WARM-1', 'served_warm' => 1]);
    }

    public function test_product_update_without_served_warm_preserves_value(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_WARM2']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'WARM-2',
            'price' => 100,
            'served_warm' => true,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_warm2',
            'source' => 'bagisto',
            'shop_code' => 'MSK_WARM2',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Партиальное событие в стиле 1С/RetailCRM — без ключа served_warm.
        $result = $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_warm2',
            'shop_code' => 'MSK_WARM2',
            'sku' => 'WARM-2',
            'title' => 'Новое название',
        ]));

        $this->assertEquals('ack', $result);
        $this->assertTrue($product->refresh()->served_warm, 'событие без ключа served_warm не должно затирать флаг');
    }

    public function test_product_update_without_flag_keys_preserves_flags(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_FLAGS']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'FLAGS-1',
            'price' => 100,
            'is_culinary' => true,
            'sugar_free' => true,
            'on_search_page' => true,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'prod_flags',
            'source' => 'bagisto',
            'shop_code' => 'MSK_FLAGS',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        // Импорт каталога 1С: product.updated без бизнес-флагов админки.
        $result = $this->consumer->processEvent($this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_flags',
            'shop_code' => 'MSK_FLAGS',
            'sku' => 'FLAGS-1',
            'title' => 'Обновление из 1С',
        ]));

        $this->assertEquals('ack', $result);
        $product->refresh();
        $this->assertTrue($product->is_culinary, 'партиальное событие затёрло is_culinary');
        $this->assertTrue($product->sugar_free, 'партиальное событие затёрло sugar_free');
        $this->assertTrue($product->on_search_page, 'партиальное событие затёрло on_search_page');
    }

    /**
     * Построить данные события с дефолтными значениями.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_'.Str::random(5),
            'source' => 'bagisto',
            'shop_code' => 'TEST_'.Str::random(3),
            'sku' => 'SKU-'.Str::random(5),
            'title' => 'Тестовый товар',
            'price' => 100.00,
            'active' => true,
        ], $overrides);
    }
}
