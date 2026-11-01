<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductEventTransformerCategoriesArrayTest extends TestCase
{
    use DatabaseTransactions;

    public function test_to_event_payload_contains_category_ids_in_position_order(): void
    {
        $shop = Shop::create([
            'title' => 'TS ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);

        [$cA, $cB, $cC] = [
            Category::factory()->create(['parent_id' => null]),
            Category::factory()->create(['parent_id' => null]),
            Category::factory()->create(['parent_id' => null]),
        ];

        $product->categories()->sync([
            $cB->id => ['position' => 0],
            $cC->id => ['position' => 1],
            $cA->id => ['position' => 2],
        ]);

        $payload = app(ProductEventTransformer::class)->toEvent($product->fresh('categories'));

        $this->assertArrayHasKey('category_ids', $payload);
        $this->assertIsArray($payload['category_ids']);
        $this->assertCount(3, $payload['category_ids']);
        // Без metadata fallback — (string) local_id
        $this->assertSame([(string) $cB->id, (string) $cC->id, (string) $cA->id], $payload['category_ids']);

        $this->assertArrayHasKey('category_source', $payload);
        $this->assertSame(config('rabbitmq-events.source'), $payload['category_source']);

        $this->assertArrayNotHasKey('category_id', $payload, 'старое поле должно уйти из payload');
    }

    public function test_to_event_uses_metadata_external_id_when_available(): void
    {
        $shop = Shop::create([
            'title' => 'TS ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
        $cat = Category::factory()->create(['parent_id' => null]);
        $product->categories()->sync([$cat->id => ['position' => 0]]);

        EventSyncMetadata::create([
            'entity_type' => 'categories',
            'entity_id' => $cat->id,
            'external_id' => 'ext-cat-99',
            'source' => '1c',
        ]);

        $payload = app(ProductEventTransformer::class)->toEvent($product->fresh('categories'));

        $this->assertSame(['ext-cat-99'], $payload['category_ids']);
        $this->assertSame('1c', $payload['category_source']);
    }

    public function test_to_event_empty_array_when_no_categories(): void
    {
        $shop = Shop::create([
            'title' => 'TS ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
        $product = Product::create([
            'title' => 'TP ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);

        $payload = app(ProductEventTransformer::class)->toEvent($product->fresh('categories'));

        $this->assertSame([], $payload['category_ids']);
        $this->assertNull($payload['category_source']);
    }
}
