<?php

namespace Tests\Feature\Transformers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Status;
use App\Transformers\OrderEventTransformer;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class OrderItemStatusMappingTest extends TestCase
{
    private OrderEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderEventTransformer;
    }

    /**
     * Создаёт мок Order с коллекцией OrderItem (без обращения к БД)
     */
    private function makeOrderWithItem(array $itemAttributes): Order
    {
        $product = new Product();
        $product->id = $itemAttributes['product_id'] ?? 1;
        $product->title = $itemAttributes['_product_title'] ?? 'Test Product';
        unset($itemAttributes['_product_title']);

        $originProduct = null;
        if (!empty($itemAttributes['origin_product_id']) && $itemAttributes['origin_product_id'] != $product->id) {
            $originProduct = new Product();
            $originProduct->id = $itemAttributes['origin_product_id'];
            $originProduct->title = $itemAttributes['_origin_product_title'] ?? 'Origin Product';
        }
        unset($itemAttributes['_origin_product_title']);

        $item = new OrderItem();
        $item->forceFill(array_merge([
            'id' => 1,
            'order_id' => 1,
            'quantity' => 1,
            'price' => 100,
            'price_during_order' => 100,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'is_supplemented' => false,
            'origin_product_id' => null,
            'origin_quantity' => null,
            'origin_price' => null,
        ], $itemAttributes));

        $item->setRelation('product', $product);
        $item->setRelation('originProduct', $originProduct);

        $shop = new Shop();
        $shop->id = 1;
        $shop->code = 'test-shop';

        $order = new Order();
        $order->id = 1;
        $order->shop_id = 1;
        $order->setRelation('orderItems', new Collection([$item]));
        $order->setRelation('shop', $shop);

        return $order;
    }

    private function getItemStatus(Order $order): array
    {
        $method = new \ReflectionMethod($this->transformer, 'transformOrderItems');
        return $method->invoke($this->transformer, $order);
    }

    public function test_confirmed_status_when_assembled_without_origin(): void
    {
        $order = $this->makeOrderWithItem([
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'is_supplemented' => false,
        ]);

        $items = $this->getItemStatus($order);

        $this->assertCount(1, $items);
        $this->assertEquals('confirmed', $items[0]['item_status']);
        $this->assertArrayNotHasKey('origin_product_id', $items[0]);
        $this->assertArrayNotHasKey('origin_product_name', $items[0]);
    }

    public function test_cancelled_status_when_item_canceled(): void
    {
        $order = $this->makeOrderWithItem([
            'status_id' => Status::ORDER_ITEM_CANCELED,
            'is_supplemented' => false,
        ]);

        $items = $this->getItemStatus($order);

        $this->assertCount(1, $items);
        $this->assertEquals('cancelled', $items[0]['item_status']);
    }

    public function test_replaced_status_when_origin_differs_from_current(): void
    {
        $order = $this->makeOrderWithItem([
            'product_id' => 2,
            '_product_title' => 'Replacement Product',
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'origin_product_id' => 1,
            'origin_quantity' => 3,
            'origin_price' => 150,
            '_origin_product_title' => 'Original Product',
            'is_supplemented' => false,
        ]);

        $items = $this->getItemStatus($order);

        // Замена уходит двумя позициями: исходный товар (replaced) и замена (added)
        $this->assertCount(2, $items);

        $this->assertEquals('replaced', $items[0]['item_status']);
        $this->assertSame('1', $items[0]['product_id']);
        $this->assertEquals('Original Product', $items[0]['product_name']);
        $this->assertEquals(3, $items[0]['quantity']);
        $this->assertEquals(150.0, $items[0]['unit_price']);

        $this->assertEquals('added', $items[1]['item_status']);
        $this->assertSame('2', $items[1]['product_id']);
        $this->assertEquals('Replacement Product', $items[1]['product_name']);
        $this->assertSame('1', $items[1]['origin_product_id']);
        $this->assertEquals('Original Product', $items[1]['origin_product_name']);
    }

    public function test_added_status_when_is_supplemented(): void
    {
        $order = $this->makeOrderWithItem([
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'is_supplemented' => true,
        ]);

        $items = $this->getItemStatus($order);

        $this->assertCount(1, $items);
        $this->assertEquals('added', $items[0]['item_status']);
    }

    public function test_cancelled_takes_priority_over_replaced(): void
    {
        // Даже если origin_product_id заполнен, cancelled приоритетнее
        $order = $this->makeOrderWithItem([
            'product_id' => 2,
            'status_id' => Status::ORDER_ITEM_CANCELED,
            'origin_product_id' => 1,
            'origin_quantity' => 1,
            'origin_price' => 100,
            'is_supplemented' => false,
        ]);

        $items = $this->getItemStatus($order);

        $this->assertCount(1, $items);
        $this->assertEquals('cancelled', $items[0]['item_status']);
    }

    public function test_same_origin_and_product_id_is_confirmed_not_replaced(): void
    {
        // origin_product_id == product_id — это NOT replaced, просто qty changed
        $order = $this->makeOrderWithItem([
            'product_id' => 1,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'origin_product_id' => 1,
            'origin_quantity' => 5,
            'origin_price' => 100,
            'is_supplemented' => false,
        ]);

        $items = $this->getItemStatus($order);

        $this->assertCount(1, $items);
        $this->assertEquals('confirmed', $items[0]['item_status']);
    }
}
