<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\GraphQL\Mutations\Order\OrderItemReturnByCourier;
use App\Models\Assembly;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Verify: списание/возврат остатка дочерней добавки-позиции (LASTOCHKI-JAMI-11211, Task 20).
 *
 * Три пути:
 * 1. Списание при AssemblyFinish::collectOrderItems — дочерняя позиция спишет remain.
 * 2. Восстановление при restoreAssembledStock — remain дочерней позиции восстанавливается.
 * 3. Возврат курьером (OrderItemReturnByCourier) — returned_quantity ставится на дочернюю.
 *
 * Дочерняя позиция = обычный order_item с parent_id IS NOT NULL.
 * Ни collectOrderItems, ни restoreAssembledStock, ни OrderItemReturnByCourier не
 * фильтруют по parent_id — дочерние обрабатываются штатно.
 */
class ChildSupplementStockTest extends TestCase
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

        // ignore_stock=0 — остатки учитываются
        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Supplement stock shop',
            'id_1c' => 999881,
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

    /**
     * Создаёт штучный продукт с ценой и остатком: остаток и позиция в штуках,
     * собранные 2 шт списывают ровно 2 (единицы весовых — StockUnitsMovementTest).
     */
    private function insertProductWithRemain(string $suffix, int $remainQty = 10): int
    {
        $productId = \DB::table('products')->insertGetId([
            'title' => 'Product ' . $suffix,
            'articul' => 'ART-STOCK-' . $suffix,
            'unit_type_id' => 2,
            'base_unit_type_id' => 1,
            'info_weight' => null,
            'info_weight_type_id' => 1,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('remains')->insert([
            'product_id' => $productId,
            'shop_id' => $this->shopId,
            'quantity' => $remainQty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('prices')->insert([
            'product_id' => $productId,
            'value' => 100,
            'step' => 1,
            'type_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    /**
     * Создаёт продукт без остатка (для корневой позиции, когда тестируем только добавку).
     */
    private function insertProduct(string $suffix): int
    {
        return \DB::table('products')->insertGetId([
            'title' => 'Product ' . $suffix,
            'articul' => 'ART-' . $suffix,
            'unit_type_id' => 1,
            'info_weight' => 100,
            'info_weight_type_id' => 2,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Создаёт заказ с корневой позицией (ignore_stock для корня) +
     * дочерней позицией-добавкой с остатком.
     *
     * Возвращает [orderId, rootItemId, childItemId, supplementProductId, assemblyId].
     */
    private function createOrderWithChildSupplement(int $childRemainQty = 10): array
    {
        // Корневой продукт — с остатком (effectiveStockIgnored=true чтобы не влиять)
        // Используем продукт без remain — магазин не ignore_stock, но корневой
        // assembly_item будет в статусе PRODUCT_CANCELED → не спишется.
        $rootProductId = $this->insertProduct('root-' . uniqid());

        // Продукт-добавка с остатком
        $supplementProductId = $this->insertProductWithRemain('supp-' . uniqid(), $childRemainQty);

        $orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'stock-supp-' . uniqid(),
            'discount' => 0,
            'delivery_price' => 0,
            'bonus_spent' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_PREPARING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Корневая позиция
        $rootItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $rootProductId,
            'quantity' => 1,
            'price' => 200,
            'price_during_order' => 200,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Создаём supplement-структуру (FK для source_supplement_value_id)
        $supplementGroupId = \DB::table('supplements')->insertGetId([
            'title' => 'Test Supplement',
            'product_id' => $rootProductId,
            'type' => 'many',
            'is_required' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supplementValueId = \DB::table('supplement_values')->insertGetId([
            'title' => 'Test Supplement Value',
            'supplement_id' => $supplementGroupId,
            'product_id' => $supplementProductId,
            'price' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Дочерняя позиция-добавка
        $childItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $supplementProductId,
            'quantity' => 2,
            'price' => 100,
            'price_during_order' => 50,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'parent_id' => $rootItemId,
            'source_supplement_value_id' => $supplementValueId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Assembly
        $assemblyId = \DB::table('assemblies')->insertGetId([
            'order_id' => $orderId,
            'user_id' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // assembly_item для корневой позиции (PRODUCT_CANCELED — чтобы не влиять на расчёт)
        \DB::table('assembly_items')->insert([
            'assembly_id' => $assemblyId,
            'order_item_id' => $rootItemId,
            'quantity' => 1,
            'status_id' => Status::PRODUCT_CANCELED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // assembly_item для дочерней позиции-добавки (PRODUCT_ASSEMBLED — реально собрана)
        \DB::table('assembly_items')->insert([
            'assembly_id' => $assemblyId,
            'order_item_id' => $childItemId,
            'quantity' => 2,
            'status_id' => Status::PRODUCT_ASSEMBLED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$orderId, $rootItemId, $childItemId, $supplementProductId, $assemblyId];
    }

    /**
     * Путь 1: AssemblyFinish::collectOrderItems списывает остаток дочерней позиции-добавки.
     *
     * Дочерняя позиция (parent_id IS NOT NULL) — это обычный order_item.
     * collectOrderItems итерирует ВСЕ assembly_items без фильтра по parent_id.
     * Remain товара-добавки должен уменьшиться на qty=2.
     */
    public function test_collect_order_items_deducts_child_supplement_remain(): void
    {
        $remainBefore = 10;
        [$orderId, , $childItemId, $supplementProductId, $assemblyId] =
            $this->createOrderWithChildSupplement($remainBefore);

        $assembly = Assembly::with([
            'order.shop',
            'assemblyItems.orderItem.supplements',
            'assemblyItems.replacements',
        ])->findOrFail($assemblyId);

        AssemblyFinish::collectOrderItems($assembly);

        $remainAfter = (int) \DB::table('remains')
            ->where('product_id', $supplementProductId)
            ->where('shop_id', $this->shopId)
            ->value('quantity');

        $this->assertSame(
            $remainBefore - 2,
            $remainAfter,
            'Remain товара-добавки должен уменьшиться на собранное кол-во (qty=2)'
        );

        // Дочерняя order_item переходит в ASSEMBLED
        $childItem = \DB::table('order_items')->where('id', $childItemId)->first();
        $this->assertEquals(
            Status::ORDER_ITEM_ASSEMBLED,
            $childItem->status_id,
            'Статус дочерней order_item должен стать ORDER_ITEM_ASSEMBLED'
        );
    }

    /**
     * Путь 2: restoreAssembledStock восстанавливает остаток дочерней позиции-добавки.
     *
     * restoreAssembledStock итерирует ВСЕ assembly_items со статусом PRODUCT_ASSEMBLED.
     * Дочерний assembly_item включается штатно — remain должен вернуться.
     */
    public function test_restore_assembled_stock_restores_child_supplement_remain(): void
    {
        $remainBefore = 10;
        [$orderId, , , $supplementProductId, $assemblyId] =
            $this->createOrderWithChildSupplement($remainBefore);

        // assembly_item дочерней уже в PRODUCT_ASSEMBLED (из createOrderWithChildSupplement)
        $assembly = Assembly::with([
            'order.shop',
            'assemblyItems.replacements.product',
            'assemblyItems.orderItem.product',
        ])->findOrFail($assemblyId);

        AssemblyFinish::restoreAssembledStock($assembly);

        $remainAfter = (int) \DB::table('remains')
            ->where('product_id', $supplementProductId)
            ->where('shop_id', $this->shopId)
            ->value('quantity');

        $this->assertSame(
            $remainBefore + 2,
            $remainAfter,
            'Remain товара-добавки должен увеличиться на 2 при восстановлении остатка'
        );

        // assembly_item переведён в PRODUCT_CANCELED (идемпотентность):
        // ни одного assembly_item в PRODUCT_ASSEMBLED не должно остаться
        $assembledCount = \DB::table('assembly_items')
            ->where('assembly_id', $assemblyId)
            ->where('status_id', Status::PRODUCT_ASSEMBLED)
            ->count();

        $this->assertSame(
            0,
            $assembledCount,
            'После restoreAssembledStock ни одного assembly_item не должно оставаться в PRODUCT_ASSEMBLED'
        );
    }

    /**
     * Путь 3: OrderItemReturnByCourier устанавливает returned_quantity на дочерней позиции.
     *
     * OrderItemReturnByCourier ищет order_item по order_id без фильтра по parent_id —
     * дочерняя позиция доступна для возврата штатно.
     */
    public function test_order_item_return_by_courier_sets_returned_quantity_on_child(): void
    {
        [$orderId, , $childItemId] = $this->createOrderWithChildSupplement();

        // Переводим заказ и дочернюю позицию в статус, при котором возврат допустим
        \DB::table('orders')->where('id', $orderId)->update([
            'status_id' => Status::ORDER_IN_DELIVERY,
        ]);
        \DB::table('order_items')->where('id', $childItemId)->update([
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'quantity' => 2,
        ]);

        // Тест про частичный возврат по количеству — товар-добавка штучный.
        // Общая фикстура создаёт продукт в граммах, а весовой по карточке
        // возвращается только целиком (см. CourierReturnWeightedItemTest).
        $childProductId = \DB::table('order_items')->where('id', $childItemId)->value('product_id');
        \DB::table('products')->where('id', $childProductId)->update([
            'info_weight' => null,
            'info_weight_type_id' => 1,
        ]);

        // Создаём Delivery + DeliveryOrder (требуется OrderItemReturnByCourier)
        $deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deliveryOrderId = \DB::table('delivery_orders')->insertGetId([
            'delivery_id' => $deliveryId,
            'order_id' => $orderId,
            'status_id' => \App\Models\Status::DELIVERY_IN_PROGRESS,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mutation = new OrderItemReturnByCourier();
        $result = $mutation(null, [
            'delivery_order_id' => $deliveryOrderId,
            'order_item_id' => $childItemId,
            'quantity' => 1,
        ]);

        $this->assertSame(200, $result['code']);

        $childItem = \DB::table('order_items')->where('id', $childItemId)->first();
        $this->assertSame(
            1,
            (int) $childItem->returned_quantity,
            'returned_quantity дочерней позиции-добавки должен стать 1'
        );
    }
}
