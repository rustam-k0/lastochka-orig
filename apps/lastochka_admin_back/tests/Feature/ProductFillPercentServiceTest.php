<?php

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\DefaultAttribute;
use App\Models\Img;
use App\Models\JoinImg;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Services\ProductFillPercentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductFillPercentServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        // Детерминированный максимум: убираем веса атрибутов из локальной БД
        // (DatabaseTransactions откатит). Поля конфига дают max = 40.
        DefaultAttribute::query()->delete();

        $this->shop = Shop::create([
            'title' => 'Fill Test Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    protected function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'title' => 'Fill Test Product ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'publish_after_unlock' => false,
        ], $attributes));
    }

    protected function attachImg(Product $product): void
    {
        $img = Img::create([
            'path' => '/test.jpg',
            'origin_name' => 't.jpg',
            'alt' => '',
            'size' => 1,
            'type' => 'jpg',
        ]);
        JoinImg::create([
            'item_id' => $product->id,
            'img_id' => $img->id,
            'head_id' => Status::CODE_HEAD_IMG,
            'table_name' => 'products',
        ]);
    }

    /** @test */
    public function empty_product_gets_base_percent_only(): void
    {
        $product = $this->createProduct();

        // max = 40 (поля), набрано = 1 (база) → round(100*1/40) = 3
        $this->assertSame(3, app(ProductFillPercentService::class)->calculate($product));
    }

    /** @test */
    public function fully_filled_fields_give_100_percent(): void
    {
        $product = $this->createProduct([
            'articul' => 'ART-' . uniqid(),
            'barcode' => '4600000000000',
            'description' => 'Описание',
            'meta_title' => 't',
            'meta_description' => 'd',
            'meta_keywords' => 'k',
        ]);
        $this->attachImg($product);
        $category = Category::create(['title' => 'Fill Cat ' . uniqid()]);
        $product->categories()->attach($category->id, ['position' => 0]);
        $product->prices()->create([
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 100,
            'step' => 1,
        ]);

        $this->assertSame(100, app(ProductFillPercentService::class)->calculate($product->fresh()));
    }

    /** @test */
    public function zero_price_is_not_counted(): void
    {
        $product = $this->createProduct();
        $product->prices()->create([
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 0,
            'step' => 1,
        ]);

        // цена с value=0 не даёт свои 5 пунктов → как у пустого товара
        $this->assertSame(3, app(ProductFillPercentService::class)->calculate($product->fresh()));
    }

    /** @test */
    public function meta_requires_all_three_fields(): void
    {
        $product = $this->createProduct(['meta_title' => 't', 'meta_description' => 'd']);

        $this->assertSame(3, app(ProductFillPercentService::class)->calculate($product));
    }

    /** @test */
    public function weighted_string_attribute_counts_when_filled(): void
    {
        $attribute = Attribute::create([
            'title' => 'Тест-атрибут ' . uniqid(),
            'code' => 'fill_test_' . uniqid(),
            'type_id' => Type::ATTRIBUTE_STRING,
            'category_type_id' => Type::ATTRIBUTE_DEFAULT,
            'position' => 1,
        ]);
        DefaultAttribute::create(['attribute_id' => $attribute->id, 'fill_percent' => 10]);

        $product = $this->createProduct();
        ProductAttribute::create([
            'product_id' => $product->id,
            'attribute_id' => $attribute->id,
            'string_value' => 'значение',
        ]);

        // max = 40 + 10 = 50, набрано = 1 + 10 = 11 → round(22) = 22
        $this->assertSame(22, app(ProductFillPercentService::class)->calculate($product->fresh()));
    }

    /** @test */
    public function select_one_attribute_counts_via_attribute_value_id(): void
    {
        $attribute = Attribute::create([
            'title' => 'Селект ' . uniqid(),
            'code' => 'fill_select_' . uniqid(),
            'type_id' => Type::ATTRIBUTE_SELECT_ONE,
            'category_type_id' => Type::ATTRIBUTE_DEFAULT,
            'position' => 1,
        ]);
        DefaultAttribute::create(['attribute_id' => $attribute->id, 'fill_percent' => 10]);
        $value = AttributeValue::create(['title' => 'v1', 'code' => 'v1', 'attribute_id' => $attribute->id]);

        $product = $this->createProduct();
        ProductAttribute::create([
            'product_id' => $product->id,
            'attribute_id' => $attribute->id,
            'attribute_value_id' => $value->id,
        ]);

        $this->assertSame(22, app(ProductFillPercentService::class)->calculate($product->fresh()));
    }

    /** @test */
    public function attribute_without_weight_does_not_affect_percent(): void
    {
        $attribute = Attribute::create([
            'title' => 'Безвесовой ' . uniqid(),
            'code' => 'fill_nw_' . uniqid(),
            'type_id' => Type::ATTRIBUTE_STRING,
            'category_type_id' => Type::ATTRIBUTE_DEFAULT,
            'position' => 1,
        ]);
        // записи в default_attributes нет

        $product = $this->createProduct();
        ProductAttribute::create([
            'product_id' => $product->id,
            'attribute_id' => $attribute->id,
            'string_value' => 'x',
        ]);

        $this->assertSame(3, app(ProductFillPercentService::class)->calculate($product->fresh()));
    }

    /** @test */
    public function recalculate_persists_without_touching_updated_at(): void
    {
        $product = $this->createProduct(['description' => 'Описание']);
        $frozen = now()->subDay()->startOfSecond();
        DB::table('products')->where('id', $product->id)->update(['updated_at' => $frozen]);

        app(ProductFillPercentService::class)->recalculate($product->fresh());

        $row = DB::table('products')->where('id', $product->id)->first();
        // max=40, набрано = 1 + 1 (description) → round(5) = 5
        $this->assertSame(5, (int) $row->fill_percent);
        $this->assertSame($frozen->toDateTimeString(), (string) $row->updated_at);
    }
}
