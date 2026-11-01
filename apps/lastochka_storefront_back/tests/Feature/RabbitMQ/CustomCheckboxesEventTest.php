<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Transformers\BannerEventTransformer;
use App\RabbitMQ\Transformers\BrandEventTransformer;
use App\RabbitMQ\Transformers\CategoryEventTransformer;
use App\RabbitMQ\Transformers\ProductGroupEventTransformer;
use App\RabbitMQ\Transformers\PromotionEventTransformer;
use App\RabbitMQ\Transformers\StoreEventTransformer;
use App\RabbitMQ\Transformers\StoryEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CustomCheckboxesEventTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
    }

    private function buildProductEvent(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_' . Str::random(5),
            'source' => 'bagisto',
            'shop_code' => 'CC_' . Str::random(3),
            'sku' => 'SKU-' . Str::random(5),
            'title' => 'Тестовый товар',
            'price' => 100.00,
            'active' => true,
        ], $overrides);
    }

    /** Product: created с ключом — map сохраняется */
    public function test_product_created_event_stores_custom_checkboxes(): void
    {
        Store::factory()->create(['code' => 'CC_001']);

        $this->consumer->processEvent($this->buildProductEvent([
            'id' => 'cc_1',
            'shop_code' => 'CC_001',
            'custom_checkboxes' => ['is_hit' => true, 'is_eco' => false],
        ]));

        $product = Product::where('sku', '!=', '')->latest('id')->first();
        $this->assertSame(['is_hit' => true, 'is_eco' => false], $product->custom_checkboxes);
    }

    /** Product: partial update БЕЗ ключа — значение не затирается */
    public function test_product_partial_update_keeps_custom_checkboxes(): void
    {
        $store = Store::factory()->create(['code' => 'CC_002']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'CC-KEEP',
            'custom_checkboxes' => ['is_hit' => true],
        ]);
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'cc_keep',
            'source' => 'bagisto',
            'shop_code' => 'CC_002',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $this->consumer->processEvent($this->buildProductEvent([
            'event_type' => 'updated',
            'id' => 'cc_keep',
            'shop_code' => 'CC_002',
            'sku' => 'CC-KEEP',
            'title' => 'Обновлённое имя',
            'price' => 150.00,
        ]));

        $this->assertSame(['is_hit' => true], $product->fresh()->custom_checkboxes);
    }

    /** Product: явная пустая map — значения очищаются */
    public function test_product_update_with_empty_map_clears_values(): void
    {
        $store = Store::factory()->create(['code' => 'CC_003']);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'sku' => 'CC-CLEAR',
            'custom_checkboxes' => ['is_hit' => true],
        ]);
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => 'cc_clear',
            'source' => 'bagisto',
            'shop_code' => 'CC_003',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $this->consumer->processEvent($this->buildProductEvent([
            'event_type' => 'updated',
            'id' => 'cc_clear',
            'shop_code' => 'CC_003',
            'sku' => 'CC-CLEAR',
            'title' => 'Очистка',
            'price' => 150.00,
            'custom_checkboxes' => [],
        ]));

        $this->assertSame([], $product->fresh()->custom_checkboxes);
    }

    /** Остальные 7 трансформеров: fromEvent юнит-проверки conditional set */
    public function test_other_transformers_apply_conditional_set(): void
    {
        $store = Store::factory()->create();

        $cases = [
            [app(CategoryEventTransformer::class), ['event_id' => 'e', 'id' => '1', 'shop_code' => $store->code, 'source' => 'bagisto', 'title' => 'Кат']],
            [app(StoryEventTransformer::class), ['event_id' => 'e', 'id' => '1', 'source' => 'bagisto', 'title' => 'Стори']],
            [app(BrandEventTransformer::class), ['event_id' => 'e', 'id' => '1', 'source' => 'bagisto', 'title' => 'Бренд']],
            [app(BannerEventTransformer::class), ['event_id' => 'e', 'id' => '1', 'source' => 'bagisto', 'title' => 'Баннер', 'shop_id' => (string) $store->id, 'shop_source' => 'bagisto']],
            [app(PromotionEventTransformer::class), ['event_id' => 'e', 'id' => '1', 'source' => 'bagisto', 'title' => 'Акция', 'shop_id' => (string) $store->id, 'shop_source' => 'bagisto']],
            [app(ProductGroupEventTransformer::class), ['event_id' => 'e', 'id' => '1', 'source' => 'bagisto', 'title' => 'Группа', 'shop_id' => (string) $store->id, 'shop_source' => 'bagisto']],
            [app(StoreEventTransformer::class), ['event_id' => 'e', 'id' => '1', 'source' => 'bagisto', 'shop_code' => 'ST_' . Str::random(4), 'title' => 'Магазин']],
        ];

        foreach ($cases as [$transformer, $base]) {
            $name = get_class($transformer);

            $without = $transformer->fromEvent($base);
            $this->assertArrayNotHasKey('custom_checkboxes', $without, "{$name}: без ключа атрибут не должен появляться");

            $with = $transformer->fromEvent($base + ['custom_checkboxes' => ['is_x' => 1]]);
            $this->assertSame(['is_x' => true], $with['custom_checkboxes'], "{$name}: map должна нормализоваться к bool");
        }
    }
}
