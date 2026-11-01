<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Comparison;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Comparison\AttributeUnionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AttributeUnionBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_union_of_unique_attributes_ordered_by_position(): void
    {
        $category = Category::factory()->create();

        $first = Product::factory()
            ->inCategory($category)
            ->create(['created_at' => now()->subMinutes(10)]);
        $second = Product::factory()
            ->inCategory($category)
            ->create(['created_at' => now()->subMinutes(5)]);

        ProductAttribute::create([
            'product_id' => $first->id,
            'code' => 'color',
            'name' => 'Цвет',
            'value' => 'белый',
            'position' => 2,
        ]);
        ProductAttribute::create([
            'product_id' => $first->id,
            'code' => 'brand',
            'name' => 'Бренд',
            'value' => 'Schneider',
            'position' => 1,
        ]);
        ProductAttribute::create([
            'product_id' => $second->id,
            'code' => 'color',
            'name' => 'Цвет (другое)',
            'value' => 'чёрный',
            'position' => 99,
        ]);
        ProductAttribute::create([
            'product_id' => $second->id,
            'code' => 'ip_rating',
            'name' => 'Степень защиты IP',
            'value' => 'IP44',
            'position' => 3,
        ]);

        $products = collect([$first, $second])->each->load('attributes');

        $rows = app(AttributeUnionBuilder::class)->build($products);

        $this->assertSame(
            [
                ['code' => 'brand', 'name' => 'Бренд', 'position' => 1],
                ['code' => 'color', 'name' => 'Цвет', 'position' => 2],
                ['code' => 'ip_rating', 'name' => 'Степень защиты IP', 'position' => 3],
            ],
            $rows,
            'Атрибуты должны быть UNION-нуты по code, приоритет name/position у первого по created_at, отсортированы по position.'
        );
    }

    public function test_skips_empty_codes(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create();

        ProductAttribute::create([
            'product_id' => $product->id,
            'code' => '',
            'name' => 'Пусто',
            'value' => 'x',
            'position' => 1,
        ]);

        $products = collect([$product->load('attributes')]);

        $this->assertSame([], app(AttributeUnionBuilder::class)->build($products));
    }
}
