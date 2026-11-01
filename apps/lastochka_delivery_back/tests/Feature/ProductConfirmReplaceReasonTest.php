<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\ProductConfirm;
use App\Models\ProductReplaceReason;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Причина замены товара (Platrum MEN-IN-DEV-11280): при реальной замене
 * (ProductConfirm с replacement_product_id) сборщик может передать
 * replace_reason_id — должен осесть на assembly_items, по аналогии
 * с cancel_reason_id в ProductCancel.
 */
class ProductConfirmReplaceReasonTest extends TestCase
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
            'title' => 'Replace reason shop',
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
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertProduct(string $title): int
    {
        $productId = \DB::table('products')->insertGetId([
            'title' => $title,
            'articul' => 'PCRR-' . uniqid(),
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

    private function insertOrderWithItem(int $productId): array
    {
        $orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'pcrr-' . uniqid(),
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

        return [$orderId, $orderItemId];
    }

    private function insertAssemblyWithItem(int $orderId, int $orderItemId): array
    {
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

        return [$assemblyId, $assemblyItemId];
    }

    /** @test */
    public function it_stores_replace_reason_id_on_assembly_item_when_product_is_replaced(): void
    {
        $originProductId = $this->insertProduct('Молоко 1л');
        $replacementProductId = $this->insertProduct('Молоко 1,5л');

        [$orderId, $orderItemId] = $this->insertOrderWithItem($originProductId);
        [, $assemblyItemId] = $this->insertAssemblyWithItem($orderId, $orderItemId);

        \DB::table('replacement_products')->insert([
            'product_id' => $replacementProductId,
            'assembly_item_id' => $assemblyItemId,
            'selected' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reason = ProductReplaceReason::create([
            'title' => 'Нет в наличии',
            'is_active' => true,
            'position' => 1,
        ]);

        (new ProductConfirm())->__invoke(null, [
            'assembly_item_id' => $assemblyItemId,
            'replacement_product_id' => $replacementProductId,
            'quantity' => 2,
            'replace_reason_id' => $reason->id,
        ]);

        $this->assertSame(
            $reason->id,
            (int) \DB::table('assembly_items')->where('id', $assemblyItemId)->value('replace_reason_id')
        );

        $this->assertSame(
            1,
            (int) \DB::table('replacement_products')
                ->where('assembly_item_id', $assemblyItemId)
                ->where('product_id', $replacementProductId)
                ->value('selected')
        );
    }

    /** @test */
    public function it_allows_replacement_without_reason(): void
    {
        $originProductId = $this->insertProduct('Хлеб белый');
        $replacementProductId = $this->insertProduct('Хлеб серый');

        [$orderId, $orderItemId] = $this->insertOrderWithItem($originProductId);
        [, $assemblyItemId] = $this->insertAssemblyWithItem($orderId, $orderItemId);

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

        $this->assertNull(
            \DB::table('assembly_items')->where('id', $assemblyItemId)->value('replace_reason_id')
        );
    }
}
