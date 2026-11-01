<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Каскадное снятие осиротевших assembly_items при удалении order_item
 * синхронизацией события order.updated («загадочный товар» сборщика).
 *
 * Баг: assembly_item создаётся в OrderPrepare со ссылкой order_item_id; при
 * последующей синхронизации события order_item soft-удаляется
 * (whereNotIn(processed)->delete()), а assembly_item остаётся сиротой →
 * AssemblyItem::orderItem() (belongsTo без withTrashed) резолвится в null →
 * пустая неоткрываемая карточка в мобилке + незакрытый PRODUCT_WAIT блокирует
 * AssemblyFinish. Фикс — removeAssemblyItemsForRemovedOrderItems в afterUpdate.
 */
class OrphanedAssemblyItemsCleanupTest extends TestCase
{
    use DatabaseTransactions;

    private function invokeRemove(Order $order, array $processedItemIds): void
    {
        $transformer = new OrderEventTransformer();
        $ref = new \ReflectionMethod($transformer, 'removeAssemblyItemsForRemovedOrderItems');
        $ref->setAccessible(true);
        $ref->invoke($transformer, $order, $processedItemIds);
    }

    private function insertProduct(): int
    {
        return \DB::table('products')->insertGetId([
            'title' => 'P ' . uniqid(),
            'unit_type_id' => 1,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{order:Order, keep_item:int, remove_item:int, keep_ai:int, remove_ai:int}
     */
    private function makeAssemblyScenario(): array
    {
        $clientId = \DB::table('clients')->insertGetId([
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = \DB::table('users')->insertGetId([
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = $this->insertProduct();

        $orderId = \DB::table('orders')->insertGetId([
            'uuid' => 'orph-' . uniqid(),
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'type_id' => 2,
            'status_id' => Status::ORDER_PREPARING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $keepItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $productId,
            'quantity' => 1,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $removeItemId = \DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $productId,
            'quantity' => 1,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assemblyId = \DB::table('assemblies')->insertGetId([
            'order_id' => $orderId,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $keepAiId = \DB::table('assembly_items')->insertGetId([
            'assembly_id' => $assemblyId,
            'order_item_id' => $keepItemId,
            'status_id' => Status::PRODUCT_WAIT,
            'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $removeAiId = \DB::table('assembly_items')->insertGetId([
            'assembly_id' => $assemblyId,
            'order_item_id' => $removeItemId,
            'status_id' => Status::PRODUCT_WAIT,
            'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'order' => Order::findOrFail($orderId),
            'keep_item' => $keepItemId,
            'remove_item' => $removeItemId,
            'keep_ai' => $keepAiId,
            'remove_ai' => $removeAiId,
        ];
    }

    /** @test */
    public function orphaned_assembly_item_is_soft_deleted_when_order_item_removed(): void
    {
        $s = $this->makeAssemblyScenario();

        // Событие оставило только keep_item → remove_item удаляется из заказа
        $this->invokeRemove($s['order'], [$s['keep_item']]);

        // assembly_item удалённого order_item — soft-deleted (не осиротел)
        $this->assertNotNull(
            \DB::table('assembly_items')->where('id', $s['remove_ai'])->value('deleted_at'),
            'assembly_item удалённого order_item должен быть soft-deleted'
        );
        // assembly_item оставшейся позиции — нетронут
        $this->assertNull(
            \DB::table('assembly_items')->where('id', $s['keep_ai'])->value('deleted_at'),
            'assembly_item оставшейся позиции не должен быть тронут'
        );
    }

    /** @test */
    public function nothing_removed_when_all_items_retained(): void
    {
        $s = $this->makeAssemblyScenario();

        // Все позиции остаются в заказе → снимать нечего
        $this->invokeRemove($s['order'], [$s['keep_item'], $s['remove_item']]);

        $this->assertNull(
            \DB::table('assembly_items')->where('id', $s['keep_ai'])->value('deleted_at')
        );
        $this->assertNull(
            \DB::table('assembly_items')->where('id', $s['remove_ai'])->value('deleted_at')
        );
    }
}
