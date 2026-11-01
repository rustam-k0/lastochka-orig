<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use App\Models\ProductReplaceReason;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Причина замены товара (Platrum MEN-IN-DEV-11280): при завершении сборки
 * replace_reason_id должен переехать с assembly_items на order_items
 * (собственный заказ) и на позицию родительского заказа (сплит по зонам),
 * симметрично origin_product_id/origin_title.
 */
class AssemblyFinishReplaceReasonTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;
    private int $collectorId;
    private ProductReplaceReason $reason;

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

        // ignore_stock=1: не тестируем остатки, только propagation reason.
        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'AF replace reason shop',
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 1,
            'is_bonus_enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->collectorId = \DB::table('users')->insertGetId([
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->reason = ProductReplaceReason::create([
            'title' => 'Нет в наличии',
            'is_active' => true,
            'position' => 1,
        ]);
    }

    private function insertProduct(string $title): int
    {
        $productId = \DB::table('products')->insertGetId([
            'title' => $title,
            'articul' => 'AFRR-' . uniqid(),
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

    private function insertOrder(array $attributes = []): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'shop_id' => $this->shopId,
            'uuid' => 'afrr-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_PREPARING,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    /** @test */
    public function it_propagates_replace_reason_id_from_assembly_item_to_order_item(): void
    {
        $originProductId = $this->insertProduct('Молоко 1л');
        $replacementProductId = $this->insertProduct('Молоко 1,5л');

        $orderId = $this->insertOrder();

        $orderItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            // product_id ещё исходный: ProductConfirm меняет только assembly_items,
            // подмену product_id на order_items делает сам collectOrderItems ниже.
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
            'quantity' => 2,
            'price' => 200,
            'status_id' => Status::PRODUCT_ASSEMBLED,
            'type_id' => Type::PRODUCT_NOT_EXISTS,
            'replace_reason_id' => $this->reason->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('replacement_products')->insert([
            'product_id' => $replacementProductId,
            'assembly_item_id' => $assemblyItemId,
            'selected' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assembly = Assembly::with(['order.shop', 'assemblyItems.orderItem.supplements'])->findOrFail($assemblyId);

        AssemblyFinish::collectOrderItems($assembly);

        $this->assertSame(
            $this->reason->id,
            (int) \DB::table('order_items')->where('id', $orderItemId)->value('replace_reason_id')
        );
        $this->assertSame(
            $originProductId,
            (int) \DB::table('order_items')->where('id', $orderItemId)->value('origin_product_id'),
            'origin_product_id должен проставиться при первой замене (гейт !origin_product_id)'
        );
    }

    /** @test */
    public function it_syncs_replace_reason_id_from_child_order_item_to_parent(): void
    {
        $replacementProductId = $this->insertProduct('Хлеб серый');

        $parentId = $this->insertOrder(['status_id' => Status::ORDER_PREPARING]);
        $childId = $this->insertOrder([
            'parent_order_id' => $parentId,
            'sub_number' => 1,
            'uuid' => 'afrr-child-' . uniqid(),
        ]);

        $parentItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $parentId,
            'product_id' => $replacementProductId,
            'quantity' => 1,
            'price' => 100,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'is_supplemented' => 0,
            'is_eligible' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('order_items')->insertGetId([
            'order_id' => $childId,
            'product_id' => $replacementProductId,
            'quantity' => 1,
            'price' => 100,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'is_supplemented' => 0,
            'is_eligible' => 1,
            'replace_reason_id' => $this->reason->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $childOrder = \App\Models\Order::findOrFail($childId);

        AssemblyFinish::syncItemsToParent($childOrder);

        $this->assertSame(
            $this->reason->id,
            (int) \DB::table('order_items')->where('id', $parentItemId)->value('replace_reason_id')
        );
    }
}
