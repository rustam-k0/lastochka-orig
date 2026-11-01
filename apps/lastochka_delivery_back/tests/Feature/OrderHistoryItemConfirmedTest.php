<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\GraphQL\Mutations\Assembly\ProductConfirm;
use App\Models\Assembly;
use App\Models\OrderHistory;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderHistoryItemConfirmedTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;
    private int $collectorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientId = \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Confirm history shop',
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0,
            'is_bonus_enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->collectorId = \DB::table('users')->insertGetId([
            'name' => 'Сборщиков',
            'surname' => 'Тестов',
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertProduct(string $title): int
    {
        $productId = \DB::table('products')->insertGetId([
            'title' => $title,
            'articul' => 'OHIC-' . uniqid(),
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('prices')->insert([
            'product_id' => $productId,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 100,
            'step' => 1,
            'blocked' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    public function test_assembly_finish_logs_item_confirmed_without_replacement(): void
    {
        $productId = $this->insertProduct('Молоко 1л');

        $orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'ohic-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_PREPARING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $productId,
            'quantity' => 2,
            'price' => 200,
            'price_during_order' => 100,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'is_supplemented' => 0,
            'is_eligible' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assemblyId = \DB::table('assemblies')->insertGetId([
            'order_id' => $orderId,
            'user_id' => $this->collectorId,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assemblyItemId = \DB::table('assembly_items')->insertGetId([
            'assembly_id' => $assemblyId,
            'order_item_id' => $orderItemId,
            'status_id' => Status::PRODUCT_WAIT,
            'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new ProductConfirm())->__invoke(null, [
            'assembly_item_id' => $assemblyItemId,
            'quantity' => 2,
        ]);

        AssemblyFinish::assemblyFinish(Assembly::findOrFail($assemblyId));

        $row = OrderHistory::where('order_id', $orderId)->where('action', OrderHistory::ACTION_ITEM_CONFIRMED)->first();

        $this->assertNotNull($row);
        $this->assertSame(OrderHistory::ACTOR_STAFF, $row->actor_type);
        $this->assertSame($this->collectorId, $row->actor_id);
        $this->assertNull($row->old_value);
        $this->assertStringContainsString('Молоко 1л', $row->new_value);
        $this->assertStringContainsString('×2', $row->new_value);
    }

    public function test_assembly_finish_does_not_log_item_confirmed_when_item_is_replaced(): void
    {
        $originProductId = $this->insertProduct('Молоко 1л');
        $replacementProductId = $this->insertProduct('Молоко 1,5л');

        $orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'ohic-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_PREPARING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $originProductId,
            'quantity' => 2,
            'price' => 200,
            'price_during_order' => 100,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'is_supplemented' => 0,
            'is_eligible' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assemblyId = \DB::table('assemblies')->insertGetId([
            'order_id' => $orderId,
            'user_id' => $this->collectorId,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assemblyItemId = \DB::table('assembly_items')->insertGetId([
            'assembly_id' => $assemblyId,
            'order_item_id' => $orderItemId,
            'status_id' => Status::PRODUCT_WAIT,
            'type_id' => Type::PRODUCT_NOT_EXISTS,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('replacement_products')->insert([
            'product_id' => $replacementProductId,
            'assembly_item_id' => $assemblyItemId,
            'selected' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new ProductConfirm())->__invoke(null, [
            'assembly_item_id' => $assemblyItemId,
            'replacement_product_id' => $replacementProductId,
            'quantity' => 2,
        ]);

        AssemblyFinish::assemblyFinish(Assembly::findOrFail($assemblyId));

        $this->assertSame(
            0,
            OrderHistory::where('order_id', $orderId)->where('action', OrderHistory::ACTION_ITEM_CONFIRMED)->count()
        );
    }
}
