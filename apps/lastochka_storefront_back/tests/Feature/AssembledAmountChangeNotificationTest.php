<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Jobs\SendOrderStatusNotification;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use App\Models\UserNotification;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\FirebaseNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Уведомление «Собран» при оплате картой называет изменение суммы:
 * held_amount (заблокировано) против captured_amount (списано по итогам сборки).
 */
final class AssembledAmountChangeNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const NBSP = "\u{00A0}";

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $firebase = \Mockery::mock(FirebaseNotificationService::class);
        $firebase->shouldReceive('sendToUser')->zeroOrMoreTimes()
            ->andReturn(['success' => 1, 'failure' => 0, 'total' => 1]);
        $this->app->instance(FirebaseNotificationService::class, $firebase);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(array $attributes = []): Order
    {
        $user = User::factory()->create(['orders_enabled' => true, 'push_enabled' => true]);

        return Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'status' => OrderStatus::Assembling,
            'shipping_method' => ShippingMethod::Courier,
            'payment_type' => PaymentType::Card,
            'total' => 1000,
            'held_amount' => 1000,
            'captured_amount' => 1000,
        ], $attributes));
    }

    private function lastNotification(Order $order): UserNotification
    {
        return UserNotification::query()->where('user_id', $order->user_id)->latest('id')->firstOrFail();
    }

    private function rub(string $amount): string
    {
        return str_replace(' ', self::NBSP, $amount) . self::NBSP . '₽';
    }

    public function test_decreased_amount_tells_difference_will_be_returned(): void
    {
        $order = $this->makeOrder(['total' => 800, 'held_amount' => 1000, 'captured_amount' => 800]);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembled);

        $notification = $this->lastNotification($order);
        $this->assertSame('Сумма заказа изменилась', $notification->title);
        $this->assertSame(
            "Заказ №{$order->id} собран. Итого {$this->rub('800')} вместо {$this->rub('1 000')}. "
                . "Разница {$this->rub('200')} вернётся на карту в течение 3 рабочих дней.",
            $notification->body,
        );
    }

    public function test_increased_amount_tells_extra_was_charged(): void
    {
        $order = $this->makeOrder(['total' => 1200, 'held_amount' => 1000, 'captured_amount' => 1200]);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembled);

        $notification = $this->lastNotification($order);
        $this->assertSame('Сумма заказа изменилась', $notification->title);
        $this->assertSame(
            "Заказ №{$order->id} собран. Итого {$this->rub('1 200')} вместо {$this->rub('1 000')}. "
                . "С карты дополнительно списано {$this->rub('200')}.",
            $notification->body,
        );
    }

    public function test_kopecks_are_shown_only_when_present(): void
    {
        $order = $this->makeOrder(['held_amount' => 2657.60, 'captured_amount' => 2415.3]);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembled);

        $this->assertStringContainsString(
            "Итого {$this->rub('2 415,30')} вместо {$this->rub('2 657,60')}. Разница {$this->rub('242,30')}",
            $this->lastNotification($order)->body,
        );
    }

    public function test_unchanged_amount_keeps_regular_text(): void
    {
        $order = $this->makeOrder();

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembled);

        $this->assertSame('Заказ собран', $this->lastNotification($order)->title);
    }

    public function test_cash_order_keeps_regular_text(): void
    {
        $order = $this->makeOrder([
            'payment_type' => PaymentType::Cash,
            'held_amount' => null,
            'captured_amount' => null,
        ]);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembled);

        $this->assertSame('Заказ собран', $this->lastNotification($order)->title);
    }

    public function test_without_confirmed_hold_keeps_regular_text(): void
    {
        // Потерянный вебхук холда: вся сумма списана доплатой, сравнивать не с чем.
        $order = $this->makeOrder(['held_amount' => null, 'captured_amount' => 800]);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembled);

        $this->assertSame('Заказ собран', $this->lastNotification($order)->title);
    }

    public function test_not_captured_yet_keeps_regular_text(): void
    {
        $order = $this->makeOrder(['captured_amount' => null]);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembled);

        $this->assertSame('Заказ собран', $this->lastNotification($order)->title);
    }

    public function test_other_statuses_are_not_affected(): void
    {
        $order = $this->makeOrder(['held_amount' => 1000, 'captured_amount' => 800]);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Delivered);

        $this->assertSame('Заказ доставлен', $this->lastNotification($order)->title);
    }

    public function test_pickup_keeps_wait_time_after_amount_change(): void
    {
        $store = Store::factory()->create([
            'pickup_auto_cancel_enabled' => true,
            'pickup_wait_regular_hours' => 3,
            'pickup_grace_period_hours' => 1,
        ]);
        $order = $this->makeOrder([
            'store_id' => $store->id,
            'shipping_method' => ShippingMethod::Pickup,
            'held_amount' => 1000,
            'captured_amount' => 800,
        ]);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembled);

        $body = $this->lastNotification($order)->body;
        $this->assertStringStartsWith("Заказ №{$order->id} собран. Итого {$this->rub('800')}", $body);
        $this->assertStringEndsWith('рабочих дней. Заберите в течение 4 ч.', $body);
    }

    /**
     * Старый путь (модуль collector_payment выключен): capture делает сам
     * OrderEventTransformer, и уведомление обязано уйти уже после него —
     * иначе job прочитал бы заказ без captured_amount.
     */
    public function test_event_capture_flow_sends_notification_after_capture(): void
    {
        $gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        $user = User::factory()->create(['orders_enabled' => true, 'push_enabled' => true]);
        $store = Store::factory()->create();
        $card = PaymentCard::create([
            'user_id' => $user->id,
            'payment_method_id' => 'pm_test_card',
            'card_type' => 'MasterCard',
            'last_four' => '4444',
            'expiry_month' => 12,
            'expiry_year' => 2030,
            'is_default' => true,
        ]);
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Assembling,
            'shipping_method' => ShippingMethod::Courier,
            'payment_type' => PaymentType::Card,
            'total' => 500,
            'held_amount' => 500,
            'captured_amount' => null,
            'payment_card_id' => $card->id,
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_hold_1',
        ]);

        // Путь обновления EventConsumer: beforeUpdate → save → afterUpdate.
        // Capture идёт в afterUpdate (после синхронизации позиций), и «Собран»
        // обязан уйти уже после него.
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'status' => 'assembled',
            'grand_total' => 400.0,
        ];
        $transformer = new OrderEventTransformer;
        $transformed = $transformer->beforeUpdate($order, $eventData, ['status' => OrderStatus::Assembled]);
        $order->withoutEvents(fn () => $order->forceFill($transformed)->save());
        $transformer->afterUpdate($order->fresh(), $eventData);

        $this->assertCount(1, $gateway->callsFor('capture'));
        $this->assertSame(
            "Заказ №{$order->id} собран. Итого {$this->rub('400')} вместо {$this->rub('500')}. "
                . "Разница {$this->rub('100')} вернётся на карту в течение 3 рабочих дней.",
            $this->lastNotification($order)->body,
        );
    }
}
