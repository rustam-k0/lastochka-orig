<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Store;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Регресс на `1406 Data too long for column 'value'` (2026-07-07).
 *
 * `product_attributes.value` был varchar(500), а реальные составы из 1С
 * (реквизит «Состав») достигают 800-1200+ символов → insert падал, всё
 * событие product.updated уходило в ретраи/DLQ, товар не обновлялся.
 * Колонка расширена до TEXT (в индексах value не участвует).
 */
final class ProductAttributeLongValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_value_column_is_text(): void
    {
        $this->assertSame('text', Schema::getColumnType('product_attributes', 'value'));
    }

    public function test_event_with_long_composition_attribute_is_processed(): void
    {
        Store::factory()->create(['code' => 'MSK_LONG_ATTR']);

        // Реальный порядок величины: составы из 1С бывают 1200+ символов.
        $composition = 'Мука пшеничная хлебопекарная высший сорт, вода, '
            .Str::repeat('жир специального назначения (рафинированные дезодорированные масла), ', 20)
            .'соль, глютен пшеничный, стабилизатор Е466.';
        $this->assertGreaterThan(500, mb_strlen($composition));

        $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
        $result = $consumer->processEvent([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_long_attr',
            'source' => 'bagisto',
            'shop_code' => 'MSK_LONG_ATTR',
            'sku' => 'LONG-ATTR-1',
            'title' => 'Лаваш с длинным составом',
            'price' => 100.00,
            'active' => true,
            'attributes' => [
                ['code' => 'composition', 'name' => 'Состав', 'value' => $composition],
            ],
        ]);

        $this->assertEquals('ack', $result);

        $product = Product::where('sku', 'LONG-ATTR-1')->firstOrFail();
        $attribute = ProductAttribute::where('product_id', $product->id)
            ->where('code', 'composition')
            ->firstOrFail();

        $this->assertSame($composition, $attribute->value);
        $this->assertSame($composition, $product->fresh()->composition);
    }
}
