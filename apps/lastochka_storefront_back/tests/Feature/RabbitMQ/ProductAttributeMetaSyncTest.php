<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Store;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ELH-117: атрибуты приезжают из admin_back с описанием (INFO-модалка)
 * и семантической группой (вкладки «Общие» / «Параметры упаковки»).
 */
final class ProductAttributeMetaSyncTest extends TestCase
{
    use RefreshDatabase;

    private function consume(array $overrides = []): string
    {
        $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));

        return $consumer->processEvent(array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_attr_meta',
            'source' => 'bagisto',
            'shop_code' => 'MSK_ATTR_META',
            'sku' => 'ATTR-META-1',
            'title' => 'Автомат с характеристиками',
            'price' => 100.00,
            'active' => true,
        ], $overrides));
    }

    public function test_attributes_with_description_and_group_are_persisted(): void
    {
        Store::factory()->create(['code' => 'MSK_ATTR_META']);

        $result = $this->consume([
            'attributes' => [
                [
                    'code' => 'nominal_voltage',
                    'name' => 'Номинальное напряжение',
                    'value' => '230 В',
                    'description' => 'Заданное значение напряжения в нормальном режиме работы',
                    'group' => 'general',
                ],
                [
                    'code' => 'pack_weight',
                    'name' => 'Вес',
                    'value' => '1.2 кг',
                    'description' => null,
                    'group' => 'packaging',
                ],
            ],
        ]);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'ATTR-META-1')->firstOrFail();

        $voltage = ProductAttribute::where('product_id', $product->id)
            ->where('code', 'nominal_voltage')->firstOrFail();
        $this->assertSame(
            'Заданное значение напряжения в нормальном режиме работы',
            $voltage->description
        );
        $this->assertSame('general', $voltage->group_code);

        $weight = ProductAttribute::where('product_id', $product->id)
            ->where('code', 'pack_weight')->firstOrFail();
        $this->assertNull($weight->description);
        $this->assertSame('packaging', $weight->group_code);
    }

    public function test_attributes_without_meta_keys_get_nulls(): void
    {
        Store::factory()->create(['code' => 'MSK_ATTR_META']);

        $result = $this->consume([
            'attributes' => [
                ['code' => 'current_kind', 'name' => 'Род тока', 'value' => 'AC'],
            ],
        ]);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'ATTR-META-1')->firstOrFail();
        $attribute = ProductAttribute::where('product_id', $product->id)
            ->where('code', 'current_kind')->firstOrFail();

        $this->assertNull($attribute->description);
        $this->assertNull($attribute->group_code);
    }

    public function test_product_api_returns_attribute_description_and_group(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);

        ProductAttribute::create([
            'product_id' => $product->id,
            'code' => 'pack_height',
            'name' => 'Высота упаковки',
            'value' => '120 мм',
            'description' => 'Габарит заводской упаковки по вертикали',
            'group_code' => 'packaging',
            'position' => 0,
        ]);

        ProductAttribute::create([
            'product_id' => $product->id,
            'code' => 'gost',
            'name' => 'ГОСТ',
            'value' => 'IEC 60898-1',
            'position' => 1,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk();

        $attributes = collect($response->json('data.attributes'));

        $height = $attributes->firstWhere('key', 'Высота упаковки');
        $this->assertNotNull($height);
        $this->assertSame('Габарит заводской упаковки по вертикали', $height['description']);
        $this->assertSame('packaging', $height['group']);

        $gost = $attributes->firstWhere('key', 'ГОСТ');
        $this->assertNotNull($gost);
        $this->assertNull($gost['description']);
        $this->assertSame('general', $gost['group'], 'без группы атрибут падает в «Общие»');
    }
}
