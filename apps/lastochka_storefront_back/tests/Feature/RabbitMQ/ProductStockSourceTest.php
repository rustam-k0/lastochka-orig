<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Остаток (quantity) принимаем только из 1С. RetailCRM-бридж (source=retailbridge)
 * по части товаров шлёт «безлимит» 100000, перетирая реальный нулевой остаток из 1С
 * и оставляя товар в поисковой выдаче. fromEvent не должен брать quantity из RetailCRM.
 */
final class ProductStockSourceTest extends TestCase
{
    use RefreshDatabase;

    private ProductEventTransformer $transformer;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();
        Product::unsetEventDispatcher();
        $this->transformer = new ProductEventTransformer();
        $this->consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
    }

    public function test_fromEvent_omits_quantity_for_retailbridge_source(): void
    {
        Store::factory()->create(['code' => 'SHOP_RB']);

        $attributes = $this->transformer->fromEvent($this->event([
            'source' => 'retailbridge',
            'shop_code' => 'SHOP_RB',
            'quantity' => 100000,
        ]));

        $this->assertArrayNotHasKey('quantity', $attributes, 'Остаток из RetailCRM не должен попадать в атрибуты');
    }

    public function test_fromEvent_keeps_quantity_for_non_retailbridge_source(): void
    {
        Store::factory()->create(['code' => 'SHOP_1C']);

        $attributes = $this->transformer->fromEvent($this->event([
            'source' => '1c',
            'shop_code' => 'SHOP_1C',
            'quantity' => 42,
        ]));

        $this->assertArrayHasKey('quantity', $attributes);
        $this->assertSame(42, $attributes['quantity']);
    }

    public function test_retailbridge_event_does_not_overwrite_quantity_on_update(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_RB']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'RB-100',
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $this->consumer->processEvent($this->event([
            'event_type' => 'updated',
            'source' => 'retailbridge',
            'shop_code' => 'SHOP_RB',
            'sku' => 'RB-100',
            'quantity' => 100000,
        ]));

        $this->assertSame(0, (int) $product->fresh()->quantity, 'RetailCRM не должен перетирать остаток');
    }

    public function test_retailbridge_event_defaults_quantity_to_zero_on_create(): void
    {
        Store::factory()->create(['code' => 'SHOP_RB2']);

        $this->consumer->processEvent($this->event([
            'event_type' => 'created',
            'source' => 'retailbridge',
            'shop_code' => 'SHOP_RB2',
            'sku' => 'RB-200',
            'quantity' => 100000,
        ]));

        $product = Product::where('sku', 'RB-200')->first();
        $this->assertNotNull($product);
        $this->assertSame(0, (int) $product->quantity, 'Новый RetailCRM-товар не берёт остаток из события');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_'.Str::random(5),
            'source' => 'bagisto',
            'shop_code' => 'SHOP',
            'sku' => 'SKU-'.Str::random(5),
            'title' => 'Тестовый товар',
            'price' => 100.00,
            'active' => true,
        ], $overrides);
    }
}
