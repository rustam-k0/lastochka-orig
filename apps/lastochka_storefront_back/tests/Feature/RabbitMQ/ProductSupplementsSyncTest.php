<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplement;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductSupplementsSyncTest extends TestCase
{
    use RefreshDatabase;

    private function createProductViaTransformer(array $eventData): Product
    {
        $transformer = new ProductEventTransformer;

        $transformedData = $transformer->fromEvent($eventData);
        $product = Product::create($transformedData);

        $transformer->afterCreate($product, $eventData);

        return $product->fresh();
    }

    public function test_sync_supplements_via_rabbitmq(): void
    {
        $store = Store::factory()->create(['code' => 'shop1']);
        Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->create([
            'sku' => 'BORSH-001',
            'quantity' => 10,
        ]);

        $transformer = new ProductEventTransformer;

        $eventData = [
            'event_id' => 'evt-1',
            'id' => '123',
            'shop_code' => $store->code,
            'source' => 'cron_market',
            'sku' => 'BORSH-001',
            'title' => 'Борщ, 300 г',
            'price' => 170,
            'supplements' => [
                [
                    'id' => 1,
                    'title' => 'Соус на выбор',
                    'type' => 'one',
                    'is_required' => true,
                    'values' => [
                        ['id' => 10, 'title' => 'Сырный', 'price' => 0],
                        ['id' => 11, 'title' => 'Кетчуп', 'price' => 0],
                    ],
                ],
                [
                    'id' => 2,
                    'title' => 'Допы',
                    'type' => 'many',
                    'is_required' => false,
                    'values' => [
                        ['id' => 20, 'title' => 'Аджика', 'price' => 50],
                        ['id' => 21, 'title' => 'Сметана с чесноком', 'price' => 40],
                    ],
                ],
            ],
        ];

        $transformer->afterUpdate($product, $eventData);

        $product->load('supplements.values');

        $this->assertCount(2, $product->supplements);
        $this->assertEquals('Соус на выбор', $product->supplements[0]->title);
        $this->assertEquals('one', $product->supplements[0]->type);
        $this->assertTrue($product->supplements[0]->is_required);
        $this->assertCount(2, $product->supplements[0]->values);

        $this->assertEquals('Допы', $product->supplements[1]->title);
        $this->assertEquals('many', $product->supplements[1]->type);
        $this->assertFalse($product->supplements[1]->is_required);
        $this->assertEquals(50, $product->supplements[1]->values[0]->price);
    }

    public function test_update_supplements_replaces_existing(): void
    {
        $store = Store::factory()->create(['code' => 'shop1']);
        $product = Product::factory()->for($store)->create(['sku' => 'BORSH-001', 'quantity' => 10]);

        // Создаём начальные добавки
        $oldSupplement = Supplement::create([
            'title' => 'Старая добавка',
            'product_id' => $product->id,
            'type' => 'one',
            'is_required' => false,
        ]);

        $transformer = new ProductEventTransformer;

        $eventData = [
            'event_id' => 'evt-2',
            'id' => '123',
            'shop_code' => $store->code,
            'source' => 'cron_market',
            'supplements' => [
                [
                    'id' => 1,
                    'title' => 'Новая добавка',
                    'type' => 'many',
                    'is_required' => true,
                    'values' => [
                        ['id' => 10, 'title' => 'Вариант 1', 'price' => 30],
                    ],
                ],
            ],
        ];

        $transformer->afterUpdate($product, $eventData);

        $product->load('supplements.values');

        // Старая добавка должна быть удалена, новая создана
        $this->assertCount(1, $product->supplements);
        $this->assertEquals('Новая добавка', $product->supplements[0]->title);
    }

    public function test_empty_supplements_array_clears_existing(): void
    {
        $store = Store::factory()->create(['code' => 'shop1']);
        $product = Product::factory()->for($store)->create(['sku' => 'BORSH-001', 'quantity' => 10]);

        Supplement::create([
            'title' => 'Старая добавка',
            'product_id' => $product->id,
            'type' => 'one',
            'is_required' => false,
        ]);

        $transformer = new ProductEventTransformer;

        // Событие с пустым массивом supplements — означает «все supplements удалены в источнике»
        $eventData = [
            'event_id' => 'evt-empty',
            'id' => '123',
            'shop_code' => $store->code,
            'source' => 'cron_market',
            'supplements' => [],
        ];

        $transformer->afterUpdate($product, $eventData);

        $product->load('supplements');

        // Старая добавка должна быть удалена
        $this->assertCount(0, $product->supplements);
    }

    public function test_event_without_supplements_does_not_touch_existing(): void
    {
        $store = Store::factory()->create(['code' => 'shop1']);
        $product = Product::factory()->for($store)->create(['sku' => 'BORSH-001', 'quantity' => 10]);

        Supplement::create([
            'title' => 'Существующая добавка',
            'product_id' => $product->id,
            'type' => 'one',
            'is_required' => false,
        ]);

        $transformer = new ProductEventTransformer;

        // Событие без поля supplements
        $eventData = [
            'event_id' => 'evt-3',
            'id' => '123',
            'shop_code' => $store->code,
            'source' => 'cron_market',
            'title' => 'Борщ обновлённый',
            'price' => 200,
        ];

        $transformer->afterUpdate($product, $eventData);

        $product->load('supplements');

        // Существующая добавка должна остаться
        $this->assertCount(1, $product->supplements);
        $this->assertEquals('Существующая добавка', $product->supplements[0]->title);
    }
}
