<?php

namespace Tests\Unit;

use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Transformers\ProductEventTransformer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LASTOCHKI-JAMI-12190: исторические гонки консьюмеров оставили в
 * product_attributes дубли пар (product_id, attribute_id). Payload
 * события не должен нести дубликаты — побеждает самая свежая строка
 * (updated_at, tie-break id), иначе мобилка рисует «Белки ×5».
 */
class ProductEventTransformerAttributesDedupeTest extends TestCase
{
    private function transform(Product $product): array
    {
        $method = new \ReflectionMethod(ProductEventTransformer::class, 'transformAttributes');
        $method->setAccessible(true);

        return $method->invoke(new ProductEventTransformer(), $product);
    }

    private function makeRow(Attribute $attribute, int $id, string $value, string $updatedAt): ProductAttribute
    {
        $row = new ProductAttribute();
        $row->id = $id;
        $row->attribute_id = $attribute->id;
        $row->string_value = $value;
        $row->updated_at = Carbon::parse($updatedAt);
        $row->setRelation('attribute', $attribute);

        return $row;
    }

    private function makeAttribute(int $id, string $code, string $title): Attribute
    {
        $attribute = new Attribute();
        $attribute->id = $id;
        $attribute->code = $code;
        $attribute->title = $title;
        $attribute->type_id = 1;
        $attribute->category_type_id = 100;

        return $attribute;
    }

    public function test_duplicate_rows_collapse_to_freshest_by_updated_at(): void
    {
        $proteins = $this->makeAttribute(1, 'proteins', 'Белки');

        $product = new Product();
        $product->setRelation('attributes', collect([
            $this->makeRow($proteins, 100, '5.0', '2026-01-01 10:00:00'),
            $this->makeRow($proteins, 50, '8.89', '2026-07-01 10:00:00'),
        ]));

        $result = $this->transform($product);

        $this->assertCount(1, $result);
        $this->assertSame('proteins', $result[0]['code']);
        $this->assertSame('8.89', $result[0]['value']);
    }

    public function test_equal_updated_at_higher_id_wins(): void
    {
        $proteins = $this->makeAttribute(1, 'proteins', 'Белки');

        $product = new Product();
        $product->setRelation('attributes', collect([
            $this->makeRow($proteins, 100, 'новое', '2026-07-01 10:00:00'),
            $this->makeRow($proteins, 50, 'старое', '2026-07-01 10:00:00'),
        ]));

        $result = $this->transform($product);

        $this->assertCount(1, $result);
        $this->assertSame('новое', $result[0]['value']);
    }

    public function test_different_attributes_are_not_collapsed(): void
    {
        $proteins = $this->makeAttribute(1, 'proteins', 'Белки');
        $fats = $this->makeAttribute(2, 'fats', 'Жиры');

        $product = new Product();
        $product->setRelation('attributes', collect([
            $this->makeRow($proteins, 1, '8.89', '2026-07-01 10:00:00'),
            $this->makeRow($fats, 2, '2.72', '2026-07-01 10:00:00'),
        ]));

        $result = $this->transform($product);

        $this->assertCount(2, $result);
    }
}
