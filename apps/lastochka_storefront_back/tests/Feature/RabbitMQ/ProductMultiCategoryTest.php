<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Category;
use App\Models\Product;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductMultiCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_categories_attaches_in_position_order(): void
    {
        $product = Product::factory()->create();
        [$cA, $cB] = [Category::factory()->create(), Category::factory()->create()];

        $this->createMetadata('categories', $cA->id, 'extA', 'admin_back');
        $this->createMetadata('categories', $cB->id, 'extB', 'admin_back');

        $transformer = app(ProductEventTransformer::class);
        $this->callLinkCategories($transformer, $product, [
            'category_ids' => ['extA', 'extB'],
            'category_source' => 'admin_back',
        ]);

        $linked = $product->fresh('categories')->categories;
        $this->assertSame([$cA->id, $cB->id], $linked->pluck('id')->all());
        $this->assertSame([0, 1], $linked->map(fn ($c) => (int) $c->pivot->position)->all());
    }

    public function test_legacy_shim_single_category_id(): void
    {
        $product = Product::factory()->create();
        $c = Category::factory()->create();
        $this->createMetadata('categories', $c->id, 'ext1', 'admin_back');

        $transformer = app(ProductEventTransformer::class);
        $this->callLinkCategories($transformer, $product, [
            'category_id' => 'ext1',
            'category_source' => 'admin_back',
        ]);

        $this->assertSame([$c->id], $product->fresh('categories')->categories->pluck('id')->all());
    }

    public function test_partial_update_keeps_existing_links(): void
    {
        $product = Product::factory()->create();
        $c = Category::factory()->create();
        $this->createMetadata('categories', $c->id, 'ext1', 'admin_back');
        $product->categories()->attach($c->id, ['position' => 0]);

        $transformer = app(ProductEventTransformer::class);
        $this->callLinkCategories($transformer, $product, [
            'title' => 'обновили только название',
        ]);

        $this->assertSame([$c->id], $product->fresh('categories')->categories->pluck('id')->all());
    }

    public function test_empty_array_detaches_all(): void
    {
        $product = Product::factory()->create();
        $c = Category::factory()->create();
        $product->categories()->attach($c->id, ['position' => 0]);

        $transformer = app(ProductEventTransformer::class);
        $this->callLinkCategories($transformer, $product, [
            'category_ids' => [],
            'category_source' => 'admin_back',
        ]);

        $this->assertCount(0, $product->fresh('categories')->categories);
    }

    private function callLinkCategories(ProductEventTransformer $transformer, Product $product, array $eventData): void
    {
        $reflection = new \ReflectionClass($transformer);
        $method = $reflection->getMethod('linkCategories');
        $method->setAccessible(true);
        $method->invoke($transformer, $product, $eventData);
    }

    private function createMetadata(string $entityType, int $entityId, string $externalId, string $source): void
    {
        EventSyncMetadata::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'external_id' => $externalId,
            'source' => $source,
            'shop_code' => null,
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subMinute(),
        ]);
    }
}
