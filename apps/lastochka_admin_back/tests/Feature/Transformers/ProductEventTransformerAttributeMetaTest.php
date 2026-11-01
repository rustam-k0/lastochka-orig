<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Shop;
use App\Models\Type;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductEventTransformerAttributeMetaTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): Shop
    {
        return Shop::create([
            'title' => 'TS ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    private function makeProduct(Shop $shop): Product
    {
        return Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
        ]);
    }

    public function test_attribute_entry_includes_description_and_packaging_group(): void
    {
        $shop = $this->makeShop();
        $product = $this->makeProduct($shop);

        $attribute = Attribute::create([
            'title' => 'Вес упаковки',
            'code' => 'pack_weight_' . uniqid(),
            'type_id' => Type::ATTRIBUTE_STRING,
            'category_type_id' => Type::ATTRIBUTE_PACKAGING,
            'description' => 'Масса товара в заводской упаковке',
        ]);

        ProductAttribute::create([
            'product_id' => $product->id,
            'attribute_id' => $attribute->id,
            'string_value' => '1.2 кг',
        ]);

        $payload = app(ProductEventTransformer::class)
            ->toEvent($product->fresh()->load('attributes'));

        $entry = collect($payload['attributes'])->firstWhere('code', $attribute->code);

        $this->assertNotNull($entry, 'атрибут должен попасть в payload');
        $this->assertSame('Масса товара в заводской упаковке', $entry['description']);
        $this->assertSame('packaging', $entry['group']);
    }

    public function test_attribute_entry_defaults_general_group_and_null_description(): void
    {
        $shop = $this->makeShop();
        $product = $this->makeProduct($shop);

        $attribute = Attribute::create([
            'title' => 'Род тока',
            'code' => 'current_kind_' . uniqid(),
            'type_id' => Type::ATTRIBUTE_STRING,
            'category_type_id' => Type::ATTRIBUTE_OTHER,
        ]);

        ProductAttribute::create([
            'product_id' => $product->id,
            'attribute_id' => $attribute->id,
            'string_value' => 'Переменный (AC)',
        ]);

        $payload = app(ProductEventTransformer::class)
            ->toEvent($product->fresh()->load('attributes'));

        $entry = collect($payload['attributes'])->firstWhere('code', $attribute->code);

        $this->assertNotNull($entry);
        $this->assertArrayHasKey(
            'description',
            $entry,
            'ключ обязан присутствовать и при null — сброс описания должен доезжать до storefront'
        );
        $this->assertNull($entry['description']);
        $this->assertSame('general', $entry['group']);
    }

    public function test_composition_attribute_gets_composition_group(): void
    {
        $shop = $this->makeShop();
        $product = $this->makeProduct($shop);

        $attribute = Attribute::create([
            'title' => 'Состав',
            'code' => 'composition_' . uniqid(),
            'type_id' => Type::ATTRIBUTE_TEXT,
            'category_type_id' => Type::ATTRIBUTE_COMPOSITION,
        ]);

        ProductAttribute::create([
            'product_id' => $product->id,
            'attribute_id' => $attribute->id,
            'text_value' => 'Медь, пластик',
        ]);

        $payload = app(ProductEventTransformer::class)
            ->toEvent($product->fresh()->load('attributes'));

        $entry = collect($payload['attributes'])->firstWhere('code', $attribute->code);

        $this->assertNotNull($entry);
        $this->assertSame('composition', $entry['group']);
    }
}
