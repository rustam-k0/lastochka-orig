<?php

namespace Tests\Feature\Transformers;

use App\Models\AdminSetting;
use App\Models\AssemblyZone;
use App\Models\Category;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Status;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Platrum MEN-IN-DEV-11270: событие "создание заказа" фиксируется в истории
 * для родительского заказа (afterCreate) и для каждого дочернего заказа,
 * порождённого разбивкой по зонам сборки (splitOrderByZones).
 */
class OrderHistoryOrderCreatedTest extends TestCase
{
    use DatabaseTransactions;

    private function makeClient(): Client
    {
        return Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
            'name' => 'Пётр',
            'surname' => 'Заказчиков',
            'is_authorized' => true,
        ]);
    }

    public function test_after_create_logs_created_action_for_parent_order(): void
    {
        $client = $this->makeClient();
        $order = Order::create([
            'uuid' => 'ohc-' . uniqid(),
            'client_id' => $client->id,
            'status_id' => Status::ORDER_CREATED,
            'type_id' => 2,
            'payment_type_id' => 1,
        ]);

        (new OrderEventTransformer())->afterCreate($order, ['event_id' => 'test-event-1']);

        $row = OrderHistory::where('order_id', $order->id)
            ->where('action', OrderHistory::ACTION_CREATED)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(OrderHistory::ACTOR_CLIENT, $row->actor_type);
        $this->assertSame($client->id, $row->actor_id);
        $this->assertStringContainsString('Заказчиков', $row->actor_name);
    }

    public function test_split_order_by_zones_logs_created_action_for_each_child(): void
    {
        AdminSetting::setValue(
            'project_modules',
            json_encode(['assembly_zones' => ['enabled' => true]])
        );

        $client = $this->makeClient();
        $zoneA = AssemblyZone::create(['title' => 'Зона A', 'color' => '#aaaaaa', 'is_active' => true, 'position' => 1]);
        $zoneB = AssemblyZone::create(['title' => 'Зона B', 'color' => '#bbbbbb', 'is_active' => true, 'position' => 2]);
        $catA = Category::create(['title' => 'Cat-A-' . uniqid(), 'assembly_zone_id' => $zoneA->id, 'is_active' => true]);
        $catB = Category::create(['title' => 'Cat-B-' . uniqid(), 'assembly_zone_id' => $zoneB->id, 'is_active' => true]);

        $prodA = Product::withoutEvents(fn () => Product::create([
            'title' => 'Product-A-' . uniqid(),
            'articul' => 'art-a-' . uniqid(),
            'status_id' => Status::CODE_ACTIVE,
            'unit_type_id' => Type::UNIT_PIECE,
        ]));
        $prodA->categories()->attach($catA->id);

        $prodB = Product::withoutEvents(fn () => Product::create([
            'title' => 'Product-B-' . uniqid(),
            'articul' => 'art-b-' . uniqid(),
            'status_id' => Status::CODE_ACTIVE,
            'unit_type_id' => Type::UNIT_PIECE,
        ]));
        $prodB->categories()->attach($catB->id);

        $order = Order::withoutEvents(fn () => Order::create([
            'uuid' => 'ohc-split-' . uniqid(),
            'client_id' => $client->id,
            'status_id' => Status::ORDER_CREATED,
            'type_id' => 2,
            'payment_type_id' => 1,
            'paid' => false,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
        ]));
        OrderItem::create(['order_id' => $order->id, 'product_id' => $prodA->id, 'quantity' => 1, 'price' => 100]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $prodB->id, 'quantity' => 1, 'price' => 100]);

        $order->load('orderItems.product.categories', 'orderItems.supplements.values');

        $method = new \ReflectionMethod(OrderEventTransformer::class, 'splitOrderByZones');
        $method->setAccessible(true);
        $method->invoke(new OrderEventTransformer(), $order);

        $children = Order::where('parent_order_id', $order->id)->get();
        $this->assertCount(2, $children);

        foreach ($children as $child) {
            $row = OrderHistory::where('order_id', $child->id)
                ->where('action', OrderHistory::ACTION_CREATED)
                ->first();
            $this->assertNotNull($row, "Дочерний заказ {$child->id} должен иметь запись 'created' в истории");
            $this->assertSame(OrderHistory::ACTOR_CLIENT, $row->actor_type);
        }
    }
}
