<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Order\OrderItemReturnByCourier;
use App\GraphQL\Mutations\Order\OrderItemReturnCancelByCourier;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Verify: возврат курьером весовой позиции, собранной БЕЗ взвешивания,
 * и немедленный пересчёт суммы заказа.
 *
 * Прод-кейс 2026-09-11 (заказ 485, позиция 1047 «Колбаса вареная Султанская»):
 * товар весовой по карточке (info_weight_type_id=3), но actual_weight = NULL —
 * сборщик подтвердил позицию без взвешивания. Приложение курьера считает такой
 * товар весовым и не шлёт quantity, а бэк раньше определял весовой ТОЛЬКО по
 * actual_weight → «Укажите количество для возврата», позиция не возвращалась.
 */
class CourierReturnWeightedItemTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;
    private int $userId;

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
            'title' => 'Courier return shop',
            'id_1c' => 999882,
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 1,
            'is_bonus_enabled' => 0,
            'bonus_spend_percent' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->userId = \DB::table('users')->insertGetId([
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param int $infoWeightTypeId 1 — штучный, 3 — килограммы (весовой)
     */
    private function insertProduct(string $suffix, int $infoWeightTypeId): int
    {
        return \DB::table('products')->insertGetId([
            'title' => 'Product ' . $suffix,
            'articul' => 'ART-RET-' . $suffix,
            'unit_type_id' => 1,
            'info_weight' => null,
            'info_weight_type_id' => $infoWeightTypeId,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Заказ в доставке с двумя позициями: весовая без actual_weight и штучная.
     *
     * @return array{0:int,1:int,2:int,3:int} orderId, weightedItemId, pieceItemId, deliveryOrderId
     */
    private function createOrderInDelivery(): array
    {
        $weightedProductId = $this->insertProduct('WEIGHTED', 3);
        $pieceProductId = $this->insertProduct('PIECE', 1);

        $orderId = \DB::table('orders')->insertGetId([
            'uuid' => 'test-' . uniqid(),
            'client_id' => $this->clientId,
            'shop_id' => $this->shopId,
            'status_id' => Status::ORDER_IN_DELIVERY,
            'type_id' => 2,
            'payment_type_id' => 2,
            'paid' => 0,
            'amount' => 546,
            'items_subtotal' => 446,
            'discount' => 0,
            'bonus_spent' => 0,
            'delivery_price' => 100,
            'assembly_cost' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Весовая позиция, собранная без взвешивания: actual_weight NULL, quantity 1
        $weightedItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $weightedProductId,
            'quantity' => 1,
            'returned_quantity' => 0,
            'actual_weight' => null,
            'price' => 446,
            'price_during_order' => 446,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pieceItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $pieceProductId,
            'quantity' => 2,
            'returned_quantity' => 0,
            'actual_weight' => null,
            'price' => 200,
            'price_during_order' => 100,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deliveryOrderId = \DB::table('delivery_orders')->insertGetId([
            'delivery_id' => $deliveryId,
            'order_id' => $orderId,
            'status_id' => Status::DELIVERY_IN_PROGRESS,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$orderId, $weightedItemId, $pieceItemId, $deliveryOrderId];
    }

    public function test_weighted_item_without_actual_weight_is_returned_fully(): void
    {
        [, $weightedItemId, , $deliveryOrderId] = $this->createOrderInDelivery();

        $result = (new OrderItemReturnByCourier())(null, [
            'delivery_order_id' => $deliveryOrderId,
            'order_item_id' => $weightedItemId,
            'reason_id' => null,
        ]);

        $this->assertSame(200, $result['code']);

        $item = \DB::table('order_items')->where('id', $weightedItemId)->first();
        $this->assertSame(
            1,
            (int) $item->returned_quantity,
            'Весовая позиция без actual_weight должна возвращаться целиком, без quantity от мобилки'
        );
    }

    public function test_order_amount_recalculated_right_after_return(): void
    {
        [$orderId, $weightedItemId, , $deliveryOrderId] = $this->createOrderInDelivery();

        (new OrderItemReturnByCourier())(null, [
            'delivery_order_id' => $deliveryOrderId,
            'order_item_id' => $weightedItemId,
            'reason_id' => null,
        ]);

        $order = \DB::table('orders')->where('id', $orderId)->first();

        // Осталась только штучная позиция: 2 × 100 = 200 + доставка 100
        $this->assertSame(200.0, (float) $order->items_subtotal);
        $this->assertSame(
            300.0,
            (float) $order->amount,
            'Сумма к оплате должна уменьшиться сразу при отметке возврата, не дожидаясь завершения доставки'
        );
    }

    public function test_partial_piece_return_recalculates_amount(): void
    {
        [$orderId, , $pieceItemId, $deliveryOrderId] = $this->createOrderInDelivery();

        (new OrderItemReturnByCourier())(null, [
            'delivery_order_id' => $deliveryOrderId,
            'order_item_id' => $pieceItemId,
            'quantity' => 1,
            'reason_id' => null,
        ]);

        $order = \DB::table('orders')->where('id', $orderId)->first();

        // Весовая 446 + одна оставшаяся штука 100 = 546 + доставка 100
        $this->assertSame(546.0, (float) $order->items_subtotal);
        $this->assertSame(646.0, (float) $order->amount);
    }

    public function test_return_cancel_restores_order_amount(): void
    {
        [$orderId, $weightedItemId, , $deliveryOrderId] = $this->createOrderInDelivery();

        (new OrderItemReturnByCourier())(null, [
            'delivery_order_id' => $deliveryOrderId,
            'order_item_id' => $weightedItemId,
            'reason_id' => null,
        ]);

        (new OrderItemReturnCancelByCourier())(null, [
            'delivery_order_id' => $deliveryOrderId,
            'order_item_id' => $weightedItemId,
        ]);

        $order = \DB::table('orders')->where('id', $orderId)->first();

        $this->assertSame(646.0, (float) $order->items_subtotal);
        $this->assertSame(
            746.0,
            (float) $order->amount,
            'Снятие возврата возвращает полную сумму к оплате'
        );
    }

    public function test_piece_item_still_requires_quantity(): void
    {
        [, , $pieceItemId, $deliveryOrderId] = $this->createOrderInDelivery();

        $this->expectException(\GraphQL\Error\UserError::class);
        $this->expectExceptionMessage('Укажите количество для возврата');

        (new OrderItemReturnByCourier())(null, [
            'delivery_order_id' => $deliveryOrderId,
            'order_item_id' => $pieceItemId,
            'reason_id' => null,
        ]);
    }

    public function test_weighed_item_total_is_snapshot_when_step_changed_after_assembly(): void
    {
        // Филе: 3 порции по 300 г, взвешено 528 г → итог строки 313.63 (снимок
        // ProductConfirm в order_items.price). Фасовку товара поменяли на 500 г
        // после сборки: пересчёт по живому шагу дал бы 178.2 × 528 / 500 = 188.18.
        [$orderId, $weightedItemId, $pieceItemId, $deliveryOrderId] = $this->createOrderInDelivery();
        $productId = \DB::table('order_items')->where('id', $weightedItemId)->value('product_id');
        \DB::table('products')->where('id', $productId)->update(['info_weight' => 300, 'info_weight_type_id' => 2]);
        \DB::table('order_items')->where('id', $weightedItemId)->update([
            'quantity' => 2,
            'actual_weight' => 528,
            'price' => 313.63,
            'price_during_order' => 178.2,
        ]);
        \DB::table('products')->where('id', $productId)->update(['info_weight' => 500]);

        // Возврат одной штуки другой позиции запускает пересчёт суммы заказа
        (new OrderItemReturnByCourier())(null, [
            'delivery_order_id' => $deliveryOrderId,
            'order_item_id' => $pieceItemId,
            'quantity' => 1,
            'reason_id' => null,
        ]);

        // 313.63 (снимок) + 1 × 100 = 413.63
        $this->assertSame(413.63, (float) \DB::table('orders')->where('id', $orderId)->value('items_subtotal'));
    }
}
