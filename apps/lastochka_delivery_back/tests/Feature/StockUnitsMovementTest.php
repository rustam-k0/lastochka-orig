<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\GraphQL\Mutations\Assembly\ProductAddToOrder;
use App\GraphQL\Mutations\Delivery\DeliveryFinish;
use App\GraphQL\Mutations\Delivery\DeliveryTakeOrder;
use App\Models\Assembly;
use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Движения остатка (remains.quantity) в единице остатка — базовой единице 1С.
 *
 * Прод 2026-10-14: списание и возврат шли в порциях из остатка в кг. Товар
 * 1603 (порция 250 г): в 1С 3.004 кг, в курьерке 0.004 кг — после сборки
 * 3 порций (750 г) списали 3 кг, товар пропал с витрины при живом остатке.
 *
 * Классы товаров — эталон прода: A (база кг, порция 300 г), B (база кг,
 * порция 1 кг), C (штучный).
 */
class StockUnitsMovementTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $fcm = $this->mock(FcmNotificationService::class);
        $fcm->shouldReceive('sendToUser')->andReturnNull();
        $fcm->shouldReceive('sendToUsers')->andReturnNull();

        $this->clientId = \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Stock units shop',
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0,
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

    private function insertProduct(int $baseUnit, int $infoWeightTypeId, ?int $infoWeight, float $remain, float $price): int
    {
        $productId = \DB::table('products')->insertGetId([
            'title' => 'Stock units product ' . uniqid(),
            'articul' => 'SU-' . uniqid(),
            'shop_id' => $this->shopId,
            'unit_type_id' => $infoWeightTypeId === Type::INFO_WEIGHT_PIECE ? Type::UNIT_PIECE : 1,
            'base_unit_type_id' => $baseUnit,
            'info_weight' => $infoWeight,
            'info_weight_type_id' => $infoWeightTypeId,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('prices')->insert([
            'product_id' => $productId,
            'shop_id' => $this->shopId,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => $price,
            'step' => 1,
            'blocked' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('remains')->insert([
            'product_id' => $productId,
            'shop_id' => $this->shopId,
            'quantity' => $remain,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    /** Класс A: «Филе грудки», база кг, порция 300 г, 178.2 ₽ за порцию. */
    private function portionedProduct(float $remain = 12.806): int
    {
        return $this->insertProduct(Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_GRAM, 300, $remain, 178.2);
    }

    /** Класс B: база кг, порция 1 кг, 35 ₽ за кг. */
    private function kilogramProduct(float $remain = 26.555): int
    {
        return $this->insertProduct(Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_KILOGRAM, null, $remain, 35);
    }

    /** Класс C: штучный. */
    private function pieceProduct(float $remain = 3): int
    {
        return $this->insertProduct(Type::INFO_WEIGHT_PIECE, Type::INFO_WEIGHT_PIECE, null, $remain, 329);
    }

    private function insertOrder(int $statusId = Status::ORDER_PREPARING): int
    {
        return \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'su-' . uniqid(),
            'discount' => 0,
            'delivery_price' => 0,
            'bonus_spent' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => $statusId,
            'amount' => 1000,
            'is_heavy' => 0,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Заказ со сборкой одной позиции в статусе «собрано».
     *
     * @return array{order_id:int, assembly_id:int, order_item_id:int}
     */
    private function assembledItem(int $productId, int $portions, ?int $actualWeight, float $price): array
    {
        $orderId = $this->insertOrder();
        $assemblyId = \DB::table('assemblies')->insertGetId([
            'order_id' => $orderId,
            'user_id' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $productId,
            'quantity' => $portions,
            'price' => $price,
            'price_during_order' => $price,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('assembly_items')->insert([
            'assembly_id' => $assemblyId,
            'order_item_id' => $orderItemId,
            'quantity' => $actualWeight === null ? $portions : null,
            'actual_weight' => $actualWeight,
            'price' => $price * $portions,
            'status_id' => Status::PRODUCT_ASSEMBLED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['order_id' => $orderId, 'assembly_id' => $assemblyId, 'order_item_id' => $orderItemId];
    }

    private function remain(int $productId): float
    {
        return (float) \DB::table('remains')
            ->where('product_id', $productId)
            ->where('shop_id', $this->shopId)
            ->value('quantity');
    }

    private function collect(int $assemblyId): void
    {
        $assembly = Assembly::with([
            'order.shop',
            'assemblyItems.orderItem.supplements',
            'assemblyItems.replacements',
        ])->findOrFail($assemblyId);

        AssemblyFinish::collectOrderItems($assembly);
    }

    private function restore(int $assemblyId): void
    {
        $assembly = Assembly::with([
            'order.shop',
            'assemblyItems.replacements.product',
            'assemblyItems.orderItem.product',
        ])->findOrFail($assemblyId);

        AssemblyFinish::restoreAssembledStock($assembly);
    }

    /** @test */
    public function assembly_finish_deducts_actual_weight_of_portioned_product_in_kg(): void
    {
        $productId = $this->portionedProduct();
        $ids = $this->assembledItem($productId, 3, 528, 178.2);

        $this->collect($ids['assembly_id']);

        $this->assertEqualsWithDelta(12.278, $this->remain($productId), 0.0005);
    }

    /** @test */
    public function assembly_finish_deducts_ordered_portions_in_kg_when_not_weighed(): void
    {
        $productId = $this->portionedProduct();
        $ids = $this->assembledItem($productId, 3, null, 178.2);

        $this->collect($ids['assembly_id']);

        $this->assertEqualsWithDelta(11.906, $this->remain($productId), 0.0005);
    }

    /** @test */
    public function assembly_finish_deducts_actual_weight_of_kilogram_product(): void
    {
        $productId = $this->kilogramProduct();
        $ids = $this->assembledItem($productId, 1, 846, 35);

        $this->collect($ids['assembly_id']);

        $this->assertEqualsWithDelta(25.709, $this->remain($productId), 0.0005);
    }

    /** @test */
    public function assembly_finish_deducts_pieces(): void
    {
        $productId = $this->pieceProduct(3);
        $ids = $this->assembledItem($productId, 2, null, 329);

        $this->collect($ids['assembly_id']);

        $this->assertEqualsWithDelta(1.0, $this->remain($productId), 0.0005);
    }

    /** @test */
    public function small_remain_of_portioned_product_is_deducted_by_weight(): void
    {
        // Остаток 1.5 кг, собрано 528 г: раньше «2 порции > 1.5» — списание пропускалось.
        $productId = $this->portionedProduct(1.5);
        $ids = $this->assembledItem($productId, 3, 528, 178.2);

        $this->collect($ids['assembly_id']);

        $this->assertEqualsWithDelta(0.972, $this->remain($productId), 0.0005);
    }

    /** @test */
    public function cancel_after_assembly_restores_actual_weight_in_kg(): void
    {
        $productId = $this->portionedProduct(12.278);
        $ids = $this->assembledItem($productId, 3, 528, 178.2);

        $this->restore($ids['assembly_id']);

        $this->assertEqualsWithDelta(12.806, $this->remain($productId), 0.0005);
    }

    /** @test */
    public function cancel_after_assembly_restores_pieces(): void
    {
        $productId = $this->pieceProduct(1);
        $ids = $this->assembledItem($productId, 2, null, 329);

        $this->restore($ids['assembly_id']);

        $this->assertEqualsWithDelta(3.0, $this->remain($productId), 0.0005);
    }

    /**
     * Возврат курьером: заказ в доставке, позиция после AssemblyFinish.
     */
    private function finishDeliveryWithReturn(int $productId, int $portions, ?int $actualWeight, int $returned, float $linePrice): void
    {
        $orderId = $this->insertOrder(Status::ORDER_ASSEMBLED);
        \DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'quantity' => $portions,
            'actual_weight' => $actualWeight,
            'price' => $linePrice,
            'price_during_order' => $linePrice,
            'returned_quantity' => $returned,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $this->pieceProduct(10),
            'quantity' => 1,
            'price' => 329,
            'price_during_order' => 329,
            'returned_quantity' => 0,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $courierId = \DB::table('users')->insertGetId([
            'name' => 'Курьер',
            'surname' => 'Курьеров',
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $res = DeliveryTakeOrder::takeOrder($courierId, $orderId);
        $res['delivery']->update(['started_at' => now()]);
        $res['delivery_order']->update([
            'status_id' => Status::DELIVERY_IN_PROGRESS,
            'started_at' => now(),
        ]);
        Order::whereId($orderId)->update(['status_id' => Status::ORDER_IN_DELIVERY]);

        $deliveryOrder = $res['delivery_order']->fresh();
        DeliveryFinish::deliveryFinish($deliveryOrder, $deliveryOrder->delivery);
    }

    /** @test */
    public function courier_return_of_weighted_item_restores_actual_weight_in_kg(): void
    {
        // После AssemblyFinish: 528 г → 2 порции, весовой возвращается целиком.
        $productId = $this->portionedProduct(12.278);

        $this->finishDeliveryWithReturn($productId, 2, 528, 2, 313.63);

        $this->assertEqualsWithDelta(12.806, $this->remain($productId), 0.0005);
    }

    /** @test */
    public function courier_return_of_pieces_restores_pieces(): void
    {
        $productId = $this->pieceProduct(1);

        $this->finishDeliveryWithReturn($productId, 2, null, 1, 658);

        $this->assertEqualsWithDelta(2.0, $this->remain($productId), 0.0005);
    }

    /** @test */
    public function product_added_by_collector_is_deducted_once_at_assembly_finish(): void
    {
        $productId = $this->pieceProduct(10);
        $orderId = $this->insertOrder();
        $assemblyId = \DB::table('assemblies')->insertGetId([
            'order_id' => $orderId,
            'user_id' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new ProductAddToOrder())(null, [
            'assembly_id' => $assemblyId,
            'product_id' => $productId,
            'quantity' => 2,
        ]);

        $this->assertEqualsWithDelta(10.0, $this->remain($productId), 0.0005, 'Добавление не списывает: спишет AssemblyFinish');

        \DB::table('assembly_items')->where('assembly_id', $assemblyId)->update([
            'quantity' => 2,
            'status_id' => Status::PRODUCT_ASSEMBLED,
        ]);
        $this->collect($assemblyId);

        $this->assertEqualsWithDelta(8.0, $this->remain($productId), 0.0005);
    }

    /** @test */
    public function admin_force_assembly_keeps_item_already_weighed_by_collector(): void
    {
        // Сборщик взвесил 528 г (313.63 ₽), админка жмёт «Следующий шаг»:
        // REST /backend-delivery/order/assembly затирал позицию заказанными
        // 3 порциями по 178.2 → «528 г за 534.60 ₽».
        config(['services.system.token' => 'test-token']);
        $productId = $this->portionedProduct();
        $ids = $this->assembledItem($productId, 3, 528, 178.2);
        \DB::table('assembly_items')->where('assembly_id', $ids['assembly_id'])->update(['price' => 313.63]);

        $this->post('/backend-delivery/order/assembly', [
            'token' => 'test-token',
            'order_id' => $ids['order_id'],
        ])->assertStatus(200);

        $item = \DB::table('order_items')->where('id', $ids['order_item_id'])->first();
        $this->assertSame(313.63, (float) $item->price);
        $this->assertSame(528, (int) $item->actual_weight);
        $this->assertEqualsWithDelta(12.278, $this->remain($productId), 0.0005);

        // Строка сборки — источник для возврата остатка при отмене и аналитики:
        // подтверждённое сборщиком не перетирается заказанными порциями.
        $assemblyItem = \DB::table('assembly_items')->where('assembly_id', $ids['assembly_id'])->first();
        $this->assertSame(313.63, (float) $assemblyItem->price);
        $this->assertNull($assemblyItem->quantity);
    }

    /** @test */
    public function assembly_finish_remembers_ordered_portions(): void
    {
        // 3 порции по 300 г, взвешено 528 г → quantity 2 (факт), заказано 3.
        $productId = $this->portionedProduct();
        $ids = $this->assembledItem($productId, 3, 528, 178.2);

        $this->collect($ids['assembly_id']);

        $item = \DB::table('order_items')->where('id', $ids['order_item_id'])->first();
        $this->assertSame(2, (int) $item->quantity);
        $this->assertSame(3, (int) $item->ordered_quantity);

        // Повторная сборка заказанное не перетирает фактом
        \DB::table('assembly_items')->where('assembly_id', $ids['assembly_id'])->update(['status_id' => Status::PRODUCT_ASSEMBLED]);
        $this->collect($ids['assembly_id']);

        $this->assertSame(3, (int) \DB::table('order_items')->where('id', $ids['order_item_id'])->value('ordered_quantity'));
    }
}
