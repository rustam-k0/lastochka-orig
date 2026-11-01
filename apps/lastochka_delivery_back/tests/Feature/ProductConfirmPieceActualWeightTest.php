<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\ProductConfirm;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MID-246: actual_weight для ШТУЧНОГО товара не должен включать «весовой» путь.
 *
 * Приложение сборщика может прислать ProductConfirm(actual_weight: N) для товара
 * с info_weight_type_id=1/NULL (штучный). До фикса это давало getStepGrams()=1 →
 * цена трактовалась «за грамм»: price = price_during_order × N (кофе за 1900 ₽
 * превращался в 380 000 ₽), а залипший actual_weight через AssemblyFinish и
 * событие order.updated раздувал quantity/total у клиента на витрине.
 */
class ProductConfirmPieceActualWeightTest extends TestCase
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
            'title' => 'Piece weight shop',
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

    private function insertProduct(?int $infoWeight, ?int $infoWeightTypeId): int
    {
        $productId = \DB::table('products')->insertGetId([
            'title' => 'PCPAW ' . uniqid(),
            'articul' => 'PCPAW-' . uniqid(),
            'unit_type_id' => Type::UNIT_PIECE,
            'info_weight' => $infoWeight,
            'info_weight_type_id' => $infoWeightTypeId,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('prices')->insert([
            'product_id' => $productId,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 1900,
            'step' => 1,
            'blocked' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    /**
     * @return array{0: int, 1: int} [orderId, orderItemId]
     */
    private function insertOrderWithItem(int $productId, int $quantity, float $priceDuringOrder): array
    {
        $orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'pcpaw-' . uniqid(),
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
            'quantity' => $quantity,
            'price' => $priceDuringOrder * $quantity,
            'price_during_order' => $priceDuringOrder,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'is_supplemented' => 0,
            'is_eligible' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$orderId, $orderItemId];
    }

    private function insertAssemblyItem(int $orderId, int $orderItemId, ?int $staleActualWeight = null): int
    {
        $assemblyId = \DB::table('assemblies')->insertGetId([
            'order_id' => $orderId,
            'user_id' => $this->collectorId,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \DB::table('assembly_items')->insertGetId([
            'assembly_id' => $assemblyId,
            'order_item_id' => $orderItemId,
            'status_id' => Status::PRODUCT_WAIT,
            'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
            'actual_weight' => $staleActualWeight,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assemblyItemRow(int $id): object
    {
        return \DB::table('assembly_items')->where('id', $id)->first();
    }

    /** @test */
    public function piece_product_confirm_with_actual_weight_ignores_weight_and_uses_ordered_quantity(): void
    {
        // Кейс заказа 13: кофе 1900 ₽, 1 шт, info_weight_type_id=1, info_weight NULL.
        $productId = $this->insertProduct(null, Type::INFO_WEIGHT_PIECE);
        [$orderId, $orderItemId] = $this->insertOrderWithItem($productId, 1, 1900);
        $assemblyItemId = $this->insertAssemblyItem($orderId, $orderItemId);

        (new ProductConfirm())->__invoke(null, [
            'assembly_item_id' => $assemblyItemId,
            'actual_weight' => 200,
        ]);

        $row = $this->assemblyItemRow($assemblyItemId);

        $this->assertSame(1, (int) $row->quantity);
        $this->assertSame(1900.0, (float) $row->price);
        $this->assertNull($row->actual_weight);
    }

    /** @test */
    public function piece_product_without_info_weight_type_confirm_with_actual_weight_ignores_weight(): void
    {
        // info_weight_type_id NULL (не заполнен из 1С) — тоже штучный, вес игнорируется.
        $productId = $this->insertProduct(null, null);
        [$orderId, $orderItemId] = $this->insertOrderWithItem($productId, 2, 500);
        $assemblyItemId = $this->insertAssemblyItem($orderId, $orderItemId);

        (new ProductConfirm())->__invoke(null, [
            'assembly_item_id' => $assemblyItemId,
            'actual_weight' => 150,
        ]);

        $row = $this->assemblyItemRow($assemblyItemId);

        $this->assertSame(2, (int) $row->quantity);
        $this->assertSame(1000.0, (float) $row->price);
        $this->assertNull($row->actual_weight);
    }

    /** @test */
    public function piece_product_reconfirm_clears_stale_actual_weight(): void
    {
        // Повторное подтверждение ×1 (как в заказе 13) должно вычищать залипший
        // вес — иначе toEvent подтянет его fallback'ом и испортит событие.
        $productId = $this->insertProduct(null, Type::INFO_WEIGHT_PIECE);
        [$orderId, $orderItemId] = $this->insertOrderWithItem($productId, 1, 1900);
        $assemblyItemId = $this->insertAssemblyItem($orderId, $orderItemId, staleActualWeight: 200);

        (new ProductConfirm())->__invoke(null, [
            'assembly_item_id' => $assemblyItemId,
            'quantity' => 1,
        ]);

        $row = $this->assemblyItemRow($assemblyItemId);

        $this->assertSame(1, (int) $row->quantity);
        $this->assertSame(1900.0, (float) $row->price);
        $this->assertNull($row->actual_weight);
    }

    /** @test */
    public function weight_product_confirm_with_actual_weight_keeps_weight_pricing(): void
    {
        // Регресс: настоящий весовой товар (квант 100 гр) считается по весу как раньше.
        $productId = $this->insertProduct(100, Type::INFO_WEIGHT_GRAM);
        [$orderId, $orderItemId] = $this->insertOrderWithItem($productId, 1, 150);
        $assemblyItemId = $this->insertAssemblyItem($orderId, $orderItemId);

        (new ProductConfirm())->__invoke(null, [
            'assembly_item_id' => $assemblyItemId,
            'actual_weight' => 250,
        ]);

        $row = $this->assemblyItemRow($assemblyItemId);

        $this->assertNull($row->quantity);
        $this->assertSame(375.0, (float) $row->price);
        $this->assertSame(250, (int) $row->actual_weight);
    }

    /** @test */
    public function piece_replacement_confirm_with_actual_weight_ignores_weight(): void
    {
        // Замена на штучный товар: вес игнорируется, цена = цена замены × количество.
        $originProductId = $this->insertProduct(null, Type::INFO_WEIGHT_PIECE);
        $replacementProductId = $this->insertProduct(null, Type::INFO_WEIGHT_PIECE);

        [$orderId, $orderItemId] = $this->insertOrderWithItem($originProductId, 1, 1900);
        $assemblyItemId = $this->insertAssemblyItem($orderId, $orderItemId);

        \DB::table('assembly_items')->where('id', $assemblyItemId)->update([
            'type_id' => Type::PRODUCT_NOT_EXISTS,
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
            'quantity' => 1,
            'actual_weight' => 200,
        ]);

        $row = $this->assemblyItemRow($assemblyItemId);

        $this->assertSame(1, (int) $row->quantity);
        $this->assertSame(1900.0, (float) $row->price);
        $this->assertNull($row->actual_weight);
    }
}
