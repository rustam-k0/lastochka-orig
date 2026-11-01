<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\Services\Stock\StockOverrideService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StockOverrideTest extends TestCase
{
    use DatabaseTransactions;

    private function shop(bool $ignore = false): Shop
    {
        return Shop::firstOrCreate(
            ['code' => 'stocktest'],
            ['title' => 'StockTest', 'ignore_stock' => $ignore]
        );
    }

    private function product(array $attrs = []): Product
    {
        return Product::forceCreate(array_merge([
            'title' => 'P', 'shop_id' => $this->shop()->id,
            'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
        ], $attrs));
    }

    public function test_product_ignore_overrides_category(): void
    {
        $cat = Category::forceCreate(['title' => 'C', 'link' => 'c', 'stock_mode' => 'fixed', 'fixed_stock_quantity' => 5, 'fixed_stock_unit_type_id' => Type::INFO_WEIGHT_PIECE]);
        $product = $this->product(['stock_mode' => 'ignore']);
        $product->categories()->attach($cat->id);

        $result = app(StockOverrideService::class)->resolve($product->fresh());

        $this->assertSame('ignore', $result['mode']);
        $this->assertNull($result['fixed']);
    }

    public function test_category_fixed_applies_when_product_empty(): void
    {
        $cat = Category::forceCreate(['title' => 'C', 'link' => 'c', 'stock_mode' => 'fixed', 'fixed_stock_quantity' => 3, 'fixed_stock_unit_type_id' => Type::INFO_WEIGHT_PIECE]);
        $product = $this->product(); // stock_mode = null
        $product->categories()->attach($cat->id);

        $result = app(StockOverrideService::class)->resolve($product->fresh());
        $this->assertSame('fixed', $result['mode']);
        $this->assertSame(3, $result['fixed']);
    }

    public function test_category_fixed_inherited_from_parent(): void
    {
        $parent = Category::forceCreate(['title' => 'P', 'link' => 'p', 'stock_mode' => 'ignore']);
        $child = Category::forceCreate(['title' => 'Ch', 'link' => 'ch', 'parent_id' => $parent->id, 'stock_mode' => 'normal']);
        $product = $this->product();
        $product->categories()->attach($child->id);

        $result = app(StockOverrideService::class)->resolve($product->fresh());
        $this->assertSame('ignore', $result['mode']);
    }

    public function test_min_fixed_among_categories(): void
    {
        $c1 = Category::forceCreate(['title' => 'A', 'link' => 'a', 'stock_mode' => 'fixed', 'fixed_stock_quantity' => 10, 'fixed_stock_unit_type_id' => Type::INFO_WEIGHT_PIECE]);
        $c2 = Category::forceCreate(['title' => 'B', 'link' => 'b', 'stock_mode' => 'fixed', 'fixed_stock_quantity' => 4, 'fixed_stock_unit_type_id' => Type::INFO_WEIGHT_PIECE]);
        $product = $this->product();
        $product->categories()->attach([$c1->id, $c2->id]);

        $result = app(StockOverrideService::class)->resolve($product->fresh());
        $this->assertSame(4, $result['fixed']);
    }

    public function test_kg_fixed_converted_to_grams(): void
    {
        $product = $this->product(['base_unit_type_id' => Type::INFO_WEIGHT_GRAM, 'stock_mode' => 'fixed', 'fixed_stock_quantity' => 100, 'fixed_stock_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM]);
        $result = app(StockOverrideService::class)->resolve($product->fresh());
        $this->assertSame(100000, $result['fixed']); // 100 кг = 100000 г
    }

    public function test_shop_ignore_fallback(): void
    {
        $product = Product::forceCreate(['title' => 'P', 'shop_id' => $this->shop(true)->id, 'base_unit_type_id' => Type::INFO_WEIGHT_PIECE]);
        $result = app(StockOverrideService::class)->resolve($product->fresh());
        $this->assertSame('ignore', $result['mode']);
    }

    public function test_default_normal(): void
    {
        $product = $this->product();
        $result = app(StockOverrideService::class)->resolve($product->fresh());
        $this->assertSame('normal', $result['mode']);
        $this->assertNull($result['fixed']);
    }

    public function test_apply_materializes_effective_and_dispatches(): void
    {
        \Event::fake([\App\Events\ProductUpdated::class]);
        $cat = Category::forceCreate(['title' => 'C', 'link' => 'c-mat', 'stock_mode' => 'ignore']);
        $product = $this->product();
        $product->categories()->attach($cat->id);

        $changed = app(StockOverrideService::class)->applyToProduct($product->fresh());

        $this->assertTrue($changed);
        $this->assertSame('ignore', $product->fresh()->effective_stock_mode);
        \Event::assertDispatched(\App\Events\ProductUpdated::class);
    }

    public function test_apply_idempotent_no_event_when_unchanged(): void
    {
        \Event::fake([\App\Events\ProductUpdated::class]);
        $product = $this->product(); // normal по умолчанию, effective уже normal
        $changed = app(StockOverrideService::class)->applyToProduct($product->fresh());
        $this->assertFalse($changed);
        \Event::assertNotDispatched(\App\Events\ProductUpdated::class);
    }

    public function test_to_event_includes_stock_mode(): void
    {
        $product = $this->product();
        $product->forceFill(['effective_stock_mode' => 'fixed', 'effective_fixed_stock' => 100])->save();

        $transformer = app(\App\Transformers\ProductEventTransformer::class);
        $payload = $transformer->toEvent($product->fresh());

        $this->assertSame('fixed', $payload['stock_mode']);
        $this->assertSame(100, $payload['fixed_stock']);
    }

    public function test_recalc_category_covers_subcategories(): void
    {
        $parent = Category::forceCreate(['title' => 'P', 'link' => 'rc-parent', 'stock_mode' => 'ignore']);
        $child = Category::forceCreate(['title' => 'Ch', 'link' => 'rc-child', 'parent_id' => $parent->id, 'stock_mode' => 'normal']);
        $p1 = $this->product(); $p1->categories()->attach($parent->id);
        $p2 = $this->product(); $p2->categories()->attach($child->id);

        $count = app(StockOverrideService::class)->recalcCategory($parent->fresh());

        $this->assertSame('ignore', $p1->fresh()->effective_stock_mode);
        $this->assertSame('ignore', $p2->fresh()->effective_stock_mode); // унаследовано от parent
        $this->assertSame(2, $count);
    }

    public function test_preview_returns_fixed_mode_from_category(): void
    {
        $cat = Category::forceCreate([
            'title' => 'C', 'link' => 'preview-fixed',
            'stock_mode' => 'fixed', 'fixed_stock_quantity' => 7,
            'fixed_stock_unit_type_id' => Type::INFO_WEIGHT_PIECE,
        ]);

        $result = app(StockOverrideService::class)->resolveForPreview(
            [$cat->id], null, Type::INFO_WEIGHT_PIECE
        );

        $this->assertSame('fixed', $result['mode']);
        $this->assertSame(7, $result['fixed']);
        $this->assertSame(Type::INFO_WEIGHT_PIECE, $result['fixed_unit_type_id']);
    }

    public function test_preview_returns_ignore_from_category(): void
    {
        $cat = Category::forceCreate(['title' => 'C', 'link' => 'preview-ignore', 'stock_mode' => 'ignore']);

        $result = app(StockOverrideService::class)->resolveForPreview([$cat->id], null, null);

        $this->assertSame('ignore', $result['mode']);
        $this->assertNull($result['fixed']);
        $this->assertNull($result['fixed_unit_type_id']);
    }

    public function test_preview_inherits_from_parent_category(): void
    {
        $parent = Category::forceCreate(['title' => 'P', 'link' => 'preview-parent', 'stock_mode' => 'ignore']);
        $child = Category::forceCreate(['title' => 'Ch', 'link' => 'preview-child', 'parent_id' => $parent->id, 'stock_mode' => 'normal']);

        $result = app(StockOverrideService::class)->resolveForPreview([$child->id], null, null);

        $this->assertSame('ignore', $result['mode']);
    }

    public function test_preview_skips_fixed_on_unit_family_mismatch(): void
    {
        $cat = Category::forceCreate([
            'title' => 'C', 'link' => 'preview-mismatch',
            'stock_mode' => 'fixed', 'fixed_stock_quantity' => 3,
            'fixed_stock_unit_type_id' => Type::INFO_WEIGHT_PIECE,
        ]);

        // unit_type_id = грамм (масса) не совпадает с fixed-категорией (шт)
        $result = app(StockOverrideService::class)->resolveForPreview(
            [$cat->id], null, Type::INFO_WEIGHT_GRAM
        );

        $this->assertSame('normal', $result['mode']);
    }

    public function test_preview_falls_back_to_shop_ignore_stock(): void
    {
        $shop = $this->shop(true);

        $result = app(StockOverrideService::class)->resolveForPreview([], $shop->id, Type::INFO_WEIGHT_PIECE);

        $this->assertSame('ignore', $result['mode']);
    }

    public function test_preview_defaults_to_normal_with_no_categories_or_shop(): void
    {
        $result = app(StockOverrideService::class)->resolveForPreview([], null, null);

        $this->assertSame('normal', $result['mode']);
        $this->assertNull($result['fixed']);
    }
}
