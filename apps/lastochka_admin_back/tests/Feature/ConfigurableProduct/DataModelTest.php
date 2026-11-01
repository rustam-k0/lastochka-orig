<?php

declare(strict_types=1);

namespace Tests\Feature\ConfigurableProduct;

use App\Models\Attribute;
use App\Models\AttributeFamily;
use App\Models\AttributeFamilyAttribute;
use App\Models\AttributeValue;
use App\Models\Img;
use App\Models\JoinImg;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeChangesLog;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use DatabaseTransactions;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'title' => 'Test Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    public function test_soft_delete_preserves_variant_in_orders(): void
    {
        $variant = Product::create([
            'title' => 'Variant',
            'shop_id' => $this->shop->id,
            'articul' => 'VAR-1',
            'unit_type_id' => Type::UNIT_PIECE,
            'status_id' => Status::CODE_NOT_PUBLISHED,
        ]);

        $variant->delete();

        $this->assertSoftDeleted('products', ['id' => $variant->id]);
        $withTrashed = Product::withTrashed()->find($variant->id);
        $this->assertNotNull($withTrashed);
        $this->assertNotNull($withTrashed->deleted_at);
    }

    public function test_effective_price_fallbacks_to_parent_when_variant_null(): void
    {
        $parent = Product::create([
            'title' => 'Parent',
            'shop_id' => $this->shop->id,
            'is_configurable' => true,
            'unit_type_id' => Type::UNIT_PIECE,
            'status_id' => Status::CODE_NOT_PUBLISHED,
        ]);
        Price::create([
            'product_id' => $parent->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 500,
            'step' => 1,
            'shop_id' => $this->shop->id,
        ]);

        $variant = Product::create([
            'title' => 'Variant A',
            'shop_id' => $this->shop->id,
            'parent_id' => $parent->id,
            'unit_type_id' => Type::UNIT_PIECE,
            'status_id' => Status::CODE_NOT_PUBLISHED,
        ]);

        $this->assertEqualsWithDelta(500.0, $variant->fresh()->effective_price, 0.01);
    }

    public function test_effective_price_uses_own_value_when_present(): void
    {
        $parent = Product::create([
            'title' => 'Parent',
            'shop_id' => $this->shop->id,
            'is_configurable' => true,
            'unit_type_id' => Type::UNIT_PIECE,
            'status_id' => Status::CODE_NOT_PUBLISHED,
        ]);
        Price::create([
            'product_id' => $parent->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 500,
            'step' => 1,
            'shop_id' => $this->shop->id,
        ]);

        $variant = Product::create([
            'title' => 'Variant B',
            'shop_id' => $this->shop->id,
            'parent_id' => $parent->id,
            'unit_type_id' => Type::UNIT_PIECE,
            'status_id' => Status::CODE_NOT_PUBLISHED,
        ]);
        Price::create([
            'product_id' => $variant->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 799,
            'step' => 1,
            'shop_id' => $this->shop->id,
        ]);

        $this->assertEqualsWithDelta(799.0, $variant->fresh()->effective_price, 0.01);
    }

    public function test_attribute_value_code_cannot_change_after_use(): void
    {
        $attribute = Attribute::create([
            'title' => 'Color-' . uniqid(),
            'code' => 'color_' . uniqid(),
            'type' => 'string',
        ]);
        $value = AttributeValue::create([
            'title' => 'Red',
            'code' => 'red',
            'attribute_id' => $attribute->id,
        ]);

        $product = Product::create([
            'title' => 'Product with attr',
            'shop_id' => $this->shop->id,
            'unit_type_id' => Type::UNIT_PIECE,
            'status_id' => Status::CODE_NOT_PUBLISHED,
        ]);
        ProductAttribute::create([
            'product_id' => $product->id,
            'attribute_id' => $attribute->id,
            'attribute_value_id' => $value->id,
            'string_value' => 'Red',
        ]);

        $this->expectException(\DomainException::class);
        $value->update(['code' => 'red2']);
    }

    public function test_attribute_value_code_can_change_before_use(): void
    {
        $attribute = Attribute::create([
            'title' => 'Size-' . uniqid(),
            'code' => 'size_' . uniqid(),
            'type' => 'string',
        ]);
        $value = AttributeValue::create([
            'title' => 'S',
            'code' => 'small',
            'attribute_id' => $attribute->id,
        ]);

        $value->update(['code' => 'sm']);

        $this->assertSame('sm', $value->fresh()->code);
    }

    public function test_product_attribute_changes_log_created(): void
    {
        $product = Product::create([
            'title' => 'Logged',
            'shop_id' => $this->shop->id,
            'unit_type_id' => Type::UNIT_PIECE,
            'status_id' => Status::CODE_NOT_PUBLISHED,
        ]);

        $log = ProductAttributeChangesLog::create([
            'product_id' => $product->id,
            'operation' => ProductAttributeChangesLog::OPERATION_GENERATE_VARIANTS,
            'payload' => ['before' => null, 'after' => ['variants_created' => 2]],
            'admin_id' => null,
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('product_attribute_changes_log', [
            'id' => $log->id,
            'product_id' => $product->id,
            'operation' => ProductAttributeChangesLog::OPERATION_GENERATE_VARIANTS,
        ]);

        $loaded = ProductAttributeChangesLog::find($log->id);
        // MySQL JSON хранит ключи отсортированными по длине, порядок before/after не сохраняется
        $this->assertEqualsCanonicalizing(['before', 'after'], array_keys($loaded->payload));
        $this->assertNull($loaded->payload['before']);
        $this->assertSame(['variants_created' => 2], $loaded->payload['after']);
    }

    public function test_attribute_family_attribute_pivot_model_accessible(): void
    {
        $family = AttributeFamily::create([
            'title' => 'Clothing',
            'code' => 'clothing_' . uniqid(),
        ]);
        $attribute = Attribute::create([
            'title' => 'Color-' . uniqid(),
            'code' => 'color_' . uniqid(),
            'type' => 'string',
        ]);

        $pivot = AttributeFamilyAttribute::create([
            'attribute_family_id' => $family->id,
            'attribute_id' => $attribute->id,
            'position' => 0,
            'is_required' => true,
            'is_configurable' => true,
        ]);

        $this->assertTrue($pivot->is_configurable);
        $this->assertTrue($pivot->is_required);
        $this->assertSame($family->id, $pivot->family->id);
        $this->assertSame($attribute->id, $pivot->attribute->id);
    }

    public function test_unique_articul_per_shop_enforced(): void
    {
        Product::create([
            'title' => 'A',
            'shop_id' => $this->shop->id,
            'articul' => 'UNIQ-123',
            'unit_type_id' => Type::UNIT_PIECE,
            'status_id' => Status::CODE_NOT_PUBLISHED,
        ]);

        // Должно сработать в другом shop
        $otherShop = Shop::create([
            'title' => 'Other Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
        Product::create([
            'title' => 'B',
            'shop_id' => $otherShop->id,
            'articul' => 'UNIQ-123',
            'unit_type_id' => Type::UNIT_PIECE,
            'status_id' => Status::CODE_NOT_PUBLISHED,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Product::create([
            'title' => 'C',
            'shop_id' => $this->shop->id,
            'articul' => 'UNIQ-123',
            'unit_type_id' => Type::UNIT_PIECE,
            'status_id' => Status::CODE_NOT_PUBLISHED,
        ]);
    }
}
