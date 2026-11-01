<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CancelOrderAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отмена заказа магазина, удалённого уже после создания заказа.
 *
 * Прод-кейс: магазин заказа помечен deleted_at, связь store() его не отдаёт,
 * и публикация события падала на обращении к code — отмена возвращала 500.
 */
final class CancelOrderDeletedStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_of_soft_deleted_store_can_be_cancelled(): void
    {
        $store = Store::factory()->create(['code' => 'gone-shop']);
        $order = Order::factory()->for(User::factory()->create())->for($store)->create([
            'status' => OrderStatus::Pending,
            'payment_type' => PaymentType::Cash,
            'total' => 100,
        ]);

        $store->delete();
        $order->refresh();

        $cancelled = app(CancelOrderAction::class)->execute($order);

        $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
    }

    public function test_shop_code_for_events_survives_deleted_store(): void
    {
        $store = Store::factory()->create(['code' => 'gone-shop-2']);
        $order = Order::factory()->for(User::factory()->create())->for($store)->create([
            'status' => OrderStatus::Pending,
        ]);

        $store->delete();

        // Свежая модель без загруженной связи: код должен подняться с withTrashed.
        $fresh = Order::query()->findOrFail($order->id);

        $this->assertSame('gone-shop-2', $fresh->shopCodeForEvents());
    }

    public function test_shop_code_for_events_uses_loaded_relation(): void
    {
        $store = Store::factory()->create(['code' => 'loaded-shop']);
        $order = Order::factory()->for(User::factory()->create())->for($store)->create([
            'status' => OrderStatus::Pending,
        ]);

        $order->load('store');

        // Связь уже загружена — код берём из неё, без повторного запроса.
        $this->assertSame('loaded-shop', $order->shopCodeForEvents());
    }
}
