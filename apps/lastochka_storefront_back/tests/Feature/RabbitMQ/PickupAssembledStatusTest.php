<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Enums\OrderStatus;
use App\Enums\ShippingMethod;
use App\Jobs\SendOrderStatusNotification;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * LASTOCHKI-JAMI-11007: при самовывозе статус заказа застревал на «Заказ
 * собирается» и не переходил в «Заказ собран». Причина — handlePickupAssembled()
 * обращался к несуществующей связи Product::category() (правильная связь —
 * categories(): BelongsToMany), из-за чего beforeUpdate() бросал
 * BadMethodCallException, обработка входящего order.updated(assembled) откатывалась
 * (→ DLQ после 3 ретраев), и статус Assembled не сохранялся в storefront.
 * Срабатывало только для pickup (ветка вызывается лишь при Assembled + Pickup
 * и включённом store.pickup_auto_cancel_enabled), поэтому доставка не ломалась.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PickupAssembledStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Регрессия: beforeUpdate для pickup-заказа на статусе assembled НЕ должен
     * бросать исключение и обязан сохранить status=Assembled в transformedData
     * (а также выставить таймер авто-отмены).
     */
    public function test_pickup_assembled_does_not_throw_and_keeps_status(): void
    {
        Bus::fake([SendOrderStatusNotification::class]);
        Carbon::setTestNow('2026-06-04 15:10:00');

        [$order] = $this->makePickupOrderWithItem(isPerishable: false);

        $transformer = new OrderEventTransformer;

        $eventData = ['event_id' => Str::uuid()->toString(), 'event_type' => 'updated'];
        $transformedData = ['status' => OrderStatus::Assembled];

        // На старом коде (product.category) здесь летит BadMethodCallException.
        $result = $transformer->beforeUpdate($order, $eventData, $transformedData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result, 'status должен остаться — заказ не terminal');
        $this->assertSame(OrderStatus::Assembled, $result['status']);

        // Таймер авто-отмены проставлен: regular(3) + grace(1) = 4 часа от now.
        $order->refresh();
        $this->assertNotNull($order->pickup_timer_expires_at, 'pickup_timer_expires_at должен быть установлен');
        $this->assertSame(
            Carbon::parse('2026-06-04 19:10:00')->toDateTimeString(),
            Carbon::parse($order->pickup_timer_expires_at)->toDateTimeString(),
        );
    }

    /**
     * Связь product.categories реально читается: для товара из скоропортящейся
     * категории берётся perishable-окно (1ч) + grace(1ч) = 2 часа.
     */
    public function test_pickup_assembled_perishable_uses_perishable_window(): void
    {
        Bus::fake([SendOrderStatusNotification::class]);
        Carbon::setTestNow('2026-06-04 15:10:00');

        [$order] = $this->makePickupOrderWithItem(isPerishable: true);

        $transformer = new OrderEventTransformer;

        $result = $transformer->beforeUpdate(
            $order,
            ['event_id' => Str::uuid()->toString(), 'event_type' => 'updated'],
            ['status' => OrderStatus::Assembled],
        );

        $this->assertSame(OrderStatus::Assembled, $result['status']);

        $order->refresh();
        $this->assertSame(
            Carbon::parse('2026-06-04 17:10:00')->toDateTimeString(),
            Carbon::parse($order->pickup_timer_expires_at)->toDateTimeString(),
        );
    }

    /**
     * @return array{0: Order}
     */
    private function makePickupOrderWithItem(bool $isPerishable): array
    {
        $store = Store::factory()->create([
            'code' => 'MSK_001',
            'pickup_auto_cancel_enabled' => true,
            'pickup_wait_perishable_hours' => 1,
            'pickup_wait_regular_hours' => 3,
            'pickup_grace_period_hours' => 1,
        ]);
        $user = User::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Assembling,
            'shipping_method' => ShippingMethod::Pickup,
        ]);

        $category = Category::factory()->create([
            'is_perishable' => $isPerishable,
        ]);
        $product = Product::factory()->create(['store_id' => $store->id]);
        $product->categories()->attach($category->id, ['position' => 0]);

        OrderItem::factory()->for($order)->create(['product_id' => $product->id]);

        return [$order];
    }
}
