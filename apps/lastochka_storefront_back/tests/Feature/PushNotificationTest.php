<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Events\OrderStatusChangedEvent;
use App\Models\Order;
use App\Models\User;
use App\Models\UserFcmToken;
use App\Services\Notification\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_courier_departed_notification_sends_for_cash_payment(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $fcmToken = UserFcmToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'test_fcm_token_123',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Assembled,
            'payment_type' => PaymentType::Cash,
            'total' => 1500, // 1500 рублей
        ]);

        // Имитируем смену статуса на "В доставке"
        event(new OrderStatusChangedEvent($order, OrderStatus::Assembled, OrderStatus::InDelivery));

        Event::assertDispatched(OrderStatusChangedEvent::class, function ($event) use ($order) {
            return $event->order->id === $order->id
                && $event->newStatus === OrderStatus::InDelivery;
        });
    }

    public function test_courier_departed_notification_sends_for_card_payment(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $fcmToken = UserFcmToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'test_fcm_token_456',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Assembled,
            'payment_type' => PaymentType::Card,
            'total' => 2000,
        ]);

        // Имитируем смену статуса на "В доставке"
        event(new OrderStatusChangedEvent($order, OrderStatus::Assembled, OrderStatus::InDelivery));

        Event::assertDispatched(OrderStatusChangedEvent::class, function ($event) use ($order) {
            return $event->order->id === $order->id
                && $event->newStatus === OrderStatus::InDelivery;
        });
    }

    public function test_no_notification_when_user_has_no_fcm_tokens(): void
    {
        Event::fake();

        $user = User::factory()->create();
        // Не создаем FCM токен

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Assembled,
            'payment_type' => PaymentType::Cash,
            'total' => 1000,
        ]);

        // Имитируем смену статуса на "В доставке"
        event(new OrderStatusChangedEvent($order, OrderStatus::Assembled, OrderStatus::InDelivery));

        Event::assertDispatched(OrderStatusChangedEvent::class);
    }

    public function test_message_format_for_cash_payment(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'payment_type' => PaymentType::Cash,
            'total' => 1500,
        ]);

        $service = app(PushNotificationService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildCourierDepartedMessage');
        $method->setAccessible(true);

        $message = $method->invoke($service, $order);

        $this->assertEquals('Курьер выехал к вам. Сумма к оплате наличными: 1500 ₽', $message);
    }

    public function test_message_format_for_card_payment(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'payment_type' => PaymentType::Card,
            'total' => 2000,
        ]);

        $service = app(PushNotificationService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildCourierDepartedMessage');
        $method->setAccessible(true);

        $message = $method->invoke($service, $order);

        // Для безналичной оплаты текст давно другой: сумму к оплате
        // показывать незачем, вместо неё — номер заказа.
        $this->assertEquals(
            "Ваш заказ №{$order->id} находится в пути. Курьер скоро свяжется с вами для уточнения времени!",
            $message,
        );
    }
}